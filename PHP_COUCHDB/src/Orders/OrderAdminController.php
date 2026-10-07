<?php
declare(strict_types=1);

namespace App\Orders;

use App\Checkout\CheckoutRepository;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;

final class OrderAdminController
{
    public function __construct(
        private readonly CheckoutRepository $orders,
        private readonly OrderWorkflowService $workflow,
    ) {
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function index(array $params = [], array $query = []): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        $knownStatuses = ['pending', 'confirmed', 'packing', 'shipping', 'delivered', 'cancelled'];
        $status = (string) ($query['status'] ?? '');
        if (!in_array($status, $knownStatuses, true)) $status = '';
        $flash = $_SESSION['order_flash'] ?? null;
        unset($_SESSION['order_flash']);
        $cursor = $query['cursor'] ?? null;
        if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 4096) $cursor = null;
        $page = $this->orders->ordersForStaffPage($status === '' ? null : $status, $cursor);
        return new Response(View::render('admin/orders/index', [
            'title' => 'Quản lý đơn hàng',
            'orders' => $page['orders'],
            'status' => $status,
            'nextCursor' => $page['next_bookmark'],
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /** @param array<string, string> $params */
    public function detail(array $params): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? '';
        $order = $id === '' || strlen($id) > 240 ? null : $this->orders->get($id);
        if ($order === null || ($order['type'] ?? null) !== 'order'
            || (isset($order['meta']['checkout']['phase']) && $order['meta']['checkout']['phase'] !== 'completed')) {
            return new Response(View::render('errors/not-found', ['title' => 'Không tìm thấy đơn hàng']), 404);
        }
        $flash = $_SESSION['order_flash'] ?? null;
        unset($_SESSION['order_flash']);
        return new Response(View::render('admin/orders/detail', [
            'title' => 'Quản lý đơn hàng',
            'order' => $order,
            'transitions' => $this->workflow->availableTransitions($order),
            'deliveryTransitions' => $this->workflow->availableDeliveryTransitions($order),
            'error' => is_array($flash) ? ($flash['error'] ?? null) : null,
            'success' => is_array($flash) ? ($flash['success'] ?? null) : null,
        ]));
    }

    /** @param array<string, string> $params */
    public function updateStatus(array $params): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? '';
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['order_flash'] = ['error' => 'Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/admin/orders/' . rawurlencode($id)]);
        }
        try {
            $user = $_SESSION['auth_user'] ?? [];
            $updated = $this->workflow->transition($id, (string) ($_POST['status'] ?? ''), (string) ($user['legacy_id'] ?? ''));
            $_SESSION['order_flash'] = ['success' => 'Đã cập nhật trạng thái đơn hàng.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/admin/orders/' . rawurlencode((string) $updated['_id'])]);
        } catch (OrderWorkflowException $exception) {
            $_SESSION['order_flash'] = ['error' => $exception->getMessage()];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/admin/orders/' . rawurlencode($id)]);
        }
    }

    /** @param array<string, string> $params */
    public function updateDeliveryStatus(array $params): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? '';
        $location = '/admin/orders/' . rawurlencode($id);
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['order_flash'] = ['error' => 'Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
        }
        try {
            $user = $_SESSION['auth_user'] ?? [];
            $updated = $this->workflow->updateDeliveryStatus(
                $id,
                (string) ($_POST['delivery_status'] ?? ''),
                (string) ($user['legacy_id'] ?? ''),
                is_string($_POST['note'] ?? null) ? $_POST['note'] : '',
            );
            $_SESSION['order_flash'] = ['success' => 'Đã cập nhật tiến trình giao hàng.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/admin/orders/' . rawurlencode((string) $updated['_id'])]);
        } catch (OrderWorkflowException $exception) {
            $_SESSION['order_flash'] = ['error' => $exception->getMessage()];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
        }
    }

    /** @param array<string, string> $params */
    public function recordCodCollected(array $params): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? '';
        $location = '/admin/orders/' . rawurlencode($id);
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['order_flash'] = ['error' => 'Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
        }
        try {
            $user = $_SESSION['auth_user'] ?? [];
            $order = $this->workflow->recordCodCollected($id, (string) ($user['legacy_id'] ?? ''));
            $_SESSION['order_flash'] = ['success' => 'Đã ghi nhận thu tiền COD.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/admin/orders/' . rawurlencode((string) $order['_id'])]);
        } catch (OrderWorkflowException $exception) {
            $_SESSION['order_flash'] = ['error' => $exception->getMessage()];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
        }
    }

    /** @param array<string, string> $params */
    public function verifyManualPayment(array $params): Response
    {
        $guard = $this->staffGuard();
        if ($guard !== null) return $guard;
        $id = $params['id'] ?? '';
        $location = '/admin/orders/' . rawurlencode($id);
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['order_flash'] = ['error' => 'Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
        }
        try {
            $user = $_SESSION['auth_user'] ?? [];
            $order = $this->workflow->verifyManualPayment($id, (string) ($user['legacy_id'] ?? ''));
            $_SESSION['order_flash'] = ['success' => 'Đã ghi nhận khoản chuyển khoản/thẻ đã được đối soát.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/admin/orders/' . rawurlencode((string) $order['_id'])]);
        } catch (OrderWorkflowException $exception) {
            $_SESSION['order_flash'] = ['error' => $exception->getMessage()];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
        }
    }

    private function staffGuard(): ?Response
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (!is_array($user)) return new Response('', 302, 'text/html; charset=utf-8', ['Location' => '/login']);
        if (($user['type'] ?? null) !== 'staff' || !in_array(($user['role'] ?? null), ['manager', 'staff'], true)) {
            return new Response('<h1>403 — Không có quyền truy cập</h1><p><a href="/">Về trang chủ</a></p>', 403);
        }
        return null;
    }
}
