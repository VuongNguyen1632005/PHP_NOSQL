<?php

declare(strict_types=1);

namespace App\Cart;

use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;

final class CartController
{
    public function __construct(private readonly CartServiceInterface $cart)
    {
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function index(array $routeParams, array $query): Response
    {
        $items = $this->cart->items();
        $flash = $_SESSION['cart_flash'] ?? null;
        unset($_SESSION['cart_flash']);
        return new Response(View::render('cart/index', [
            'title' => 'Giỏ hàng', 'items' => $items,
            'subtotal' => $this->cart->selectedSubtotal($items),
            'lineCount' => $this->cart->lineCount(), 'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function add(array $routeParams, array $query): Response
    {
        return $this->mutate(function (): void {
            $this->cart->add(
                trim((string) ($_POST['product_id'] ?? '')),
                trim((string) ($_POST['variant_id'] ?? '')),
                $this->positiveInteger($_POST['quantity'] ?? null),
            );
            $_SESSION['cart_flash'] = ['type' => 'success', 'message' => 'Đã thêm sản phẩm vào giỏ hàng.'];
        });
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function update(array $routeParams, array $query): Response
    {
        return $this->mutate(function (): void {
            $this->cart->updateQuantity(
                trim((string) ($_POST['variant_id'] ?? '')),
                $this->positiveInteger($_POST['quantity'] ?? null),
            );
            $_SESSION['cart_flash'] = ['type' => 'success', 'message' => 'Đã cập nhật số lượng.'];
        });
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function changeVariant(array $routeParams, array $query): Response
    {
        return $this->mutate(function (): void {
            $this->cart->changeVariant(
                trim((string) ($_POST['old_variant_id'] ?? '')),
                trim((string) ($_POST['new_variant_id'] ?? '')),
            );
            $_SESSION['cart_flash'] = ['type' => 'success', 'message' => 'Đã đổi kích thước sản phẩm.'];
        });
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function select(array $routeParams, array $query): Response
    {
        return $this->mutate(function (): void {
            $this->cart->setSelected(
                trim((string) ($_POST['variant_id'] ?? '')),
                ($_POST['selected'] ?? '') === '1',
            );
            $_SESSION['cart_flash'] = null;
        });
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function selectAll(array $routeParams, array $query): Response
    {
        return $this->mutate(function (): void {
            $this->cart->setAllSelected(($_POST['selected'] ?? '') === '1');
            $_SESSION['cart_flash'] = null;
        });
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function remove(array $routeParams, array $query): Response
    {
        return $this->mutate(function (): void {
            $this->cart->remove(trim((string) ($_POST['variant_id'] ?? '')));
            $_SESSION['cart_flash'] = ['type' => 'success', 'message' => 'Đã xóa sản phẩm khỏi giỏ hàng.'];
        });
    }

    private function mutate(callable $operation): Response
    {
        if (!Csrf::isValid($_POST['csrf_token'] ?? null)) {
            return new Response('<h1>Yêu cầu không hợp lệ</h1><p>Hãy tải lại trang giỏ hàng rồi thử lại.</p><p><a href="/cart">Quay lại giỏ hàng</a></p>', 403);
        }

        try {
            $operation();
        } catch (CartInputException $exception) {
            $_SESSION['cart_flash'] = ['type' => 'danger', 'message' => $exception->getMessage()];
        }
        return new Response('', 303, 'text/plain; charset=utf-8', ['Location' => '/cart']);
    }

    private function positiveInteger(mixed $value): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        return $validated === false ? 0 : (int) $validated;
    }
}
