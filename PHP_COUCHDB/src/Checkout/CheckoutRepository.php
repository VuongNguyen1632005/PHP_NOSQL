<?php
declare(strict_types=1);

namespace App\Checkout;

use App\Infrastructure\CouchDB\CouchDbClient;
use App\Infrastructure\CouchDB\CouchDbResponse;
use RuntimeException;

final class CheckoutRepository
{
    private const ORDER_PAGE_SIZE = 25;

    public function __construct(private readonly CouchDbClient $client, private readonly string $database)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $response = $this->client->request('GET', $this->path($id));
        if ($response->statusCode === 404) return null;
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('CouchDB document read failed (' . $response->statusCode . ').');
        return $response->json();
    }

    public function trackingCodeExists(string $trackingCode, string $exceptOrderId = ''): bool
    {
        $response = $this->client->request('POST', rawurlencode($this->database) . '/_find', [
            'selector' => ['type' => 'order', 'delivery_tracking.tracking_code' => $trackingCode],
            'use_index' => ['_design/catalog_indexes', 'delivery_tracking_code'],
            'limit' => 2,
            'fields' => ['_id'],
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB tracking-code lookup failed (' . $response->statusCode . ').');
        }
        foreach ($response->json()['docs'] ?? [] as $document) {
            if (is_array($document) && (string) ($document['_id'] ?? '') !== $exceptOrderId) return true;
        }
        return false;
    }

    /** @param array<string, mixed> $document */
    public function put(array $document): CouchDbResponse
    {
        return $this->client->request('PUT', $this->path((string) $document['_id']), $document);
    }

    /** @return list<array<string, mixed>> */
    public function activeDocuments(string $type): array
    {
        $response = $this->client->request('POST', rawurlencode($this->database) . '/_find', [
            'selector' => ['type' => $type, 'active' => true],
            'limit' => 100,
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('CouchDB reference query failed (' . $response->statusCode . ').');
        $docs = $response->json()['docs'] ?? null;
        if (!is_array($docs)) throw new RuntimeException('CouchDB returned no reference document list.');
        return array_values(array_filter($docs, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    public function ordersForCustomer(string $customerId): array
    {
        if ($customerId === '') return [];
        $orders = [];
        $bookmark = null;
        do {
            $page = $this->ordersForCustomerPage($customerId, $bookmark);
            array_push($orders, ...$page['orders']);
            $bookmark = $page['next_bookmark'];
        } while ($bookmark !== null);
        return $orders;
    }

    /** @return array{orders:list<array<string,mixed>>,next_bookmark:?string} */
    public function ordersForCustomerPage(string $customerId, ?string $bookmark = null): array
    {
        if ($customerId === '') return ['orders' => [], 'next_bookmark' => null];
        return $this->fetchOrderPage(
            ['type' => 'order', 'customer.customer_id' => $customerId],
            'customer_orders',
            $bookmark,
            static fn (array $doc): bool => (string) ($doc['customer']['customer_id'] ?? '') === $customerId,
            'customer',
        );
    }

    /** @return array{orders:list<array<string,mixed>>,next_bookmark:?string} */
    public function ordersForStaffPage(?string $status = null, ?string $bookmark = null): array
    {
        $selector = ['type' => 'order'];
        if (is_string($status) && $status !== '') $selector['status'] = $status;
        $index = is_string($status) && $status !== '' ? 'admin_orders' : 'admin_orders_by_date';
        return $this->fetchOrderPage(
            $selector,
            $index,
            $bookmark,
            static fn (array $doc): bool => !is_string($status) || $status === '' || ($doc['status'] ?? null) === $status,
            'staff',
        );
    }

    /** @return list<array<string, mixed>> */
    public function ordersForStaff(?string $status = null): array
    {
        $orders = iterator_to_array($this->iterateOrdersForStaff($status), false);
        usort($orders, static fn (array $left, array $right): int => strcmp((string) ($right['ordered_at'] ?? ''), (string) ($left['ordered_at'] ?? '')));
        return $orders;
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function iterateOrdersForStaff(?string $status = null): \Generator
    {
        $selector = ['type' => 'order'];
        if (is_string($status) && $status !== '') $selector['status'] = $status;
        $bookmark = null;
        do {
            $query = [
                'selector' => $selector,
                'limit' => 500,
                'use_index' => ['_design/catalog_indexes', 'admin_orders'],
            ];
            if (is_string($bookmark) && $bookmark !== '') $query['bookmark'] = $bookmark;
            $response = $this->client->request('POST', rawurlencode($this->database) . '/_find', $query);
            if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('CouchDB staff order query failed (' . $response->statusCode . ').');
            $result = $response->json();
            $docs = $result['docs'] ?? null;
            if (!is_array($docs)) throw new RuntimeException('CouchDB returned no staff order list.');
            foreach ($docs as $doc) {
                if (is_array($doc)
                    && ($doc['type'] ?? null) === 'order'
                    && (!is_string($status) || $status === '' || ($doc['status'] ?? null) === $status)
                    && (!isset($doc['meta']['checkout']['phase']) || $doc['meta']['checkout']['phase'] === 'completed')) {
                    yield $doc;
                }
            }
            $nextBookmark = $result['bookmark'] ?? null;
            if (count($docs) < 500 || !is_string($nextBookmark) || $nextBookmark === '' || $nextBookmark === $bookmark) {
                break;
            }
            $bookmark = $nextBookmark;
        } while (true);
    }

    /** @return \Generator<int, array<string, mixed>> Includes failed and in-progress checkout journals. */
    public function iterateOrderJournals(): \Generator
    {
        $bookmark = null;
        do {
            $query = [
                'selector' => ['type' => 'order'],
                'limit' => 500,
                'use_index' => ['_design/catalog_indexes', 'admin_orders'],
            ];
            if (is_string($bookmark) && $bookmark !== '') $query['bookmark'] = $bookmark;
            $response = $this->client->request('POST', rawurlencode($this->database) . '/_find', $query);
            if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('CouchDB order journal scan failed (' . $response->statusCode . ').');
            $result = $response->json();
            $docs = $result['docs'] ?? null;
            if (!is_array($docs)) throw new RuntimeException('CouchDB returned no order journal list.');
            foreach ($docs as $doc) {
                if (is_array($doc) && ($doc['type'] ?? null) === 'order') yield $doc;
            }
            $nextBookmark = $result['bookmark'] ?? null;
            if (count($docs) < 500 || !is_string($nextBookmark) || $nextBookmark === '' || $nextBookmark === $bookmark) break;
            $bookmark = $nextBookmark;
        } while (true);
    }

    /** @param array<string, mixed> $selector
     *  @param callable(array<string, mixed>): bool $matchesScope
     *  @return array{orders:list<array<string,mixed>>,next_bookmark:?string}
     */
    private function fetchOrderPage(array $selector, string $index, ?string $bookmark, callable $matchesScope, string $scope): array
    {
        if ($bookmark !== null && (strlen($bookmark) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $bookmark) === 1)) {
            throw new RuntimeException('Order page cursor is invalid.');
        }
        $query = [
            'selector' => $selector,
            'limit' => self::ORDER_PAGE_SIZE,
            'sort' => [['ordered_at' => 'desc']],
            'use_index' => ['_design/catalog_indexes', $index],
        ];
        if (is_string($bookmark) && $bookmark !== '') $query['bookmark'] = $bookmark;
        $response = $this->client->request('POST', rawurlencode($this->database) . '/_find', $query);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB ' . $scope . ' order page query failed (' . $response->statusCode . ').');
        }
        $result = $response->json();
        $docs = $result['docs'] ?? null;
        if (!is_array($docs)) throw new RuntimeException('CouchDB returned no order page list.');
        $orders = array_values(array_filter($docs, static fn (mixed $doc): bool => is_array($doc)
            && ($doc['type'] ?? null) === 'order'
            && $matchesScope($doc)
            && (!isset($doc['meta']['checkout']['phase']) || $doc['meta']['checkout']['phase'] === 'completed')));
        $next = $result['bookmark'] ?? null;
        if (count($docs) < self::ORDER_PAGE_SIZE || !is_string($next) || $next === '' || $next === $bookmark) $next = null;
        return ['orders' => $orders, 'next_bookmark' => $next];
    }

    private function path(string $id): string
    {
        return rawurlencode($this->database) . '/' . rawurlencode($id);
    }
}
