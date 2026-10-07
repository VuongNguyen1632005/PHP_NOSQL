<?php

declare(strict_types=1);

namespace App\Orders;

use App\Checkout\CheckoutRepository;
use App\Core\Response;
use App\Core\StreamedResponse;
use App\Infrastructure\CouchDB\CouchDbClient;
use RuntimeException;

final class OrderRealtimeController
{
    private readonly int $changeWaitMs;

    public function __construct(
        private readonly CheckoutRepository $orders,
        private readonly CouchDbClient $client,
        private readonly string $database,
        int $changeWaitMs = 8000,
    ) {
        $this->changeWaitMs = max(250, min(8000, $changeWaitMs));
    }

    /** @param array<string, string> $params */
    public function customerEvents(array $params): Response|StreamedResponse
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (!is_array($user) || ($user['type'] ?? null) !== 'customer' || (string) ($user['legacy_id'] ?? '') === '') {
            return $this->notFound();
        }

        $customerId = (string) $user['legacy_id'];
        return $this->subscribe(
            (string) ($params['id'] ?? ''),
            static fn (array $order): bool => (string) ($order['customer']['customer_id'] ?? '') === $customerId
                && (!isset($order['meta']['checkout']['phase']) || $order['meta']['checkout']['phase'] === 'completed'),
        );
    }

    /** @param array<string, string> $params */
    public function guestEvents(array $params): Response|StreamedResponse
    {
        if (is_array($_SESSION['auth_user'] ?? null)) {
            return $this->notFound();
        }
        $id = (string) ($params['id'] ?? '');
        $ownedIds = is_array($_SESSION['_guest_order_ids'] ?? null) ? $_SESSION['_guest_order_ids'] : [];
        if ($id === '' || strlen($id) > 240 || !in_array($id, $ownedIds, true)) {
            return $this->notFound();
        }

        return $this->subscribe($id, static fn (array $order): bool =>
            ($order['type'] ?? null) === 'order'
            && ($order['guest_order'] ?? false) === true
            && ($order['customer'] ?? null) === null
            && (!isset($order['meta']['checkout']['phase']) || $order['meta']['checkout']['phase'] === 'completed')
        );
    }

    /** @param callable(array<string, mixed>): bool $owns */
    private function subscribe(string $id, callable $owns): Response|StreamedResponse
    {
        if ($id === '' || strlen($id) > 240) {
            return $this->notFound();
        }
        $order = $this->orders->get($id);
        if ($order === null || ($order['type'] ?? null) !== 'order' || !$owns($order)) {
            return $this->notFound();
        }

        $lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? '';
        $sequence = is_string($lastEventId) && strlen($lastEventId) <= 512
            && !str_contains($lastEventId, "\r") && !str_contains($lastEventId, "\n")
            ? $lastEventId
            : '';
        if ($sequence === '') {
            $sequence = $this->currentSequence($id);
        }

        // Read after the checkpoint so a concurrent update is either in this snapshot or in _changes.
        $order = $this->orders->get($id);
        if ($order === null || ($order['type'] ?? null) !== 'order' || !$owns($order)) {
            return $this->notFound();
        }
        $initialPayload = $this->payload($order);
        session_write_close();

        return new StreamedResponse(
            function () use ($id, $sequence, $initialPayload, $owns): void {
                @ini_set('zlib.output_compression', '0');
                @ini_set('output_buffering', '0');
                if (function_exists('apache_setenv')) {
                    @apache_setenv('no-gzip', '1');
                }
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                @ini_set('implicit_flush', '1');
                ob_implicit_flush(true);

                echo "retry: 5000\n\n";
                $this->emit('order_updated', $sequence, $initialPayload);
                flush();
                if (connection_aborted()) {
                    return;
                }

                try {
                    $query = http_build_query([
                        'filter' => '_doc_ids',
                        'since' => $sequence,
                        'feed' => 'longpoll',
                        'timeout' => $this->changeWaitMs,
                    ], '', '&', PHP_QUERY_RFC3986);
                    $changes = $this->client->request(
                        'POST',
                        rawurlencode($this->database) . '/_changes?' . $query,
                        ['doc_ids' => [$id]],
                    );
                    if ($changes->statusCode === 400) {
                        $resetSequence = $this->currentSequence($id);
                        $fresh = $this->orders->get($id);
                        if ($fresh !== null && ($fresh['type'] ?? null) === 'order' && $owns($fresh)) {
                            $this->emit('order_updated', $resetSequence, $this->payload($fresh));
                        } else {
                            $this->emit('reconnecting', '', ['retry' => true]);
                        }
                        return;
                    }
                    if ($changes->statusCode < 200 || $changes->statusCode >= 300) {
                        throw new RuntimeException('CouchDB changes request returned ' . $changes->statusCode . '.');
                    }

                    $changeSet = $changes->json();
                    $nextSequence = $this->safeSequence($changeSet['last_seq'] ?? $sequence);
                    $results = is_array($changeSet['results'] ?? null) ? $changeSet['results'] : [];
                    if ($results !== []) {
                        $fresh = $this->orders->get($id);
                        if ($fresh !== null && ($fresh['type'] ?? null) === 'order' && $owns($fresh)) {
                            $payload = $this->payload($fresh);
                            if ($this->fingerprint($payload) !== $this->fingerprint($initialPayload)) {
                                $this->emit('order_updated', $nextSequence, $payload);
                                return;
                            }
                        }
                    }
                    $this->emit('heartbeat', $nextSequence, ['alive' => true]);
                } catch (\Throwable $exception) {
                    error_log('Order SSE change check failed: ' . $exception->getMessage());
                    $this->emit('reconnecting', '', ['retry' => true]);
                }
                flush();
            },
            200,
            'text/event-stream; charset=utf-8',
            ['X-Accel-Buffering' => 'no'],
        );
    }

    private function currentSequence(string $id): string
    {
        $query = http_build_query([
            'filter' => '_doc_ids',
            'since' => 'now',
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->client->request(
            'POST',
            rawurlencode($this->database) . '/_changes?' . $query,
            ['doc_ids' => [$id]],
        );
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB changes checkpoint failed (' . $response->statusCode . ').');
        }
        return $this->safeSequence($response->json()['last_seq'] ?? 'now');
    }

    /** @param array<string, mixed> $order
     *  @return array<string, mixed>
     */
    private function payload(array $order): array
    {
        $payment = is_array($order['payment'] ?? null) ? $order['payment'] : [];
        $paymentStatus = !empty($payment['paid']) || ($payment['status'] ?? null) === 'paid'
            ? 'paid'
            : (is_string($payment['status'] ?? null) ? $payment['status'] : 'unpaid');
        $rawDelivery = is_array($order['delivery_tracking'] ?? null) ? $order['delivery_tracking'] : null;
        $legacyShipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
        $trackingCode = (string) ($rawDelivery['tracking_code'] ?? $legacyShipping['tracking_code'] ?? '');
        $delivery = null;
        if ($rawDelivery !== null || $trackingCode !== '' || !empty($legacyShipping['estimated_delivery_date'])) {
            $history = [];
            foreach (($rawDelivery['history'] ?? []) as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $entry = [];
                foreach (['status', 'at', 'note'] as $field) {
                    if (is_string($event[$field] ?? null)) {
                        $entry[$field] = $event[$field];
                    }
                }
                $history[] = $entry;
            }
            $delivery = [
                'tracking_code' => $trackingCode,
                'status' => is_string($rawDelivery['status'] ?? null) ? $rawDelivery['status'] : null,
                'estimated_delivery_date' => is_string($rawDelivery['estimated_delivery_date'] ?? $legacyShipping['estimated_delivery_date'] ?? null)
                    ? (string) ($rawDelivery['estimated_delivery_date'] ?? $legacyShipping['estimated_delivery_date'])
                    : null,
                'delivered_at' => is_string($rawDelivery['delivered_at'] ?? null) ? $rawDelivery['delivered_at'] : null,
                'history' => $history,
            ];
        }

        $statusHistory = [];
        foreach (($order['status_history'] ?? []) as $event) {
            if (is_array($event) && is_string($event['status'] ?? null)) {
                $statusHistory[] = [
                    'status' => $event['status'],
                    'at' => is_string($event['at'] ?? null) ? $event['at'] : '',
                ];
            }
        }

        return [
            'order_id' => (string) ($order['_id'] ?? ''),
            'status' => (string) ($order['status'] ?? 'pending'),
            'payment_status' => $paymentStatus,
            'delivery' => $delivery,
            'status_history' => $statusHistory,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function emit(string $event, string $sequence, array $payload): void
    {
        if ($sequence !== '' && !str_contains($sequence, "\r") && !str_contains($sequence, "\n") && !str_contains($sequence, "\0")) {
            echo 'id: ' . $sequence . "\n";
        }
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n";
    }

    private function safeSequence(mixed $sequence): string
    {
        if (!is_string($sequence) && !is_int($sequence)) {
            return 'now';
        }
        $value = (string) $sequence;
        if (strlen($value) > 512 || str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            return 'now';
        }
        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function notFound(): Response
    {
        return new Response('', 404, 'text/event-stream; charset=utf-8', ['X-Accel-Buffering' => 'no']);
    }
}
