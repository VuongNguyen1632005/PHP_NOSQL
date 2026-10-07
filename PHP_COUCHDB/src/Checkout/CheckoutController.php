<?php
declare(strict_types=1);

namespace App\Checkout;

use App\Cart\CartServiceInterface;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;

final class CheckoutController
{
    public function __construct(private readonly CheckoutService $checkout, private readonly CartServiceInterface $cart)
    {
    }

    /** @param array<string, string> $routeParams @param array<string, mixed> $query */
    public function index(array $routeParams, array $query): Response
    {
        $items = array_values(array_filter($this->cart->items(), static fn (array $line): bool => ($line['selected'] ?? false) === true && ($line['available'] ?? false) === true));
        if ($items === []) return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/cart']);
        $quote = $this->checkout->preview('BD');
        if (($_SESSION['_checkout_completed'] ?? false) === true || !preg_match('/^[a-f0-9]{32}$/', (string) ($_SESSION['_checkout_token'] ?? ''))) {
            $_SESSION['_checkout_token'] = bin2hex(random_bytes(16));
            unset($_SESSION['_checkout_completed']);
        }
        $user = $_SESSION['auth_user'] ?? [];
        $flash = $_SESSION['checkout_flash'] ?? null;
        unset($_SESSION['checkout_flash']);
        return new Response(View::render('checkout/index', [
            'title' => 'Thanh toán',
            'items' => $items,
            'subtotal' => $quote['subtotal'],
            'shippingFee' => $quote['shipping_fee'],
            'discountAmount' => $quote['discount_amount'],
            'grandTotal' => $quote['grand_total'],
            'shippingMethods' => $this->checkout->shippingMethods(),
            'token' => (string) $_SESSION['_checkout_token'],
            'name' => (string) ($user['name'] ?? ''),
            'phone' => (string) ($user['phone'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'address' => '',
            'note' => '',
            'shippingCode' => 'BD',
            'voucherCode' => '',
            'error' => null,
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /** @param array<string, string> $routeParams @param array<string, mixed> $query */
    public function submit(array $routeParams, array $query): Response
    {
        $form = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'note' => trim((string) ($_POST['note'] ?? '')),
        ];
        $token = (string) ($_POST['checkout_token'] ?? '');
        if (!Csrf::isValid($_POST['csrf_token'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $token)
            || !hash_equals((string) ($_SESSION['_checkout_token'] ?? ''), $token)) {
            return $this->formError('Yêu cầu thanh toán không hợp lệ. Hãy tải lại trang rồi thử lại.', $form, 403);
        }
        if (($_POST['preview_only'] ?? '') === '1') {
            return $this->renderForm($form, $token, null, 200, (string) ($_POST['shipping_code'] ?? 'BD'), (string) ($_POST['voucher_code'] ?? ''));
        }
        try {
            $order = $this->checkout->placeOrder(
                $form,
                (string) ($_POST['shipping_code'] ?? 'BD'),
                (string) ($_POST['voucher_code'] ?? ''),
                (string) ($_POST['payment_method'] ?? ''),
                $token,
            );
        } catch (CheckoutException $exception) {
            $_SESSION['_checkout_token'] = bin2hex(random_bytes(16));
            return $this->renderForm($form, (string) $_SESSION['_checkout_token'], $exception->getMessage(), 422, (string) ($_POST['shipping_code'] ?? 'BD'), (string) ($_POST['voucher_code'] ?? ''));
        }
        if (($order['guest_order'] ?? false) === true && is_string($order['_id'] ?? null)) {
            $guestOrderIds = is_array($_SESSION['_guest_order_ids'] ?? null) ? $_SESSION['_guest_order_ids'] : [];
            $guestOrderIds[] = $order['_id'];
            $_SESSION['_guest_order_ids'] = array_values(array_unique(array_filter(
                $guestOrderIds,
                static fn (mixed $id): bool => is_string($id) && $id !== '',
            )));
        }
        $_SESSION['_checkout_completed'] = true;
        $_SESSION['cart_flash'] = ['type' => 'success', 'message' => 'Đặt hàng thành công. Mã đơn: ' . (string) ($order['legacy_id'] ?? $order['_id']) . ' · Tổng thanh toán ' . number_format((float) ($order['totals']['grand_total'] ?? 0), 0, ',', '.') . '₫. Thanh toán khi nhận hàng.'];
        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/cart']);
    }

    /** @param array{name:string,phone:string,email:string,address:string,note:string} $form */
    private function formError(string $error, array $form, int $status): Response
    {
        return $this->renderForm($form, (string) ($_SESSION['_checkout_token'] ?? ''), $error, $status, (string) ($_POST['shipping_code'] ?? 'BD'), (string) ($_POST['voucher_code'] ?? ''));
    }

    /** @param array{name:string,phone:string,email:string,address:string,note:string} $form */
    private function renderForm(array $form, string $token, ?string $error, int $status, string $shippingCode, string $voucherCode): Response
    {
        $items = array_values(array_filter($this->cart->items(), static fn (array $line): bool => ($line['selected'] ?? false) === true && ($line['available'] ?? false) === true));
        if ($items === []) return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/cart']);
        try {
            $quote = $this->checkout->preview($shippingCode, $voucherCode);
        } catch (CheckoutException) {
            $quote = $this->checkout->preview($shippingCode);
            $error ??= 'Mã giảm giá không khả dụng.';
        }
        return new Response(View::render('checkout/index', [
            'title' => 'Thanh toán', 'items' => $items,
            'subtotal' => $quote['subtotal'], 'shippingFee' => $quote['shipping_fee'],
            'discountAmount' => $quote['discount_amount'], 'grandTotal' => $quote['grand_total'],
            'shippingMethods' => $this->checkout->shippingMethods(),
            'token' => $token,
            'name' => $form['name'], 'phone' => $form['phone'], 'email' => $form['email'], 'address' => $form['address'],
            'note' => $form['note'], 'shippingCode' => $shippingCode,
            'voucherCode' => trim($voucherCode), 'error' => $error, 'flash' => null,
        ]), $status);
    }
}
