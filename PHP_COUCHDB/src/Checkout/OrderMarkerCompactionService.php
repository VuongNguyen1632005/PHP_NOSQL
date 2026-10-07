<?php
declare(strict_types=1);

namespace App\Checkout;

use App\Infrastructure\CouchDB\CouchDbClient;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class OrderMarkerCompactionService
{
    public function __construct(
        private readonly CheckoutRepository $orders,
        private readonly CouchDbClient $client,
        private readonly string $database,
    ) {
    }

    /** @return array{cutoff:string,eligible_orders:int,resources_examined:int,markers_found:int,markers_removed:int,documents_updated:int,dry_run:bool} */
    public function compact(DateTimeImmutable $cutoff, bool $apply = false): array
    {
        $resources = [];
        $eligibleOrders = 0;
        foreach ($this->orders->iterateOrderJournals() as $order) {
            if (!$this->isSafeTerminalOrder($order, $cutoff)) continue;
            ++$eligibleOrders;
            $orderId = (string) ($order['_id'] ?? '');
            $checkout = is_array($order['meta']['checkout'] ?? null) ? $order['meta']['checkout'] : [];
            $operationId = $checkout['operation_id'] ?? null;
            $productIds = array_keys(is_array($checkout['stock_plan'] ?? null) ? $checkout['stock_plan'] : []);
            if ($productIds === [] && ($order['status'] ?? null) === 'cancelled') {
                foreach ($order['items'] ?? [] as $item) {
                    if (is_array($item) && is_string($item['product_id'] ?? null)) $productIds[] = $item['product_id'];
                }
            }
            foreach (array_unique($productIds) as $productId) {
                if (!is_string($productId) || $productId === '' || strlen($productId) > 180) continue;
                $documentId = 'product:' . $productId;
                if (is_string($operationId) && preg_match('/^[a-f0-9]{64}$/', $operationId) === 1) {
                    $resources[$documentId]['checkout_reservations'][$operationId] = true;
                }
                if (($order['status'] ?? null) === 'cancelled' && $orderId !== '') {
                    $resources[$documentId]['order_cancellations'][$orderId] = true;
                }
            }

            $voucherCode = $checkout['voucher_code'] ?? ($order['discount']['code'] ?? null);
            if (is_string($voucherCode) && $voucherCode !== '' && strlen($voucherCode) <= 100) {
                $documentId = 'voucher:' . $voucherCode;
                if (is_string($operationId) && preg_match('/^[a-f0-9]{64}$/', $operationId) === 1) {
                    $resources[$documentId]['checkout_redemptions'][$operationId] = true;
                }
                if (($order['status'] ?? null) === 'cancelled' && $orderId !== '') {
                    $resources[$documentId]['order_cancellations'][$orderId] = true;
                }
            }
        }

        $summary = [
            'cutoff' => $cutoff->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'eligible_orders' => $eligibleOrders,
            'resources_examined' => 0,
            'markers_found' => 0,
            'markers_removed' => 0,
            'documents_updated' => 0,
            'dry_run' => !$apply,
        ];
        foreach ($resources as $documentId => $markerGroups) {
            ++$summary['resources_examined'];
            $result = $this->compactResource($documentId, $markerGroups, $cutoff, $apply);
            $summary['markers_found'] += $result['found'];
            $summary['markers_removed'] += $result['removed'];
            $summary['documents_updated'] += $result['updated'] ? 1 : 0;
        }
        return $summary;
    }

    /** @param array<string, mixed> $order */
    private function isSafeTerminalOrder(array $order, DateTimeImmutable $cutoff): bool
    {
        $status = (string) ($order['status'] ?? '');
        $checkout = $order['meta']['checkout'] ?? null;
        if (!is_array($checkout)) return false;
        $phase = (string) ($checkout['phase'] ?? '');
        $terminalAt = null;

        if ($phase === 'failed') {
            $terminalAt = $this->parseDate($checkout['updated_at'] ?? null);
        } elseif ($phase === 'completed' && in_array($status, ['shipping', 'delivered'], true)) {
            if (($order['meta']['order_transition']['phase'] ?? null) === 'cancelling') return false;
            $terminalAt = $this->latestStatusTime($order, $status);
        } elseif ($phase === 'completed' && $status === 'cancelled'
            && ($order['meta']['order_transition']['phase'] ?? null) === 'completed') {
            $terminalAt = $this->parseDate($order['meta']['order_transition']['completed_at'] ?? null);
        }

        return $terminalAt !== null && $terminalAt < $cutoff;
    }

    /** @param array<string, mixed> $order */
    private function latestStatusTime(array $order, string $status): ?DateTimeImmutable
    {
        $latest = null;
        foreach ($order['status_history'] ?? [] as $entry) {
            if (!is_array($entry) || ($entry['status'] ?? null) !== $status) continue;
            $at = $this->parseDate($entry['at'] ?? null);
            if ($at !== null && ($latest === null || $at > $latest)) $latest = $at;
        }
        return $latest;
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') return null;
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, array<string, true>> $markerGroups
     *  @return array{found:int,removed:int,updated:bool}
     */
    private function compactResource(string $documentId, array $markerGroups, DateTimeImmutable $cutoff, bool $apply): array
    {
        $path = rawurlencode($this->database) . '/' . rawurlencode($documentId);
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $response = $this->client->request('GET', $path);
            if ($response->statusCode === 404) return ['found' => 0, 'removed' => 0, 'updated' => false];
            if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not load marker resource (' . $response->statusCode . ').');
            $document = $response->json();
            if (!is_array($document) || !in_array($document['type'] ?? null, ['product', 'voucher'], true)) {
                return ['found' => 0, 'removed' => 0, 'updated' => false];
            }
            $found = 0;
            $updatedMeta = is_array($document['meta'] ?? null) ? $document['meta'] : [];
            foreach ($markerGroups as $field => $keys) {
                $current = is_array($updatedMeta[$field] ?? null) ? $updatedMeta[$field] : [];
                foreach ($keys as $key => $_) {
                    if (array_key_exists($key, $current)) {
                        ++$found;
                        unset($current[$key]);
                    }
                }
                if ($current === []) unset($updatedMeta[$field]);
                else $updatedMeta[$field] = $current;
            }
            if (!$apply || $found === 0) return ['found' => $found, 'removed' => 0, 'updated' => false];

            $document['meta'] = $updatedMeta;
            $audit = is_array($document['meta']['marker_compaction'] ?? null) ? $document['meta']['marker_compaction'] : [];
            $audit['runs'] = (int) ($audit['runs'] ?? 0) + 1;
            $audit['markers_removed'] = (int) ($audit['markers_removed'] ?? 0) + $found;
            $audit['last_run_at'] = gmdate('c');
            $audit['cutoff'] = $cutoff->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            $document['meta']['marker_compaction'] = $audit;
            $saved = $this->client->request('PUT', $path, $document);
            if ($saved->statusCode === 409) continue;
            if ($saved->statusCode < 200 || $saved->statusCode >= 300) throw new RuntimeException('Could not compact marker resource (' . $saved->statusCode . ').');
            return ['found' => $found, 'removed' => $found, 'updated' => true];
        }
        throw new RuntimeException('Marker resource changed repeatedly during compaction.');
    }
}
