<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;
use App\Catalog\ProductRepository;
use App\Cart\GuestCartService;
use App\Cart\MemberCartRepository;
use App\Cart\MemberCartService;

final class CustomerPasswordController
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly ProductRepository $products,
        private readonly MemberCartRepository $memberCarts,
    ) {
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function form(array $routeParams, array $query): Response
    {
        if (($user = currentUser()) === null || ($user['type'] ?? null) !== 'customer') {
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/login']);
        }
        $flash = $_SESSION['password_flash'] ?? null;
        unset($_SESSION['password_flash']);
        return new Response(View::render('auth/password-change', [
            'title' => 'Đổi mật khẩu',
            'error' => null,
            'flash' => is_array($flash) ? $flash : null,
            'required' => ($user['password_reset_required'] ?? false) === true,
        ]));
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function change(array $routeParams, array $query): Response
    {
        $user = currentUser();
        if ($user === null || ($user['type'] ?? null) !== 'customer') {
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/login']);
        }
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            return new Response(View::render('auth/password-change', [
                'title' => 'Đổi mật khẩu', 'error' => 'Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.', 'flash' => null,
                'required' => ($user['password_reset_required'] ?? false) === true,
            ]), 403);
        }

        try {
            $this->accounts->changeCustomerPassword(
                (string) ($user['document_id'] ?? ''),
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? ''),
            );
        } catch (AuthException $exception) {
            return new Response(View::render('auth/password-change', [
                'title' => 'Đổi mật khẩu', 'error' => $exception->getMessage(), 'flash' => null,
                'required' => ($user['password_reset_required'] ?? false) === true,
            ]), 422);
        }

        session_regenerate_id(true);
        $_SESSION['auth_user']['password_reset_required'] = false;
        $customerId = (string) ($user['legacy_id'] ?? '');
        $memberCart = new MemberCartService($this->memberCarts, $this->products, $customerId);
        $merge = $memberCart->mergeGuestItems((new GuestCartService($this->products))->items());
        unset($_SESSION['guest_cart']);
        $_SESSION['_cart_line_count'] = $memberCart->lineCount();
        $_SESSION['cart_flash'] = match (true) {
            $merge['warnings'] > 0 => ['type' => 'warning', 'message' => 'Giỏ được đồng bộ; một số dòng đã được điều chỉnh theo tồn kho hiện tại.'],
            $merge['merged'] > 0 => ['type' => 'success', 'message' => 'Giỏ hàng khách đã được gộp vào tài khoản.'],
            default => null,
        };
        $_SESSION['password_flash'] = ['type' => 'success', 'message' => 'Đổi mật khẩu thành công.'];
        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/account/orders']);
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function help(array $routeParams, array $query): Response
    {
        return new Response(View::render('auth/password-help', ['title' => 'Hỗ trợ mật khẩu']));
    }
}
