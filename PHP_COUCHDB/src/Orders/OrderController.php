<?php
declare(strict_types=1);

namespace App\Orders;

use App\Checkout\CheckoutRepository;
use App\Core\Response;
use App\Core\View;
use App\Core\Csrf;

final class OrderController
{
    public function __construct(
        private readonly CheckoutRepository $orders,
        private readonly OrderWorkflowService $workflow,
    )
    {
    }

    /** @param array<string, mixed> $query */
    public function index(array $params = [], array $query = []): Response
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (!is_array($user) || ($user['type'] ?? null) !== 'customer' || (string) ($user['legacy_id'] ?? '') === '') {
            return new Response('', 302, 'text/html; charset=utf-8', ['Location' => '/login']);
        }
        $cursor = $query['cursor'] ?? null;
        if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 4096) $cursor = null;
        $page = $this->orders->ordersForCustomerPage((string) $user['legacy_id'], $cursor);
        $flash = $_SESSION['customer_order_flash'] ?? null;
        unset($_SESSION['customer_order_flash']);
        return new Response(View::render('orders/index', [
            'title' => 'Đơn hàng của tôi', 'orders' => $page['orders'], 'nextCursor' => $page['next_bookmark'],
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function guestIndex(array $params = [], array $query = []): Response
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (is_array($user)) {
            $destination = ($user['type'] ?? null) === 'customer' ? '/account/orders' : '/';
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $destination]);
        }
        $ids = array_values(array_unique(array_filter(
            is_array($_SESSION['_guest_order_ids'] ?? null) ? $_SESSION['_guest_order_ids'] : [],
            static fn (mixed $id): bool => is_string($id) && $id !== '' && strlen($id) <= 240,
        )));
        $orders = [];
        foreach ($ids as $id) {
            $order = $this->orders->get($id);
            if ($this->isGuestOrder($order)) $orders[] = $order;
        }
        usort($orders, static fn (array $left, array $right): int => strcmp((string) ($right['ordered_at'] ?? ''), (string) ($left['ordered_at'] ?? '')));
        return new Response(View::render('orders/index', [
            'title' => 'Đơn hàng khách vãng lai', 'orders' => $orders, 'nextCursor' => null,
            'flash' => null, 'isGuest' => true,
        ]));
    }

    /** @param array<string, string> $params */
    public function guestDetail(array $params): Response
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (is_array($user)) {
            $destination = ($user['type'] ?? null) === 'customer' ? '/account/orders' : '/';
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $destination]);
        }
        $id = $params['id'] ?? '';
        $ownedIds = is_array($_SESSION['_guest_order_ids'] ?? null) ? $_SESSION['_guest_order_ids'] : [];
        if ($id === '' || strlen($id) > 240 || !in_array($id, $ownedIds, true)) return $this->notFound();
        $order = $this->orders->get($id);
        if (!$this->isGuestOrder($order)) return $this->notFound();
        $flash = $_SESSION['guest_order_flash'] ?? null;
        unset($_SESSION['guest_order_flash']);
        return new Response(View::render('orders/detail', [
            'title' => 'Chi tiết đơn hàng', 'order' => $order, 'flash' => is_array($flash) ? $flash : null,
            'isGuest' => true,
        ]));
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function confirmGuestReceived(array $params, array $query = []): Response
    {
        $id = $params['id'] ?? '';
        $ownedIds = is_array($_SESSION['_guest_order_ids'] ?? null) ? $_SESSION['_guest_order_ids'] : [];
        $location = '/orders/' . rawurlencode($id);
        if ($id === '' || strlen($id) > 240 || !in_array($id, $ownedIds, true)) return $this->notFound();
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['guest_order_flash'] = ['type' => 'error', 'message' => 'Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
        }
        try {
            $this->workflow->confirmGuestReceived($id);
            $_SESSION['guest_order_flash'] = ['type' => 'success', 'message' => 'Đã xác nhận nhận hàng.'];
        } catch (OrderWorkflowException $exception) {
            $_SESSION['guest_order_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => $location]);
    }

    /** @param array<string, string> $params */
    public function detail(array $params): Response
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (!is_array($user) || ($user['type'] ?? null) !== 'customer' || (string) ($user['legacy_id'] ?? '') === '') {
            return new Response('', 302, 'text/html; charset=utf-8', ['Location' => '/login']);
        }
        $id = $params['id'] ?? '';
        if ($id === '' || strlen($id) > 240) return $this->notFound();
        $order = $this->orders->get($id);
        if ($order === null || ($order['type'] ?? null) !== 'order'
            || (string) ($order['customer']['customer_id'] ?? '') !== (string) $user['legacy_id']
            || (isset($order['meta']['checkout']['phase']) && $order['meta']['checkout']['phase'] !== 'completed')) {
            return $this->notFound();
        }
        $flash = $_SESSION['customer_order_flash'] ?? null;
        unset($_SESSION['customer_order_flash']);
        return new Response(View::render('orders/detail', [
            'title' => 'Chi tiết đơn hàng', 'order' => $order, 'flash' => is_array($flash) ? $flash : null, 'isGuest' => false,
        ]));
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function confirmReceived(array $params, array $query = []): Response
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (!is_array($user) || ($user['type'] ?? null) !== 'customer' || (string) ($user['legacy_id'] ?? '') === '') {
            return new Response('', 302, 'text/html; charset=utf-8', ['Location' => '/login']);
        }
        $id = $params['id'] ?? '';
        if ($id === '' || strlen($id) > 240) return $this->notFound();
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            $_SESSION['customer_order_flash'] = ['type' => 'error', 'message' => 'Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/account/orders']);
        }

        try {
            $order = $this->workflow->confirmReceived($id, (string) $user['legacy_id']);
            $_SESSION['customer_order_flash'] = ['type' => 'success', 'message' => 'Đã xác nhận nhận hàng.'];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/account/orders/' . rawurlencode((string) $order['_id'])]);
        } catch (OrderWorkflowException $exception) {
            $_SESSION['customer_order_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/account/orders']);
        }
    }

    private function notFound(): Response
    {
        return new Response(View::render('errors/not-found', ['title' => 'Không tìm thấy đơn hàng']), 404);
    }

    /** @param array<string, mixed>|null $order */
    private function isGuestOrder(?array $order): bool
    {
        return $order !== null
            && ($order['type'] ?? null) === 'order'
            && ($order['guest_order'] ?? false) === true
            && ($order['customer'] ?? null) === null
            && (!isset($order['meta']['checkout']['phase']) || $order['meta']['checkout']['phase'] === 'completed');
    }
}
