<?php
declare(strict_types=1);

namespace App\Reviews;

use App\Core\Csrf;
use App\Core\Response;

final class ReviewController
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    /** @param array<string, string> $params */
    public function submit(array $params): Response
    {
        $productId = (string) ($params['id'] ?? '');
        $location = '/products/' . rawurlencode($productId) . '#review-area';
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            return new Response('<h1>Yêu cầu không hợp lệ</h1><p>Hãy tải lại trang sản phẩm rồi thử lại.</p>', 403);
        }
        try {
            $review = $this->reviews->submit(
                $productId,
                (string) ($_POST['reviewer_name'] ?? ''),
                $_POST['rating'] ?? null,
                (string) ($_POST['content'] ?? ''),
                $_SESSION['auth_user'] ?? null,
                session_id(),
            );
            $_SESSION['review_flash'] = ['type' => 'success', 'message' => 'Cảm ơn bạn đã gửi đánh giá ' . (string) $review['legacy_id'] . '.'];
        } catch (ReviewException $exception) {
            $_SESSION['review_flash'] = ['type' => 'warning', 'message' => $exception->getMessage()];
        }
        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
    }
}
