<?php

declare(strict_types=1);

namespace App\Cart;

use App\Infrastructure\CouchDB\CouchDbClient;
use App\Infrastructure\CouchDB\CouchDbResponse;
use RuntimeException;

final class MemberCartRepository
{
    public function __construct(private readonly CouchDbClient $client, private readonly string $database)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $customerId): ?array
    {
        $response = $this->client->request('GET', $this->path($customerId));
        if ($response->statusCode === 404) {
            return null;
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB could not load the member cart (' . $response->statusCode . ').');
        }
        return $response->json();
    }

    /** @param array<string, mixed> $document */
    public function put(string $customerId, array $document): CouchDbResponse
    {
        return $this->client->request('PUT', $this->path($customerId), $document);
    }

    private function path(string $customerId): string
    {
        return rawurlencode($this->database) . '/' . rawurlencode('cart:' . $customerId);
    }
}
