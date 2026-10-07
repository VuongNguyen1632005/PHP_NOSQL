<?php

declare(strict_types=1);

namespace App\Cart;

use App\Auth\JwtBearerAuthenticator;
use App\Catalog\ProductRepository;
use App\Checkout\CheckoutException;
use App\Checkout\CheckoutRepository;
use App\Checkout\CheckoutService;
use App\Core\Response;

final class CustomerCommerceApiController
{
    public function __construct(
        private readonly JwtBearerAuthenticator $bearer,
        private readonly MemberCartRepository $carts,
        private readonly ProductRepository $products,
        private readonly CheckoutRepository $orders,
    ) {
    }

    /** @param array<string, string> $params @param array<string, mixed> $query */
    public function cart(array $params, array $query): Response
    {
        $customer = $this->customer();
        if ($customer instanceof Response) return $customer;
        return $this->json($this->cartPayload($this->cartService($customer)));
    }

    /** @param array<string, string> $params @param array<string, mixed> $query */
    public function add(array $params, array $query): Response
    {
        return $this->mutateCart(static function (MemberCartService $cart, array $body): ?Response {
            $productId = self::requiredString($body, 'product_id', 120);
            $variantId = self::requiredString($body, 'variant_id', 160);
            $quantity = self::quantity($body['quantity'] ?? null);
            if ($productId instanceof Response) return $productId;
            if ($variantId instanceof Response) return $variantId;
            if ($quantity instanceof Response) return $quantity;
            $cart->add($productId, $variantId, $quantity);
            return null;
        });
    }

    /** @param array<string, string> $params @param array<string, mixed> $query */
    public function update(array $params, array $query): Response
    {
        return $this->mutateCart(static function (MemberCartService $cart, array $body): ?Response {
            $variantId = self::requiredString($body, 'variant_id', 160);
            $quantity = self::quantity($body['quantity'] ?? null);
            if ($variantId instanceof Response) return $variantId;
            if ($quantity instanceof Response) return $quantity;
            $cart->updateQuantity($variantId, $quantity);
            return null;
        });
    }

    /** @param array<string, string> $params @param array<string, mixed> $query */
    public function select(array $params, array $query): Response
    {
        return $this->mutateCart(static function (MemberCartService $cart, array $body): ?Response {
            $variantId = self::requiredString($body, 'variant_id', 160);
            if ($variantId instanceof Response) return $variantId;
            if (!is_bool($body['selected'] ?? null)) return self::json(['error' => 'selected must be a boolean.'], 400);
            $cart->setSelected($variantId, $body['selected']);
            return null;
        });
    }

    /** @param array<string, string> $params @param array<string, mixed> $query */
    public function selectAll(array $params, array $query): Response
    {
        return $this->mutateCart(static function (MemberCartService $cart, array $body): ?Response {
            if (!is_bool($body['selected'] ?? null)) return self::json(['error' => 'selected must be a boolean.'], 400);
            $cart->setAllSelected($body['selected']);
            return null;
        });
    }

    /** @param array<string, string> $params @param array<string, mixed> $query */
    public function changeVariant(array $params, array $query): Response
    {
        return $this->mutateCart(static function (MemberCartService $cart, array $body): ?Response {
            $oldId = self::requiredString($body, 'old_variant_id', 160);
            $newId = self::requiredString($body, 'new_variant_id', 160);
            if ($oldId instanceof Response) return $oldId;
            if ($newId instanceof Response) return $newId;
            $cart->changeVariant($oldId, $newId);
            return null;
        });
    }

    /** @param array<string, string> $params @param array<string, mixed> $query */
    public function remove(array $params, array $query): Response
    {
        return $this->mutateCart(static function (MemberCartService $cart, array $body): ?Response {
            $variantId = self::requiredString($body, 'variant_id', 160);
            if ($variantId instanceof Response) return $variantId;
            $cart->remove($variantId);
            return null;
        });
    }

    /** @param array<string, string> $params @param array<string, mixed> $query */
    public function checkoutPreview(array $params, array $query): Response
    {
        $customer = $this->customer();
        if ($customer instanceof Response) return $customer;
        $body = self::jsonInput();
        if ($body instanceof Response) return $body;
        $shippingCode = self::optionalString($body, 'shipping_code', 40, 'BD');
        $voucherCode = self::optionalString($body, 'voucher_code', 64, '');
        if ($shippingCode instanceof Response) return $shippingCode;
        if ($voucherCode instanceof Response) return $voucherCode;

        $checkout = $this->checkoutService($customer);
        try {
            $quote = $checkout->preview($shippingCode, $voucherCode);
        } catch (CheckoutException $exception) {
            return self::json(['error' => $exception->getMessage()], 422);
        }
        return self::json(['data' => [
            'items' => array_map(static fn (array $item): array => [
                'product_id' => $item['product_id'], 'product_name' => $item['product_name'],
                'variant_id' => $item['variant_id'], 'size' => $item['size'],
                'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'line_total' => $item['line_total'],
            ], $quote['items']),
            'shipping_code' => $quote['shipping_code'],
            'subtotal' => $quote['subtotal'], 'shipping_fee' => $quote['shipping_fee'],
            'discount_amount' => $quote['discount_amount'], 'grand_total' => $quote['grand_total'],
        ]]);
    }

    /** @param array<string, string> $params @param array<string, mixed> $query */
    public function checkout(array $params, array $query): Response
    {
        $customer = $this->customer();
        if ($customer instanceof Response) return $customer;
        $body = self::jsonInput();
        if ($body instanceof Response) return $body;

        $receiverInput = $body['receiver'] ?? null;
        if (!is_array($receiverInput) || array_is_list($receiverInput)) {
            return self::json(['error' => 'receiver must be a JSON object.'], 400);
        }
        $profile = is_array($customer['profile'] ?? null) ? $customer['profile'] : [];
        $receiver = [];
        foreach (['name', 'phone', 'email', 'address', 'note'] as $field) {
            $fallback = match ($field) {
                'name' => (string) ($profile['name'] ?? ''),
                'email' => (string) ($profile['email'] ?? $customer['auth']['username'] ?? ''),
                'phone' => (string) ($profile['phone'] ?? ''),
                default => '',
            };
            $value = $receiverInput[$field] ?? $fallback;
            if (!is_string($value)) return self::json(['error' => 'receiver.' . $field . ' must be a string.'], 400);
            $receiver[$field] = trim($value);
        }
        $shippingCode = self::optionalString($body, 'shipping_code', 40, 'BD');
        $voucherCode = self::optionalString($body, 'voucher_code', 64, '');
        $paymentMethod = self::optionalString($body, 'payment_method', 40, 'cod');
        if ($shippingCode instanceof Response) return $shippingCode;
        if ($voucherCode instanceof Response) return $voucherCode;
        if ($paymentMethod instanceof Response) return $paymentMethod;
        $idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            return self::json(['error' => 'Idempotency-Key header must be 32 lowercase hexadecimal characters.'], 400);
        }

        try {
            $order = $this->checkoutService($customer)->placeOrder(
                $receiver, $shippingCode, $voucherCode, $paymentMethod, $idempotencyKey,
            );
        } catch (CheckoutException $exception) {
            return self::json(['error' => $exception->getMessage()], 422);
        }

        return self::json(['data' => [
            'id' => (string) ($order['_id'] ?? ''),
            'number' => (string) ($order['legacy_id'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'ordered_at' => $order['ordered_at'] ?? null,
            'totals' => [
                'subtotal' => (float) ($order['totals']['subtotal'] ?? 0),
                'shipping_fee' => (float) ($order['totals']['shipping_fee'] ?? 0),
                'discount_amount' => (float) ($order['totals']['discount_amount'] ?? 0),
                'grand_total' => (float) ($order['totals']['grand_total'] ?? 0),
                'currency' => (string) ($order['totals']['currency'] ?? 'VND'),
            ],
            'payment' => ['method' => 'cod', 'status' => (string) ($order['payment']['status'] ?? 'unpaid')],
        ]], 201);
    }

    /** @return array<string, mixed>|Response */
    private function customer(): array|Response
    {
        $account = $this->bearer->account();
        if ($account instanceof Response) return $account;
        if (($account['type'] ?? null) !== 'customer' || !is_string($account['legacy_id'] ?? null)
            || $account['legacy_id'] === '') {
            return self::json(['error' => 'Customer access is required.'], 403);
        }
        return $account;
    }

    /** @param array<string, mixed> $customer */
    private function cartService(array $customer): MemberCartService
    {
        return new MemberCartService($this->carts, $this->products, (string) $customer['legacy_id']);
    }

    /** @param array<string, mixed> $customer */
    private function checkoutService(array $customer): CheckoutService
    {
        $cart = $this->cartService($customer);
        return new CheckoutService(
            $this->orders,
            $cart,
            (string) $customer['legacy_id'],
            'customer:' . (string) $customer['legacy_id'],
            null,
            is_array($customer['profile'] ?? null) ? $customer['profile'] : [],
        );
    }

    private function mutateCart(callable $operation): Response
    {
        $customer = $this->customer();
        if ($customer instanceof Response) return $customer;
        $body = self::jsonInput();
        if ($body instanceof Response) return $body;
        $cart = $this->cartService($customer);
        try {
            $error = $operation($cart, $body);
            if ($error instanceof Response) return $error;
        } catch (CartInputException $exception) {
            return self::json(['error' => $exception->getMessage()], 422);
        }
        return self::json($this->cartPayload($cart));
    }

    /** @return array<string, mixed> */
    private function cartPayload(MemberCartService $cart): array
    {
        $items = $cart->items();
        $data = array_map(static fn (array $item): array => [
            'product_id' => (string) ($item['product_id'] ?? ''),
            'variant_id' => (string) ($item['variant_id'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'image_url' => '/' . ltrim((string) (($item['image'] ?? '') !== '' ? $item['image'] : 'assets/img/resourse/product-placeholder.png'), '/'),
            'size' => (string) ($item['size'] ?? ''),
            'unit_price' => (float) ($item['unit_price'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'stock' => (int) ($item['stock'] ?? 0),
            'selected' => ($item['selected'] ?? false) === true,
            'available' => ($item['available'] ?? false) === true,
            'variants' => array_map(static fn (array $variant): array => [
                'id' => (string) ($variant['variant_id'] ?? ''),
                'size' => (string) ($variant['size'] ?? ''),
                'stock' => (int) ($variant['stock'] ?? 0),
            ], is_array($item['variants'] ?? null) ? $item['variants'] : []),
        ], $items);
        return [
            'data' => $data,
            'line_count' => count($items),
            'selected_subtotal' => $cart->selectedSubtotal($items),
        ];
    }

    /** @return array<string, mixed>|Response */
    private static function jsonInput(): array|Response
    {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') return self::json(['error' => 'Content-Type must be application/json.'], 415);
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 16384) return self::json(['error' => 'Request body is too large.'], 413);
        $body = file_get_contents('php://input');
        if (!is_string($body) || $body === '') return self::json(['error' => 'A JSON request body is required.'], 400);
        try {
            $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::json(['error' => 'Request body must be valid JSON.'], 400);
        }
        if (!is_array($input) || array_is_list($input)) return self::json(['error' => 'Request body must be a JSON object.'], 400);
        return $input;
    }

    /** @param array<string, mixed> $input */
    private static function requiredString(array $input, string $field, int $maxLength): string|Response
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
            return self::json(['error' => $field . ' must be a non-empty string of at most ' . $maxLength . ' characters.'], 400);
        }
        return trim($value);
    }

    /** @param array<string, mixed> $input */
    private static function optionalString(array $input, string $field, int $maxLength, string $default): string|Response
    {
        $value = $input[$field] ?? $default;
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            return self::json(['error' => $field . ' must be a string of at most ' . $maxLength . ' characters.'], 400);
        }
        return trim($value);
    }

    private static function quantity(mixed $value): int|Response
    {
        $quantity = filter_var($value, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > 999) {
            return self::json(['error' => 'quantity must be an integer between 1 and 999.'], 400);
        }
        return $quantity;
    }

    /** @param array<string, mixed> $body
     *  @param array<string, string> $headers
     */
    private static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response(
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            'application/json; charset=utf-8',
            $headers,
        );
    }
}
