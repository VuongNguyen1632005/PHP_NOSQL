<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;
use RuntimeException;

final class ReviewAdminController
{
    public function __construct(private readonly ReviewRepository $reviews)
    {
    }

    public function index(): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        $flash = $_SESSION['review_admin_flash'] ?? null;
        unset($_SESSION['review_admin_flash']);
        return new Response(View::render('admin/reviews/index', [
            'title' => 'Kiểm duyệt đánh giá', 'reviews' => $this->reviews->allForAdmin(),
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /** @param array<string, string> $params */
    public function setVisibility(array $params): Response
    {
        $guard = $this->managerGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? '';
        $location = '/admin/reviews';
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['review_admin_flash'] = ['type' => 'danger', 'message' => 'Phiên biểu mẫu hết hạn. Hãy tải lại danh sách.'];
            return $this->redirect($location);
        }
        $activeValue = $_POST['active'] ?? null;
        if (!is_string($activeValue) || !in_array($activeValue, ['0', '1'], true)) {
            $_SESSION['review_admin_flash'] = ['type' => 'danger', 'message' => 'Thao tác kiểm duyệt không hợp lệ.'];
            return $this->redirect($location);
        }
        $active = $activeValue === '1';
        try {
            $this->reviews->setActive($id, $active, (string) ($_SESSION['auth_user']['legacy_id'] ?? 'staff'));
            $_SESSION['review_admin_flash'] = ['type' => 'success', 'message' => $active ? 'Đã khôi phục đánh giá.' : 'Đã ẩn đánh giá khỏi cửa hàng.'];
        } catch (RuntimeException $exception) {
            $_SESSION['review_admin_flash'] = ['type' => 'danger', 'message' => $exception->getMessage()];
        }
        return $this->redirect($location);
    }

    /** @param array<string, string> $params */
    public function reply(array $params): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['review_admin_flash'] = ['type' => 'danger', 'message' => 'Phiên biểu mẫu hết hạn. Hãy tải lại danh sách.'];
            return $this->redirect('/admin/reviews');
        }
        $user = $_SESSION['auth_user'] ?? [];
        try {
            $this->reviews->reply(
                (string) ($params['id'] ?? ''),
                (string) ($_POST['content'] ?? ''),
                (string) ($user['legacy_id'] ?? ''),
                (string) ($user['name'] ?? $user['username'] ?? ''),
            );
            $_SESSION['review_admin_flash'] = ['type' => 'success', 'message' => 'Đã lưu phản hồi và hiển thị dưới đánh giá sản phẩm.'];
        } catch (RuntimeException $exception) {
            $_SESSION['review_admin_flash'] = ['type' => 'danger', 'message' => $exception->getMessage()];
        }
        return $this->redirect('/admin/reviews');
    }

    private function staffGuard(): ?Response
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (!is_array($user)) return $this->redirect('/login');
        if (($user['type'] ?? null) !== 'staff' || !in_array(($user['role'] ?? null), ['manager', 'staff'], true)) {
            return new Response('<h1>403 — Không có quyền truy cập</h1><p><a href="/">Về trang chủ</a></p>', 403);
        }
        return null;
    }

    private function managerGuard(): ?Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        return (($_SESSION['auth_user']['role'] ?? null) === 'manager')
            ? null : new Response('<h1>403 — Chỉ quản lý được kiểm duyệt đánh giá</h1><p><a href="/admin/reviews">Quay lại danh sách</a></p>', 403);
    }

    private function redirect(string $location): Response
    {
        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
    }
}
