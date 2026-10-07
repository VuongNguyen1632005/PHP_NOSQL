<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;
use RuntimeException;

final class ProductAdminController
{
    public function __construct(private readonly ProductAdminService $products)
    {
    }

    public function index(): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        $flash = $_SESSION['product_flash'] ?? null;
        unset($_SESSION['product_flash']);
        return new Response(View::render('admin/products/index', [
            'title' => 'Quản lý sản phẩm', 'products' => $this->products->allProducts(),
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /** @param array<string, string> $params */
    public function form(array $params = []): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? '';
        $product = $id === '' ? null : $this->products->get($id);
        if ($id !== '' && $product === null) return new Response(View::render('errors/not-found', ['title' => 'Không tìm thấy sản phẩm']), 404);
        $flash = $_SESSION['product_flash'] ?? null;
        unset($_SESSION['product_flash']);
        return new Response(View::render('admin/products/form', [
            'title' => $product === null ? 'Thêm sản phẩm' : 'Sửa sản phẩm',
            'product' => $product, 'error' => is_array($flash) ? ($flash['error'] ?? null) : null,
            'oldInput' => is_array($flash) ? ($flash['input'] ?? []) : [],
        ]));
    }

    /** @param array<string, string> $params */
    public function save(array $params = []): Response
    {
        $guard = $this->managerGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? null;
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['product_flash'] = ['error' => 'Phiên biểu mẫu hết hạn. Hãy thử lưu lại.'];
            return $this->redirect($id === null ? '/admin/products/new' : '/admin/products/' . rawurlencode($id) . '/edit');
        }
        try {
            $user = $_SESSION['auth_user'] ?? [];
            $upload = isset($_FILES['image']) && is_array($_FILES['image']) ? $_FILES['image'] : null;
            $saved = $this->products->save($id, $_POST, (string) ($user['legacy_id'] ?? 'staff'), $upload);
            $_SESSION['product_flash'] = ['message' => 'Đã lưu sản phẩm ' . ($saved['legacy_id'] ?? '') . '.'];
            return $this->redirect('/admin/products');
        } catch (RuntimeException $exception) {
            $_SESSION['product_flash'] = ['error' => $exception->getMessage(), 'input' => $_POST];
            return $this->redirect($id === null ? '/admin/products/new' : '/admin/products/' . rawurlencode($id) . '/edit');
        }
    }

    /** @param array<string, string> $params */
    public function delete(array $params): Response
    {
        $guard = $this->managerGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? '';
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['product_flash'] = ['message' => 'Phiên biểu mẫu hết hạn.'];
            return $this->redirect('/admin/products');
        }
        try {
            $user = $_SESSION['auth_user'] ?? [];
            $this->products->softDelete($id, (string) ($user['legacy_id'] ?? 'staff'));
            $_SESSION['product_flash'] = ['message' => 'Đã ẩn sản phẩm ' . $id . '.'];
        } catch (RuntimeException $exception) {
            $_SESSION['product_flash'] = ['message' => $exception->getMessage()];
        }
        return $this->redirect('/admin/products');
    }

    /** @param array<string, string> $params */
    public function restore(array $params): Response
    {
        $guard = $this->managerGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? '';
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['product_flash'] = ['message' => 'Phiên biểu mẫu hết hạn.'];
            return $this->redirect('/admin/products');
        }
        try {
            $user = $_SESSION['auth_user'] ?? [];
            $this->products->restore($id, (string) ($user['legacy_id'] ?? 'staff'));
            $_SESSION['product_flash'] = ['message' => 'Đã khôi phục sản phẩm ' . $id . '.'];
        } catch (RuntimeException $exception) {
            $_SESSION['product_flash'] = ['message' => $exception->getMessage()];
        }
        return $this->redirect('/admin/products');
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
            ? null : new Response('<h1>403 — Chỉ quản lý được thay đổi sản phẩm</h1><p><a href="/admin/products">Quay lại danh sách</a></p>', 403);
    }

    private function redirect(string $location): Response
    {
        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
    }
}
