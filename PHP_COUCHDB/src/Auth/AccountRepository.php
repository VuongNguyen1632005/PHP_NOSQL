<?php

declare(strict_types=1);

namespace App\Auth;

use App\Infrastructure\CouchDB\CouchDbClient;
use RuntimeException;

final class AccountRepository
{
    public function __construct(private readonly CouchDbClient $client, private readonly string $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function byUsername(string $type, string $username): array
    {
        $response = $this->client->request('POST', rawurlencode($this->database) . '/_find', [
            'selector' => ['type' => $type, 'auth.username' => $username],
            'use_index' => ['_design/catalog_indexes', 'account_usernames'],
            'limit' => 10,
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB account lookup failed (' . $response->statusCode . ').');
        }
        $result = $response->json();
        $documents = $result['docs'] ?? null;
        if (!is_array($documents)) {
            throw new RuntimeException('CouchDB account lookup returned no docs list.');
        }
        return array_values(array_filter($documents, 'is_array'));
    }

    /** @return array<string, mixed>|null */
    public function byDocumentId(string $documentId): ?array
    {
        if (!str_starts_with($documentId, 'customer:')) {
            return null;
        }
        $response = $this->client->request('GET', rawurlencode($this->database) . '/' . rawurlencode($documentId));
        if ($response->statusCode === 404) {
            return null;
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB account lookup failed (' . $response->statusCode . ').');
        }
        $account = $response->json();
        return is_array($account) && ($account['type'] ?? null) === 'customer' ? $account : null;
    }

    /** @return array<string, mixed>|null */
    public function byTokenSubject(string $documentId): ?array
    {
        if (preg_match('/^(customer|staff):[A-Za-z0-9:_-]{1,220}$/', $documentId, $matches) !== 1) {
            return null;
        }
        $expectedType = $matches[1];
        $response = $this->client->request('GET', rawurlencode($this->database) . '/' . rawurlencode($documentId));
        if ($response->statusCode === 404) return null;
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB account lookup failed (' . $response->statusCode . ').');
        }
        $account = $response->json();
        return is_array($account) && ($account['type'] ?? null) === $expectedType ? $account : null;
    }

    /** @param array<string, mixed> $account */
    public function create(array $account): void
    {
        $response = $this->client->request(
            'PUT',
            rawurlencode($this->database) . '/' . rawurlencode((string) $account['_id']),
            $account,
        );
        if ($response->statusCode === 409) {
            throw new AuthException('Email này đã được đăng ký.');
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB could not create the customer (' . $response->statusCode . ').');
        }
    }

    /** @param array<string, mixed> $account */
    public function update(array $account): void
    {
        $response = $this->client->request(
            'PUT',
            rawurlencode($this->database) . '/' . rawurlencode((string) $account['_id']),
            $account,
        );
        if ($response->statusCode === 409) {
            throw new AuthException('Tài khoản vừa được cập nhật ở nơi khác. Hãy tải lại trang và thử lại.');
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB could not update the account (' . $response->statusCode . ').');
        }
    }
}
