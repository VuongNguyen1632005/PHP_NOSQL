<?php
declare(strict_types=1);

namespace App\Checkout;

use App\Cart\CartServiceInterface;
use App\Infrastructure\CouchDB\CouchDbResponse;
use RuntimeException;

final class CheckoutService
{
    private readonly PricingService $pricing;

    public function __construct(
        private readonly CheckoutRepository $documents,
        private readonly CartServiceInterface|null $cart,
        private readonly ?string $customerId,
        private readonly string $scope,
        ?PricingService $pricing = null,
        private readonly ?array $customerProfile = null,
    ) {
        $this->pricing = $pricing ?? new PricingService();
    }

    /** @return list<array<string, mixed>> */
    public function shippingMethods(): array
    {
        return $this->documents->activeDocuments('shipping_method');
    }

    /** @return array{items:list<array<string,mixed>>,subtotal:int,shipping_fee:int,discount_amount:int,grand_total:int,shipping_code:string} */
    public function preview(string $shippingCode = 'BD', string $voucherCode = ''): array
    {
        if ($this->cart === null) throw new RuntimeException('Checkout preview cannot read the current cart.');
        $selected = array_values(array_filter($this->cart->items(), static fn (array $line): bool => ($line['selected'] ?? false) === true && ($line['available'] ?? false) === true));
        if ($selected === []) throw new CheckoutException('Vui lòng chọn ít nhất một sản phẩm để đặt hàng.');
        $prepared = $this->prepareItems($selected);
        $shippingCode = strtoupper(trim($shippingCode));
        $shipping = $this->documents->get('shipping_method:' . $shippingCode);
        if ($shipping === null || ($shipping['active'] ?? false) !== true) {
            $shippingCode = 'BD';
            $shipping = $this->documents->get('shipping_method:BD');
        }
        if ($shipping === null || ($shipping['active'] ?? false) !== true) throw new CheckoutException('Chưa có phương thức vận chuyển khả dụng.');
        $subtotal = array_sum(array_map(static fn (array $item): int => $item['line_total'], $prepared['items']));
        $voucher = null;
        $voucherCode = strtoupper(trim($voucherCode));
        if ($voucherCode !== '') {
            $voucher = $this->documents->get('voucher:' . $voucherCode);
            if ($voucher === null || ($voucher['active'] ?? false) !== true || (int) ($voucher['remaining_quantity'] ?? 0) < 1) {
                throw new CheckoutException('Mã giảm giá không tồn tại, đã hết hạn hoặc đã hết lượt sử dụng.');
            }
        }
        $quote = $this->pricing->calculate($subtotal, (int) ($shipping['fee'] ?? 0), $voucher);
        return ['items' => $prepared['items'], 'shipping_code' => $shippingCode] + $quote;
    }

    /**
     * @param array{name:string,phone:string,email:string,address:string,note:string} $receiver
     * @return array<string, mixed>
     */
    public function placeOrder(array $receiver, string $shippingCode, string $voucherCode, string $paymentMethod, string $token): array
    {
        $receiver = $this->validateReceiver($receiver);
        $shippingCode = strtoupper(trim($shippingCode));
        $voucherCode = strtoupper(trim($voucherCode));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new CheckoutException('Phiên đặt hàng không hợp lệ. Hãy tải lại trang thanh toán.');
        if ($paymentMethod !== 'cod') throw new CheckoutException('Phương thức thanh toán này chưa được hỗ trợ.');

        $fingerprint = hash('sha256', json_encode([
            'receiver' => $receiver,
            'shipping_code' => $shippingCode,
            'voucher_code' => $voucherCode,
            'payment_method' => $paymentMethod,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $operationId = hash('sha256', $this->scope . ':' . $token);
        $orderId = 'order:checkout:' . $operationId;
        $existing = $this->documents->get($orderId);
        if ($existing !== null) {
            if (!hash_equals((string) ($existing['meta']['checkout']['request_fingerprint'] ?? ''), $fingerprint)) {
                throw new CheckoutException('Mã đặt hàng đã được dùng với thông tin khác. Hãy tải lại trang thanh toán.');
            }
            return $this->finish($existing);
        }

        if ($this->cart === null) throw new RuntimeException('Checkout cannot read the current cart.');
        $selected = array_values(array_filter($this->cart->items(), static fn (array $line): bool => ($line['selected'] ?? false) === true));
        if ($selected === []) throw new CheckoutException('Vui lòng chọn ít nhất một sản phẩm để đặt hàng.');

        $shipping = $this->documents->get('shipping_method:' . $shippingCode);
        if ($shipping === null || ($shipping['active'] ?? false) !== true) throw new CheckoutException('Phương thức vận chuyển không còn khả dụng.');

        $prepared = $this->prepareItems($selected);
        $subtotal = array_sum(array_map(static fn (array $item): int => $item['line_total'], $prepared['items']));
        $shippingFee = (int) ($shipping['fee'] ?? 0);
        $voucher = null;
        if ($voucherCode !== '') {
            $voucher = $this->documents->get('voucher:' . $voucherCode);
            if ($voucher === null || ($voucher['active'] ?? false) !== true || (int) ($voucher['remaining_quantity'] ?? 0) < 1) {
                throw new CheckoutException('Mã giảm giá không tồn tại, đã hết hạn hoặc đã hết lượt sử dụng.');
            }
        }
        $quote = $this->pricing->calculate($subtotal, $shippingFee, $voucher);

        $customer = null;
        if ($this->customerId !== null) {
            $sessionUser = $this->customerProfile ?? ($_SESSION['auth_user'] ?? []);
            $customer = [
                'customer_id' => $this->customerId,
                'name' => (string) ($sessionUser['name'] ?? $receiver['name']),
                'email' => (string) ($sessionUser['username'] ?? $receiver['email']),
                'phone' => $receiver['phone'],
            ];
        }
        $order = [
            '_id' => $orderId,
            'type' => 'order',
            'schema_version' => 2,
            'legacy_id' => 'CO' . strtoupper(substr($operationId, 0, 24)),
            'customer' => $customer,
            'guest_order' => $customer === null,
            'assigned_staff_id' => null,
            'receiver' => $receiver,
            'items' => $prepared['items'],
            'shipping' => [
                'method_code' => $shippingCode,
                'method_name' => (string) ($shipping['name'] ?? $shippingCode),
                'fee' => $shippingFee,
                'currency' => 'VND',
            ],
            'discount' => $voucher === null ? ['code' => null, 'type' => null, 'value' => 0, 'amount' => 0, 'currency' => 'VND'] : [
                'code' => $voucherCode,
                'type' => $voucher['discount_type'],
                'value' => $voucher['value'],
                'amount' => $quote['discount_amount'],
                'currency' => 'VND',
            ],
            'payment' => ['method' => 'cod', 'legacy_label' => 'Thanh toán khi nhận hàng', 'status' => 'unpaid', 'paid' => false],
            'totals' => [
                'total_quantity' => $prepared['quantity'],
                'subtotal' => $quote['subtotal'],
                'shipping_fee' => $quote['shipping_fee'],
                'discount_amount' => $quote['discount_amount'],
                'grand_total' => $quote['grand_total'],
                'currency' => 'VND',
            ],
            'status' => 'pending',
            'status_history' => [['status' => 'pending', 'at' => gmdate('c'), 'source' => 'php_checkout']],
            'note' => $receiver['note'],
            'ordered_at' => gmdate('c'),
            'active' => true,
            'meta' => [
                'source' => 'PHP checkout',
                'checkout' => [
                    'operation_id' => $operationId,
                    'request_fingerprint' => $fingerprint,
                    'phase' => 'stock_reserving',
                    'stock_plan' => $prepared['stock_plan'],
                    'voucher_code' => $voucherCode === '' ? null : $voucherCode,
                    'failure_reason' => null,
                    'updated_at' => gmdate('c'),
                ],
            ],
        ];
        $response = $this->documents->put($order);
        if ($response->statusCode === 409) {
            $existing = $this->documents->get($orderId);
            if ($existing === null || !hash_equals((string) ($existing['meta']['checkout']['request_fingerprint'] ?? ''), $fingerprint)) {
                throw new CheckoutException('Yêu cầu đặt hàng bị trùng nhưng thông tin không khớp.');
            }
            $order = $existing;
        } elseif ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('Could not create the checkout journal (' . $response->statusCode . ').');
        }
        return $this->finish($order);
    }

    /** @return array<string, mixed> */
    public function recover(string $orderId): array
    {
        $order = $this->documents->get($orderId);
        if ($order === null || ($order['type'] ?? null) !== 'order' || !isset($order['meta']['checkout']['operation_id'])) {
            throw new CheckoutException('Không tìm thấy nhật ký checkout cần phục hồi.');
        }
        return $this->finish($order);
    }

    /** @param list<array<string, mixed>> $selected
     *  @return array{items:list<array<string,mixed>>,quantity:int,stock_plan:array<string,array<string,array{quantity:int,unit_price:int}>>}
     */
    private function prepareItems(array $selected): array
    {
        $items = [];
        $stockPlan = [];
        $quantityTotal = 0;
        foreach ($selected as $line) {
            $productId = (string) ($line['product_id'] ?? '');
            $variantId = (string) ($line['variant_id'] ?? '');
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($productId === '' || $variantId === '' || $quantity < 1 || $quantity > 999) throw new CheckoutException('Một dòng trong giỏ hàng không hợp lệ.');
            $product = $this->documents->get('product:' . $productId);
            $variant = null;
            foreach ($product['variants'] ?? [] as $candidate) {
                if (($candidate['variant_id'] ?? null) === $variantId && ($candidate['active'] ?? false) === true) { $variant = $candidate; break; }
            }
            if ($product === null || ($product['active'] ?? false) !== true || $variant === null) throw new CheckoutException('Một sản phẩm trong giỏ không còn khả dụng.');
            $price = (int) ($variant['price'] ?? 0);
            $items[] = [
                'product_id' => $productId,
                'product_name' => (string) ($product['name'] ?? ''),
                'variant_id' => $variantId,
                'size' => (string) ($variant['size'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_total' => $price * $quantity,
                'currency' => 'VND',
            ];
            $stockPlan[$productId][$variantId] = ['quantity' => $quantity, 'unit_price' => $price];
            $quantityTotal += $quantity;
        }
        return ['items' => $items, 'quantity' => $quantityTotal, 'stock_plan' => $stockPlan];
    }

    /** @param array<string, mixed> $order
     *  @return array<string, mixed>
     */
    private function finish(array $order): array
    {
        $phase = (string) ($order['meta']['checkout']['phase'] ?? '');
        if ($phase === 'failed') throw new CheckoutException((string) ($order['meta']['checkout']['failure_reason'] ?? 'Đơn hàng không thể hoàn tất.'));
        if ($phase === 'compensating') {
            $failed = $this->compensate($order);
            throw new CheckoutException((string) ($failed['meta']['checkout']['failure_reason'] ?? 'Đơn hàng không thể hoàn tất.'));
        }
        if ($phase === 'completed') return $order;

        try {
            foreach ($order['meta']['checkout']['stock_plan'] ?? [] as $productId => $variants) {
                $this->reserveStock((string) $productId, $order, $variants);
            }
            $this->updatePhase($order['_id'], 'voucher_reserving');
            $order = $this->documents->get((string) $order['_id']) ?? $order;
            $voucherCode = $order['meta']['checkout']['voucher_code'] ?? null;
            if (is_string($voucherCode) && $voucherCode !== '') $this->reserveVoucher($voucherCode, $order);
        } catch (CheckoutException $exception) {
            $this->beginCompensation($order, $exception->getMessage());
            $fresh = $this->documents->get((string) $order['_id']) ?? $order;
            $this->compensate($fresh);
            throw $exception;
        }

        $this->updatePhase($order['_id'], 'finalizing');
        $order = $this->documents->get((string) $order['_id']) ?? $order;
        $this->clearPurchasedItems($order);
        $this->updatePhase($order['_id'], 'completed');
        return $this->documents->get((string) $order['_id']) ?? $order;
    }

    /** @param array<string, mixed> $order
     *  @param array<string, array{quantity:int,unit_price:int}> $variants
     */
    private function reserveStock(string $productId, array $order, array $variants): void
    {
        $operationId = (string) $order['meta']['checkout']['operation_id'];
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $product = $this->documents->get('product:' . $productId);
            if ($product === null || ($product['active'] ?? false) !== true) throw new CheckoutException('Sản phẩm ' . $productId . ' không còn khả dụng.');
            $product['meta'] = is_array($product['meta'] ?? null) ? $product['meta'] : [];
            $product['meta']['checkout_reservations'] = is_array($product['meta']['checkout_reservations'] ?? null) ? $product['meta']['checkout_reservations'] : [];
            $markers = $product['meta']['checkout_reservations'];
            $marker = $markers[$operationId] ?? ['order_id' => $order['_id'], 'status' => 'reserved', 'variants' => []];
            if (($marker['status'] ?? null) === 'released') throw new CheckoutException('Đơn hàng đã được bù trừ và không thể tiếp tục.');
            $changed = false;
            foreach ($variants as $variantId => $plan) {
                if (isset($marker['variants'][$variantId])) {
                    if ((int) $marker['variants'][$variantId]['quantity'] !== (int) $plan['quantity']) throw new RuntimeException('Stock marker does not match the order.');
                    continue;
                }
                $found = false;
                foreach ($product['variants'] as &$variant) {
                    if (($variant['variant_id'] ?? null) !== $variantId) continue;
                    $found = true;
                    if (($variant['active'] ?? false) !== true || (int) ($variant['price'] ?? -1) !== (int) $plan['unit_price']) throw new CheckoutException('Giá hoặc trạng thái sản phẩm đã thay đổi. Hãy kiểm tra lại giỏ hàng.');
                    if ((int) ($variant['stock'] ?? 0) < (int) $plan['quantity']) throw new CheckoutException('Sản phẩm ' . (string) ($product['name'] ?? $productId) . ' không đủ tồn kho.');
                    $variant['stock'] = (int) $variant['stock'] - (int) $plan['quantity'];
                    $marker['variants'][$variantId] = ['quantity' => (int) $plan['quantity'], 'status' => 'reserved'];
                    $changed = true;
                    break;
                }
                unset($variant);
                if (!$found) throw new CheckoutException('Kích thước sản phẩm không còn khả dụng.');
            }
            if (!$changed) return;
            $product['meta']['checkout_reservations'][$operationId] = $marker;
            $response = $this->documents->put($product);
            if ($response->statusCode === 409) continue;
            $this->assertWriteSucceeded($response, 'reserve stock');
            return;
        }
        throw new RuntimeException('Product stock changed repeatedly during checkout.');
    }

    private function reserveVoucher(string $code, array $order): void
    {
        $operationId = (string) $order['meta']['checkout']['operation_id'];
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $voucher = $this->documents->get('voucher:' . $code);
            if ($voucher === null) throw new CheckoutException('Mã giảm giá vừa hết lượt sử dụng.');
            $voucher['meta'] = is_array($voucher['meta'] ?? null) ? $voucher['meta'] : [];
            $voucher['meta']['checkout_redemptions'] = is_array($voucher['meta']['checkout_redemptions'] ?? null) ? $voucher['meta']['checkout_redemptions'] : [];
            $marker = $voucher['meta']['checkout_redemptions'][$operationId] ?? null;
            if (is_array($marker) && ($marker['status'] ?? null) === 'reserved') return;
            if (is_array($marker) && ($marker['status'] ?? null) === 'released') throw new CheckoutException('Lượt dùng mã này đã được hoàn lại; vui lòng tạo yêu cầu đặt hàng mới.');
            if (($voucher['active'] ?? false) !== true || (int) ($voucher['remaining_quantity'] ?? 0) < 1) throw new CheckoutException('Mã giảm giá vừa hết lượt sử dụng.');
            $voucher['remaining_quantity'] = (int) $voucher['remaining_quantity'] - 1;
            $voucher['meta']['checkout_redemptions'][$operationId] = ['order_id' => $order['_id'], 'status' => 'reserved'];
            $response = $this->documents->put($voucher);
            if ($response->statusCode === 409) continue;
            $this->assertWriteSucceeded($response, 'reserve voucher');
            return;
        }
        throw new RuntimeException('Voucher changed repeatedly during checkout.');
    }

    /** @param array<string, mixed> $order */
    private function beginCompensation(array $order, string $reason): void
    {
        $this->updateOrder($order['_id'], static function (array $fresh) use ($reason): array {
            $fresh['meta']['checkout']['phase'] = 'compensating';
            $fresh['meta']['checkout']['failure_reason'] = $reason;
            return $fresh;
        });
    }

    /** @param array<string, mixed> $order
     *  @return array<string, mixed>
     */
    private function compensate(array $order): array
    {
        $operationId = (string) $order['meta']['checkout']['operation_id'];
        foreach ($order['meta']['checkout']['stock_plan'] ?? [] as $productId => $variants) {
            $this->releaseStock((string) $productId, $operationId, $order['_id']);
        }
        $voucherCode = $order['meta']['checkout']['voucher_code'] ?? null;
        if (is_string($voucherCode) && $voucherCode !== '') $this->releaseVoucher($voucherCode, $operationId, $order['_id']);
        $this->updatePhase($order['_id'], 'failed');
        return $this->documents->get((string) $order['_id']) ?? $order;
    }

    private function releaseStock(string $productId, string $operationId, string $orderId): void
    {
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $product = $this->documents->get('product:' . $productId);
            if ($product === null) throw new RuntimeException('Product disappeared while compensating checkout.');
            $marker = $product['meta']['checkout_reservations'][$operationId] ?? null;
            if (!is_array($marker) || ($marker['status'] ?? null) === 'released') return;
            foreach ($marker['variants'] ?? [] as $variantId => $entry) {
                if (($entry['status'] ?? null) !== 'reserved') continue;
                foreach ($product['variants'] as &$variant) {
                    if (($variant['variant_id'] ?? null) === $variantId) {
                        $variant['stock'] = (int) ($variant['stock'] ?? 0) + (int) $entry['quantity'];
                        $marker['variants'][$variantId]['status'] = 'released';
                        break;
                    }
                }
                unset($variant);
            }
            $marker['status'] = 'released';
            $product['meta']['checkout_reservations'][$operationId] = $marker;
            $response = $this->documents->put($product);
            if ($response->statusCode === 409) continue;
            $this->assertWriteSucceeded($response, 'release stock');
            return;
        }
        throw new RuntimeException('Product stock changed repeatedly while compensating checkout.');
    }

    private function releaseVoucher(string $code, string $operationId, string $orderId): void
    {
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $voucher = $this->documents->get('voucher:' . $code);
            if ($voucher === null) throw new RuntimeException('Voucher disappeared while compensating checkout.');
            $marker = $voucher['meta']['checkout_redemptions'][$operationId] ?? null;
            if (!is_array($marker) || ($marker['status'] ?? null) === 'released') return;
            $voucher['remaining_quantity'] = (int) ($voucher['remaining_quantity'] ?? 0) + 1;
            $voucher['meta']['checkout_redemptions'][$operationId]['status'] = 'released';
            $response = $this->documents->put($voucher);
            if ($response->statusCode === 409) continue;
            $this->assertWriteSucceeded($response, 'release voucher');
            return;
        }
        throw new RuntimeException('Voucher changed repeatedly while compensating checkout.');
    }

    /** @param array<string, mixed> $order */
    private function clearPurchasedItems(array $order): void
    {
        if ($this->cart === null) throw new RuntimeException('This recovery requires the customer session to clear its cart.');
        $purchased = [];
        foreach ($order['items'] as $item) $purchased[(string) $item['variant_id']] = (int) $item['quantity'];
        $this->cart->removePurchasedForOrder((string) $order['_id'], $purchased);
    }

    private function updatePhase(string $orderId, string $phase): void
    {
        $this->updateOrder($orderId, static function (array $order) use ($phase): array {
            $order['meta']['checkout']['phase'] = $phase;
            $order['meta']['checkout']['updated_at'] = gmdate('c');
            return $order;
        });
    }

    private function updateOrder(string $orderId, callable $change): array
    {
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $order = $this->documents->get((string) $orderId);
            if ($order === null) throw new RuntimeException('Checkout journal disappeared.');
            $updated = $change($order);
            $response = $this->documents->put($updated);
            if ($response->statusCode === 409) continue;
            $this->assertWriteSucceeded($response, 'update checkout journal');
            return $updated;
        }
        throw new RuntimeException('Checkout journal changed repeatedly.');
    }

    private function validateReceiver(array $receiver): array
    {
        $name = trim($receiver['name']);
        $phone = trim($receiver['phone']);
        $email = strtolower(trim($receiver['email']));
        $address = trim($receiver['address']);
        $note = trim($receiver['note']);
        if ($name === '' || mb_strlen($name) > 100) throw new CheckoutException('Vui lòng nhập họ tên người nhận (tối đa 100 ký tự).');
        if (!preg_match('/^0\d{9}$/', $phone)) throw new CheckoutException('Số điện thoại cần có 10 chữ số và bắt đầu bằng số 0.');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) throw new CheckoutException('Email nhận hàng không hợp lệ.');
        if ($address === '' || mb_strlen($address) > 500) throw new CheckoutException('Vui lòng nhập địa chỉ giao hàng (tối đa 500 ký tự).');
        if (mb_strlen($note) > 500) throw new CheckoutException('Ghi chú không được vượt quá 500 ký tự.');
        return ['name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address, 'note' => $note];
    }

    private function assertWriteSucceeded(CouchDbResponse $response, string $operation): void
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('CouchDB could not ' . $operation . ' (' . $response->statusCode . ').');
    }
}
