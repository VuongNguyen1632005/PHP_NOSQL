<?php
declare(strict_types=1);

namespace App\Reviews;

use App\Infrastructure\CouchDB\CouchDbClient;
use RuntimeException;

final class ReviewRepository
{
    public function __construct(private readonly CouchDbClient $client, private readonly string $database)
    {
    }

    /** @param array<string, mixed> $review */
    public function create(array $review): void
    {
        $response = $this->client->request('PUT', rawurlencode($this->database) . '/' . rawurlencode((string) $review['_id']), $review);
        if ($response->statusCode === 409) throw new ReviewException('Tài khoản/phiên này đã gửi đánh giá cho sản phẩm.');
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('CouchDB could not save the review (' . $response->statusCode . ').');
    }

    /** @return list<array<string, mixed>> */
    public function allForAdmin(): array
    {
        $response = $this->client->request('POST', rawurlencode($this->database) . '/_find', [
            'selector' => ['type' => 'review'],
            'use_index' => ['_design/catalog_indexes', 'admin_reviews'],
            'limit' => 2000,
            'fields' => ['_id', '_rev', 'type', 'legacy_id', 'product_id', 'customer_id', 'reviewer_name', 'rating', 'content', 'active', 'created_at', 'admin_response', 'meta'],
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB could not load admin reviews (' . $response->statusCode . ').');
        }
        $docs = $response->json()['docs'] ?? null;
        if (!is_array($docs)) throw new RuntimeException('CouchDB review list is invalid.');
        $reviews = array_values(array_filter($docs, 'is_array'));
        usort($reviews, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $reviews;
    }

    /** @return array<string, mixed> */
    public function setActive(string $id, bool $active, string $staffId): array
    {
        if ($id === '' || strlen($id) > 240) throw new RuntimeException('Mã đánh giá không hợp lệ.');
        $response = $this->client->request('GET', rawurlencode($this->database) . '/' . rawurlencode($id));
        if ($response->statusCode === 404) throw new RuntimeException('Không tìm thấy đánh giá.');
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('CouchDB could not load review (' . $response->statusCode . ').');
        $review = $response->json();
        if (($review['type'] ?? null) !== 'review') throw new RuntimeException('Không tìm thấy đánh giá.');
        if (($review['active'] ?? false) === $active) return $review;
        $review['active'] = $active;
        $review['meta'] = array_merge(is_array($review['meta'] ?? null) ? $review['meta'] : [], [
            ($active ? 'moderated_restored_at' : 'moderated_hidden_at') => gmdate('c'),
            ($active ? 'moderated_restored_by' : 'moderated_hidden_by') => $staffId,
        ]);
        $saved = $this->client->request('PUT', rawurlencode($this->database) . '/' . rawurlencode($id), $review);
        if ($saved->statusCode === 409) throw new RuntimeException('Đánh giá vừa được thay đổi ở nơi khác. Hãy tải lại danh sách.');
        if ($saved->statusCode < 200 || $saved->statusCode >= 300) throw new RuntimeException('Không thể cập nhật đánh giá (HTTP ' . $saved->statusCode . ').');
        $review['_rev'] = $saved->json()['rev'] ?? $review['_rev'] ?? '';
        return $review;
    }

    /** @return array<string, mixed> */
    public function reply(string $id, string $content, string $staffId, string $staffName): array
    {
        $content = trim($content);
        $staffName = trim($staffName);
        if ($id === '' || strlen($id) > 240) throw new RuntimeException('Mã đánh giá không hợp lệ.');
        if ($content === '' || mb_strlen($content) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $content) === 1) {
            throw new RuntimeException('Phản hồi không được để trống và tối đa 2000 ký tự.');
        }
        if ($staffName === '' || mb_strlen($staffName) > 100) $staffName = $staffId !== '' ? $staffId : 'Nhân viên';

        $path = rawurlencode($this->database) . '/' . rawurlencode($id);
        $response = $this->client->request('GET', $path);
        if ($response->statusCode === 404) throw new RuntimeException('Không tìm thấy đánh giá.');
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('CouchDB could not load review (' . $response->statusCode . ').');
        $review = $response->json();
        if (($review['type'] ?? null) !== 'review') throw new RuntimeException('Không tìm thấy đánh giá.');

        $now = gmdate('c');
        $previous = $review['admin_response'] ?? null;
        $review['admin_response'] = ['content' => $content, 'staff_id' => $staffId, 'staff_name' => $staffName, 'updated_at' => $now];
        $review['meta'] = is_array($review['meta'] ?? null) ? $review['meta'] : [];
        if (is_array($previous) && is_string($previous['content'] ?? null)) {
            $review['meta']['admin_response_history'] = is_array($review['meta']['admin_response_history'] ?? null)
                ? $review['meta']['admin_response_history'] : [];
            $review['meta']['admin_response_history'][] = [
                'content' => $previous['content'],
                'staff_id' => (string) ($previous['staff_id'] ?? ''),
                'staff_name' => (string) ($previous['staff_name'] ?? ''),
                'updated_at' => (string) ($previous['updated_at'] ?? ''),
            ];
        }
        $saved = $this->client->request('PUT', $path, $review);
        if ($saved->statusCode === 409) throw new RuntimeException('Đánh giá vừa được thay đổi ở nơi khác. Hãy tải lại danh sách.');
        if ($saved->statusCode < 200 || $saved->statusCode >= 300) throw new RuntimeException('Không thể lưu phản hồi (HTTP ' . $saved->statusCode . ').');
        $review['_rev'] = $saved->json()['rev'] ?? $review['_rev'] ?? '';
        return $review;
    }
}
