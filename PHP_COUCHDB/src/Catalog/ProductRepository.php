<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Infrastructure\CouchDB\CouchDbClient;
use RuntimeException;

final class ProductRepository
{
    public function __construct(private readonly CouchDbClient $client, private readonly string $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function activeProducts(): array
    {
        return $this->find(['type' => 'product', 'active' => true], 'active_products');
    }

    /** @return list<array<string, mixed>> */
    public function activeReviews(): array
    {
        return $this->find(['type' => 'review', 'active' => true], 'active_reviews');
    }

    /** @return array<string, mixed>|null */
    public function productByLegacyId(string $legacyId): ?array
    {
        $response = $this->client->request('GET', rawurlencode($this->database) . '/' . rawurlencode('product:' . $legacyId));
        if ($response->statusCode === 404) {
            return null;
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB could not load product (' . $response->statusCode . ').');
        }
        $document = $response->json();
        return ($document['type'] ?? null) === 'product' && ($document['active'] ?? false) === true ? $document : null;
    }

    /** @param array<string, mixed> $selector
     *  @return list<array<string, mixed>>
     */
    private function find(array $selector, string $indexName): array
    {
        $response = $this->client->request('POST', rawurlencode($this->database) . '/_find', [
            'selector' => $selector,
            'use_index' => ['_design/catalog_indexes', $indexName],
            'limit' => 2000,
            'fields' => ['_id', 'type', 'legacy_id', 'name', 'description', 'category', 'variants', 'images', 'active', 'product_id', 'reviewer_name', 'rating', 'content', 'admin_response'],
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB catalog query failed (' . $response->statusCode . ').');
        }
        $result = $response->json();
        $documents = $result['docs'] ?? null;
        if (!is_array($documents)) {
            throw new RuntimeException('CouchDB catalog response is missing its docs list.');
        }
        return array_values(array_filter($documents, 'is_array'));
    }
}
