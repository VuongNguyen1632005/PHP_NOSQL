<?php

declare(strict_types=1);

namespace App\Auth;

use App\Cart\GuestCartService;
use App\Cart\MemberCartRepository;
use App\Cart\MemberCartService;
use App\Catalog\ProductRepository;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;

final class AuthController
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
    public function loginForm(array $routeParams, array $query): Response
    {
        $flash = $_SESSION['auth_flash'] ?? null;
        unset($_SESSION['auth_flash']);
        return new Response(View::render('auth/login', [
            'title' => 'Đăng nhập', 'email' => '', 'error' => null,
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function registerForm(array $routeParams, array $query): Response
    {
        return new Response(View::render('auth/register', ['title' => 'Đăng ký', 'fullName' => '', 'email' => '', 'error' => null]));
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function login(array $routeParams, array $query): Response
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            return $this->loginError('Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.', $email, 403);
        }

        try {
            $account = $this->accounts->authenticate($email, (string) ($_POST['password'] ?? ''));
        } catch (AuthException $exception) {
            return $this->loginError($exception->getMessage(), $email, 422);
        }

        $accountType = (string) ($account['type'] ?? '');
        $requiresPasswordReset = $accountType === 'customer'
            && (($account['auth']['requires_password_reset'] ?? false) === true);
        $memberCart = null;
        $merge = ['merged' => 0, 'warnings' => 0];
        if ($accountType === 'customer' && !$requiresPasswordReset) {
            $customerId = (string) ($account['legacy_id'] ?? '');
            $guestItems = (new GuestCartService($this->products))->items();
            $memberCart = new MemberCartService($this->memberCarts, $this->products, $customerId);
            $merge = $memberCart->mergeGuestItems($guestItems);
        }

        session_regenerate_id(true);
        unset($_SESSION['_csrf_token'], $_SESSION['_checkout_token'], $_SESSION['_checkout_completed']);
        $_SESSION['auth_user'] = [
            'type' => $accountType,
            'document_id' => (string) ($account['_id'] ?? ''),
            'legacy_id' => (string) ($account['legacy_id'] ?? ''),
            'name' => (string) ($account['profile']['name'] ?? ''),
            'phone' => (string) ($account['profile']['phone'] ?? ''),
            'email' => (string) ($account['profile']['email'] ?? $account['auth']['username'] ?? ''),
            'username' => (string) ($account['auth']['username'] ?? ''),
            'role' => $accountType === 'staff' ? (string) ($account['role'] ?? '') : 'customer',
            'password_reset_required' => $requiresPasswordReset,
        ];

        if ($accountType === 'customer') {
            if ($requiresPasswordReset) {
                return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/account/password']);
            }
            unset($_SESSION['guest_cart']);
            $_SESSION['_cart_line_count'] = $memberCart?->lineCount() ?? 0;
            $_SESSION['cart_flash'] = match (true) {
                $merge['warnings'] > 0 => ['type' => 'warning', 'message' => 'Giỏ được đồng bộ; một số dòng đã được điều chỉnh theo tồn kho hiện tại.'],
                $merge['merged'] > 0 => ['type' => 'success', 'message' => 'Giỏ hàng khách đã được gộp vào tài khoản.'],
                default => null,
            };
            return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/cart']);
        }

        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/']);
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function register(array $routeParams, array $query): Response
    {
        $fullName = trim((string) ($_POST['fullName'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            return $this->registerError('Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.', $fullName, $email, 403);
        }

        try {
            $this->accounts->register(
                $fullName,
                $email,
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['confirmPassword'] ?? ''),
            );
        } catch (AuthException $exception) {
            return $this->registerError($exception->getMessage(), $fullName, $email, 422);
        }

        $_SESSION['auth_flash'] = ['type' => 'success', 'message' => 'Đăng ký thành công. Hãy đăng nhập bằng email và mật khẩu vừa tạo.'];
        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/login']);
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function logout(array $routeParams, array $query): Response
    {
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            return new Response('<h1>Yêu cầu không hợp lệ</h1><p>Hãy tải lại trang rồi thử lại.</p>', 403);
        }
        unset($_SESSION['auth_user'], $_SESSION['_cart_line_count'], $_SESSION['_csrf_token'], $_SESSION['_checkout_token'], $_SESSION['_checkout_completed'], $_SESSION['_guest_order_ids']);
        $_SESSION['guest_cart'] = [];
        session_regenerate_id(true);
        $_SESSION['auth_flash'] = ['type' => 'success', 'message' => 'Bạn đã đăng xuất.'];
        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/login']);
    }

    private function loginError(string $error, string $email, int $status): Response
    {
        return new Response(View::render('auth/login', ['title' => 'Đăng nhập', 'email' => $email, 'error' => $error, 'flash' => null]), $status);
    }

    private function registerError(string $error, string $fullName, string $email, int $status): Response
    {
        return new Response(View::render('auth/register', ['title' => 'Đăng ký', 'fullName' => $fullName, 'email' => $email, 'error' => $error]), $status);
    }
}
