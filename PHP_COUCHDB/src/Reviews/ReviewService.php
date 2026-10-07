<?php
declare(strict_types=1);

namespace App\Reviews;

use App\Catalog\ProductRepository;

final class ReviewService
{
    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly ProductRepository $products,
    ) {
    }

    /** @param array<string, mixed>|null $user
     *  @return array<string, mixed>
     */
    public function submit(string $productId, string $guestName, mixed $ratingInput, string $content, ?array $user, string $sessionId): array
    {
        $productId = trim($productId);
        $product = $this->products->productByLegacyId($productId);
        if ($product === null) throw new ReviewException('Sản phẩm không còn khả dụng.');
        $customerId = ($user['type'] ?? null) === 'customer' ? trim((string) ($user['legacy_id'] ?? '')) : '';
        $reviewerName = $customerId !== ''
            ? trim((string) (($user['name'] ?? '') !== '' ? $user['name'] : ($user['username'] ?? '')))
            : trim($guestName);
        $content = trim($content);
        $rating = filter_var($ratingInput, FILTER_VALIDATE_INT);
        if ($reviewerName === '' || mb_strlen($reviewerName) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $reviewerName) === 1) {
            throw new ReviewException('Vui lòng nhập tên hợp lệ (tối đa 100 ký tự).');
        }
        if ($rating === false || $rating < 1 || $rating > 5) throw new ReviewException('Số sao phải từ 1 đến 5.');
        if ($content === '' || mb_strlen($content) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $content) === 1) {
            throw new ReviewException('Nội dung đánh giá không được để trống và tối đa 2000 ký tự.');
        }
        if ($customerId === '' && $sessionId === '') throw new ReviewException('Phiên đánh giá không hợp lệ. Hãy tải lại trang.');

        $scope = $customerId !== '' ? 'customer:' . $customerId : 'guest:' . $sessionId;
        $submissionId = hash('sha256', $scope . ':' . $productId);
        $review = [
            '_id' => 'review:submission:' . $submissionId,
            'type' => 'review',
            'schema_version' => 2,
            'legacy_id' => 'RV' . strtoupper(substr($submissionId, 0, 24)),
            'product_id' => $productId,
            'customer_id' => $customerId !== '' ? $customerId : null,
            'reviewer_name' => $reviewerName,
            'rating' => $rating,
            'content' => $content,
            'active' => true,
            'created_at' => gmdate('c'),
            'meta' => ['source' => 'PHP review form'],
        ];
        $this->reviews->create($review);
        return $review;
    }
}
