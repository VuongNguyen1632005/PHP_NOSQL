<?php
declare(strict_types=1);

namespace App\Orders;

use App\Checkout\CheckoutRepository;
use App\Infrastructure\CouchDB\CouchDbResponse;
use RuntimeException;

final class OrderWorkflowService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['packing', 'cancelled'],
        'packing' => ['shipping', 'cancelled'],
        'shipping' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    /** @var array<string, list<string>> */
    private const DELIVERY_TRANSITIONS = [
        'created' => ['picked_up', 'failed_delivery'],
        'picked_up' => ['in_transit', 'failed_delivery'],
        'in_transit' => ['out_for_delivery', 'failed_delivery'],
        'out_for_delivery' => ['delivered', 'failed_delivery'],
        'failed_delivery' => ['in_transit', 'out_for_delivery'],
        'delivered' => [],
    ];

    public function __construct(private readonly CheckoutRepository $documents)
    {
    }

    /** @return list<string> */
    public function availableTransitions(array $order): array
    {
        $status = (string) ($order['status'] ?? '');
        $targets = self::TRANSITIONS[$status] ?? [];
        if ($status === 'shipping' && is_array($order['delivery_tracking'] ?? null)
            && ($order['delivery_tracking']['status'] ?? null) !== 'delivered') {
            $targets = array_values(array_diff($targets, ['delivered']));
        }
        if (($order['payment']['paid'] ?? false) === true || ($order['payment']['status'] ?? null) === 'paid') $targets = array_values(array_diff($targets, ['cancelled']));
        if (($order['meta']['order_transition']['phase'] ?? null) === 'cancelling') $targets = ['cancelled'];
        return $targets;
    }

    /** @return list<string> */
    public function availableDeliveryTransitions(array $order): array
    {
        if (($order['status'] ?? null) !== 'shipping') return [];
        $tracking = $order['delivery_tracking'] ?? null;
        if (!is_array($tracking)) return ['created'];
        return self::DELIVERY_TRANSITIONS[(string) ($tracking['status'] ?? '')] ?? [];
    }

    /** @return array<string, mixed> */
    public function transition(string $orderId, string $target, string $staffId): array
    {
        if ($staffId === '' || !in_array($target, ['confirmed', 'packing', 'shipping', 'delivered', 'cancelled'], true)) {
            throw new OrderWorkflowException('Yêu cầu cập nhật trạng thái không hợp lệ.');
        }
        $order = $this->documents->get($orderId);
        if ($order === null || ($order['type'] ?? null) !== 'order') throw new OrderWorkflowException('Không tìm thấy đơn hàng.');
        if (isset($order['meta']['checkout']['phase']) && $order['meta']['checkout']['phase'] !== 'completed') {
            throw new OrderWorkflowException('Checkout chưa hoàn tất; đơn này chưa thể được xử lý.');
        }
        if (($order['status'] ?? null) === $target
            && ($target !== 'cancelled' || ($order['meta']['order_transition']['phase'] ?? null) !== 'cancelling')) return $order;

        if ($target === 'cancelled') return $this->cancel($order, $staffId);

        $updated = $this->updateOrder($orderId, function (array $fresh) use ($target, $staffId): array {
            if (isset($fresh['meta']['checkout']['phase']) && $fresh['meta']['checkout']['phase'] !== 'completed') {
                throw new OrderWorkflowException('Checkout chưa hoàn tất; đơn này chưa thể được xử lý.');
            }
            if (($fresh['meta']['order_transition']['phase'] ?? null) === 'cancelling') {
                throw new OrderWorkflowException('Đơn đang trong quy trình hủy; hãy phục hồi thao tác hủy trước.');
            }
            if (($fresh['status'] ?? null) === $target) {
                if ($target === 'shipping' && !is_array($fresh['delivery_tracking'] ?? null)) {
                    return $this->initializeDeliveryTracking($fresh, $staffId);
                }
                return $fresh;
            }
            if (!in_array($target, self::TRANSITIONS[(string) ($fresh['status'] ?? '')] ?? [], true)) {
                throw new OrderWorkflowException('Không thể chuyển đơn từ trạng thái hiện tại sang trạng thái được chọn.');
            }
            if ($target === 'delivered' && is_array($fresh['delivery_tracking'] ?? null)
                && ($fresh['delivery_tracking']['status'] ?? null) !== 'delivered') {
                throw new OrderWorkflowException('Hãy hoàn tất tiến trình giao hàng trước khi đóng đơn đã theo dõi.');
            }
            $fresh['status'] = $target;
            $fresh['status_history'] = is_array($fresh['status_history'] ?? null) ? $fresh['status_history'] : [];
            $fresh['status_history'][] = ['status' => $target, 'at' => gmdate('c'), 'source' => 'php_admin', 'staff_id' => $staffId];
            if ($target === 'shipping' && !is_array($fresh['delivery_tracking'] ?? null)) {
                $fresh = $this->initializeDeliveryTracking($fresh, $staffId);
            }
            return $fresh;
        });
        return $this->documents->get((string) $updated['_id']) ?? $updated;
    }

    /** @return array<string, mixed> */
    public function updateDeliveryStatus(string $orderId, string $target, string $staffId, string $note = ''): array
    {
        $note = trim($note);
        if ($orderId === '' || $staffId === '' || !in_array($target, array_keys(self::DELIVERY_TRANSITIONS), true)
            || mb_strlen($note) > 500) {
            throw new OrderWorkflowException('Yêu cầu cập nhật giao hàng không hợp lệ.');
        }
        $order = $this->documents->get($orderId);
        if ($order === null || ($order['type'] ?? null) !== 'order'
            || (isset($order['meta']['checkout']['phase']) && $order['meta']['checkout']['phase'] !== 'completed')) {
            throw new OrderWorkflowException('Không tìm thấy đơn hàng đã hoàn tất checkout.');
        }
        if (($order['status'] ?? null) === 'delivered' && $target === 'delivered'
            && ($order['delivery_tracking']['status'] ?? null) === 'delivered') return $order;
        if (($order['status'] ?? null) !== 'shipping') {
            throw new OrderWorkflowException('Chỉ có thể cập nhật giao hàng khi đơn đang ở trạng thái đang giao.');
        }

        if (!is_array($order['delivery_tracking'] ?? null)) {
            if ($target !== 'created') {
                throw new OrderWorkflowException('Hãy tạo thông tin giao hàng trước khi cập nhật tiến trình.');
            }
            $updated = $this->updateOrder($orderId, function (array $fresh) use ($staffId): array {
                if (($fresh['status'] ?? null) !== 'shipping') throw new OrderWorkflowException('Đơn không còn ở trạng thái đang giao.');
                if (is_array($fresh['delivery_tracking'] ?? null)) return $fresh;
                return $this->initializeDeliveryTracking($fresh, $staffId);
            });
            return $this->documents->get((string) $updated['_id']) ?? $updated;
        }

        $updated = $this->updateOrder($orderId, function (array $fresh) use ($target, $staffId, $note): array {
            if (($fresh['status'] ?? null) !== 'shipping') {
                throw new OrderWorkflowException('Chỉ có thể cập nhật giao hàng khi đơn đang ở trạng thái đang giao.');
            }
            $tracking = is_array($fresh['delivery_tracking'] ?? null) ? $fresh['delivery_tracking'] : null;
            if ($tracking === null) throw new OrderWorkflowException('Hãy tạo thông tin giao hàng trước khi cập nhật tiến trình.');
            $current = (string) ($tracking['status'] ?? '');
            if ($current === $target) return $fresh;
            if (!in_array($target, self::DELIVERY_TRANSITIONS[$current] ?? [], true)) {
                throw new OrderWorkflowException('Không thể chuyển giao hàng từ trạng thái hiện tại sang trạng thái được chọn.');
            }

            $now = gmdate('c');
            $tracking['status'] = $target;
            $tracking['delivered_at'] = $target === 'delivered' ? $now : null;
            $tracking['history'] = is_array($tracking['history'] ?? null) ? $tracking['history'] : [];
            $tracking['history'][] = [
                'status' => $target,
                'at' => $now,
                'note' => $note !== '' ? $note : $this->defaultDeliveryNote($target),
                'updated_by' => $staffId,
            ];
            $fresh['delivery_tracking'] = $tracking;
            if ($target === 'delivered') {
                $fresh['status'] = 'delivered';
                $fresh['status_history'] = is_array($fresh['status_history'] ?? null) ? $fresh['status_history'] : [];
                $fresh['status_history'][] = ['status' => 'delivered', 'at' => $now, 'source' => 'php_delivery_tracking', 'staff_id' => $staffId];
            }
            return $fresh;
        });
        return $this->documents->get((string) $updated['_id']) ?? $updated;
    }

    /** @return array<string, mixed> */
    public function confirmReceived(string $orderId, string $customerId): array
    {
        if ($orderId === '' || $customerId === '') throw new OrderWorkflowException('Yêu cầu xác nhận đơn hàng không hợp lệ.');

        $updated = $this->updateOrder($orderId, function (array $fresh) use ($customerId): array {
            if (($fresh['type'] ?? null) !== 'order'
                || (string) ($fresh['customer']['customer_id'] ?? '') !== $customerId) {
                throw new OrderWorkflowException('Không tìm thấy đơn hàng.');
            }
            if (isset($fresh['meta']['checkout']['phase']) && $fresh['meta']['checkout']['phase'] !== 'completed') {
                throw new OrderWorkflowException('Checkout chưa hoàn tất; đơn này chưa thể được xác nhận.');
            }
            $payment = is_array($fresh['payment'] ?? null) ? $fresh['payment'] : [];
            if (($fresh['status'] ?? null) === 'delivered') return $fresh;
            if (($fresh['status'] ?? null) !== 'shipping') {
                throw new OrderWorkflowException('Chỉ có thể xác nhận đơn đang được giao.');
            }

            $now = gmdate('c');
            $fresh['status'] = 'delivered';
            $fresh['status_history'] = is_array($fresh['status_history'] ?? null) ? $fresh['status_history'] : [];
            $fresh['status_history'][] = [
                'status' => 'delivered',
                'at' => $now,
                'source' => 'php_customer_confirm_received',
                'customer_id' => $customerId,
            ];
            $this->completeDeliveryFromReceipt($fresh, $now, $customerId, 'Khách xác nhận đã nhận hàng', 'php_customer_confirm_received');
            if (($payment['method'] ?? null) === 'cod') {
                $payment['paid'] = true;
                $payment['status'] = 'paid';
                $payment['paid_at'] ??= $now;
                $fresh['payment'] = $payment;
                $fresh['payment_history'] = is_array($fresh['payment_history'] ?? null) ? $fresh['payment_history'] : [];
                $fresh['payment_history'][] = [
                    'status' => 'paid',
                    'at' => $now,
                    'source' => 'php_customer_confirm_received',
                    'customer_id' => $customerId,
                ];
            }
            return $fresh;
        });

        return $this->documents->get((string) $updated['_id']) ?? $updated;
    }

    /** @return array<string, mixed> */
    public function confirmGuestReceived(string $orderId): array
    {
        if ($orderId === '') throw new OrderWorkflowException('Yêu cầu xác nhận đơn hàng không hợp lệ.');
        $updated = $this->updateOrder($orderId, function (array $fresh): array {
            if (($fresh['type'] ?? null) !== 'order' || ($fresh['guest_order'] ?? false) !== true
                || ($fresh['customer'] ?? null) !== null) {
                throw new OrderWorkflowException('Không tìm thấy đơn hàng khách vãng lai.');
            }
            if (isset($fresh['meta']['checkout']['phase']) && $fresh['meta']['checkout']['phase'] !== 'completed') {
                throw new OrderWorkflowException('Checkout chưa hoàn tất; đơn này chưa thể được xác nhận.');
            }
            $payment = is_array($fresh['payment'] ?? null) ? $fresh['payment'] : [];
            if (($fresh['status'] ?? null) === 'delivered') return $fresh;
            if (($fresh['status'] ?? null) !== 'shipping') {
                throw new OrderWorkflowException('Chỉ có thể xác nhận đơn đang được giao.');
            }
            $now = gmdate('c');
            $fresh['status'] = 'delivered';
            $fresh['status_history'] = is_array($fresh['status_history'] ?? null) ? $fresh['status_history'] : [];
            $fresh['status_history'][] = ['status' => 'delivered', 'at' => $now, 'source' => 'php_guest_confirm_received'];
            $this->completeDeliveryFromReceipt($fresh, $now, 'guest', 'Khách xác nhận đã nhận hàng', 'php_guest_confirm_received');
            if (($payment['method'] ?? null) === 'cod') {
                $payment['paid'] = true;
                $payment['status'] = 'paid';
                $payment['paid_at'] ??= $now;
                $fresh['payment'] = $payment;
                $fresh['payment_history'] = is_array($fresh['payment_history'] ?? null) ? $fresh['payment_history'] : [];
                $fresh['payment_history'][] = ['status' => 'paid', 'at' => $now, 'source' => 'php_guest_confirm_received'];
            }
            return $fresh;
        });
        return $this->documents->get((string) $updated['_id']) ?? $updated;
    }

    /** @return array<string, mixed> */
    public function recordCodCollected(string $orderId, string $staffId): array
    {
        return $this->recordPaymentVerifiedForMethods($orderId, $staffId, ['cod'], true, 'php_admin_cod_collection');
    }

    /** @return array<string, mixed> */
    public function verifyManualPayment(string $orderId, string $staffId): array
    {
        return $this->recordPaymentVerifiedForMethods($orderId, $staffId, ['bank_transfer', 'card'], false, 'php_admin_payment_verification');
    }

    /** @param list<string> $allowedMethods
     *  @return array<string, mixed>
     */
    private function recordPaymentVerifiedForMethods(string $orderId, string $staffId, array $allowedMethods, bool $deliveredRequired, string $source): array
    {
        if ($orderId === '' || $staffId === '') throw new OrderWorkflowException('Yêu cầu xác minh thanh toán không hợp lệ.');
        $updated = $this->updateOrder($orderId, function (array $fresh) use ($staffId, $allowedMethods, $deliveredRequired, $source): array {
            if (($fresh['type'] ?? null) !== 'order'
                || (isset($fresh['meta']['checkout']['phase']) && $fresh['meta']['checkout']['phase'] !== 'completed')) {
                throw new OrderWorkflowException('Không tìm thấy đơn hàng đã hoàn tất checkout.');
            }
            $method = (string) ($fresh['payment']['method'] ?? '');
            if (!in_array($method, $allowedMethods, true)) throw new OrderWorkflowException('Phương thức thanh toán của đơn không phù hợp với thao tác này.');
            if (($fresh['status'] ?? null) === 'cancelled') throw new OrderWorkflowException('Không thể ghi nhận thanh toán cho đơn đã hủy.');
            if ($deliveredRequired && ($fresh['status'] ?? null) !== 'delivered') {
                throw new OrderWorkflowException('Chỉ ghi nhận COD đã thu cho đơn COD đã giao thành công.');
            }
            $payment = is_array($fresh['payment'] ?? null) ? $fresh['payment'] : [];
            if (($payment['paid'] ?? false) === true || ($payment['status'] ?? null) === 'paid') return $fresh;
            $now = gmdate('c');
            $payment['paid'] = true;
            $payment['status'] = 'paid';
            $payment['paid_at'] = $now;
            $fresh['payment'] = $payment;
            $fresh['payment_history'] = is_array($fresh['payment_history'] ?? null) ? $fresh['payment_history'] : [];
            $fresh['payment_history'][] = [
                'status' => 'paid',
                'at' => $now,
                'source' => $source,
                'staff_id' => $staffId,
                'method' => $method,
                'verification' => 'manual',
            ];
            return $fresh;
        });
        return $this->documents->get((string) $updated['_id']) ?? $updated;
    }

    /** @return array<string, mixed> */
    private function cancel(array $order, string $staffId): array
    {
        $orderId = (string) $order['_id'];
        $journal = $this->updateOrder($orderId, function (array $fresh) use ($staffId): array {
            $transition = $fresh['meta']['order_transition'] ?? null;
            if (is_array($transition) && ($transition['phase'] ?? null) === 'cancelling' && ($transition['to'] ?? null) === 'cancelled') return $fresh;
            if (($fresh['status'] ?? null) === 'cancelled' && ($transition['phase'] ?? null) !== 'cancelling') return $fresh;
            if (($fresh['payment']['paid'] ?? false) === true || ($fresh['payment']['status'] ?? null) === 'paid') throw new OrderWorkflowException('Đơn đã thanh toán; cần quy trình hoàn tiền riêng trước khi hủy.');
            if (!in_array('cancelled', self::TRANSITIONS[(string) ($fresh['status'] ?? '')] ?? [], true)) {
                throw new OrderWorkflowException('Không thể hủy đơn sau khi bắt đầu giao hàng hoặc khi đơn đã kết thúc.');
            }
            $fresh['meta'] = is_array($fresh['meta'] ?? null) ? $fresh['meta'] : [];
            $fresh['meta']['order_transition'] = [
                'from' => (string) $fresh['status'],
                'to' => 'cancelled',
                'phase' => 'cancelling',
                'staff_id' => $staffId,
                'started_at' => gmdate('c'),
            ];
            return $fresh;
        });
        if (($journal['status'] ?? null) === 'cancelled' && ($journal['meta']['order_transition']['phase'] ?? null) !== 'cancelling') return $this->documents->get($orderId) ?? $journal;

        $itemsByProduct = $this->itemsByProduct($journal['items'] ?? []);
        $operationId = $journal['meta']['checkout']['operation_id'] ?? null;
        foreach ($itemsByProduct as $productId => $variants) {
            $this->releaseProductForCancellation((string) $productId, $orderId, is_string($operationId) ? $operationId : null, $variants);
        }
        $voucherCode = $journal['discount']['code'] ?? null;
        if (is_string($voucherCode) && trim($voucherCode) !== '') {
            $this->releaseVoucherForCancellation(trim($voucherCode), $orderId, is_string($operationId) ? $operationId : null);
        }

        $this->updateOrder($orderId, static function (array $fresh): array {
            $transition = $fresh['meta']['order_transition'] ?? [];
            if (($fresh['status'] ?? null) !== 'cancelled') {
                $fresh['status'] = 'cancelled';
                $fresh['status_history'] = is_array($fresh['status_history'] ?? null) ? $fresh['status_history'] : [];
                $fresh['status_history'][] = ['status' => 'cancelled', 'at' => gmdate('c'), 'source' => 'php_admin', 'staff_id' => (string) ($transition['staff_id'] ?? '')];
            }
            $fresh['meta']['order_transition']['phase'] = 'completed';
            $fresh['meta']['order_transition']['completed_at'] = gmdate('c');
            return $fresh;
        });
        return $this->documents->get($orderId) ?? $journal;
    }

    /** @param mixed $items
     *  @return array<string, array<string, int>>
     */
    private function itemsByProduct(mixed $items): array
    {
        if (!is_array($items) || $items === []) throw new OrderWorkflowException('Đơn hàng không có dòng sản phẩm hợp lệ.');
        $grouped = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new OrderWorkflowException('Chi tiết đơn hàng không hợp lệ.');
            $productId = (string) ($item['product_id'] ?? '');
            $variantId = (string) ($item['variant_id'] ?? '');
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($productId === '' || $variantId === '' || $quantity < 1) throw new OrderWorkflowException('Chi tiết tồn kho của đơn hàng không hợp lệ.');
            $grouped[$productId][$variantId] = ($grouped[$productId][$variantId] ?? 0) + $quantity;
        }
        return $grouped;
    }

    /** @param array<string, int> $variants */
    private function releaseProductForCancellation(string $productId, string $orderId, ?string $operationId, array $variants): void
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $product = $this->documents->get('product:' . $productId);
            if ($product === null) throw new OrderWorkflowException('Không tìm thấy sản phẩm để hoàn tồn kho cho đơn ' . $orderId . '.');
            $product['meta'] = is_array($product['meta'] ?? null) ? $product['meta'] : [];
            $product['meta']['order_cancellations'] = is_array($product['meta']['order_cancellations'] ?? null) ? $product['meta']['order_cancellations'] : [];
            $cancelMarker = $product['meta']['order_cancellations'][$orderId] ?? null;
            if (is_array($cancelMarker) && ($cancelMarker['status'] ?? null) === 'released') return;
            $reservations = is_array($product['meta']['checkout_reservations'] ?? null) ? $product['meta']['checkout_reservations'] : [];
            $reservation = $operationId === null ? null : ($reservations[$operationId] ?? null);
            $fallback = !is_array($reservation);
            if (is_array($reservation) && ($reservation['status'] ?? null) === 'released') $fallback = false;
            foreach ($variants as $variantId => $quantity) {
                $releaseQuantity = $quantity;
                if (is_array($reservation) && ($reservation['status'] ?? null) !== 'released') {
                    $entry = $reservation['variants'][$variantId] ?? null;
                    if (!is_array($entry)) throw new OrderWorkflowException('Thiếu marker giữ tồn kho cho đơn ' . $orderId . '.');
                    if (($entry['status'] ?? null) === 'released') continue;
                    if ((int) ($entry['quantity'] ?? 0) !== $quantity) throw new OrderWorkflowException('Marker tồn kho không khớp với chi tiết đơn.');
                    $releaseQuantity = (int) $entry['quantity'];
                } elseif (is_array($reservation) && ($reservation['status'] ?? null) === 'released') {
                    continue;
                } elseif (!$fallback) {
                    throw new OrderWorkflowException('Không thể xác định reservation tồn kho cần hoàn lại.');
                }
                $found = false;
                if (!is_array($product['variants'] ?? null)) throw new OrderWorkflowException('Sản phẩm không có danh sách biến thể hợp lệ.');
                foreach ($product['variants'] as &$variant) {
                    if (($variant['variant_id'] ?? null) !== $variantId) continue;
                    $variant['stock'] = (int) ($variant['stock'] ?? 0) + $releaseQuantity;
                    $found = true;
                    break;
                }
                unset($variant);
                if (!$found) throw new OrderWorkflowException('Không tìm thấy biến thể để hoàn tồn kho: ' . $variantId . '.');
                if (is_array($reservation)) $reservation['variants'][$variantId]['status'] = 'released';
            }
            if (is_array($reservation)) {
                $reservation['status'] = 'released';
                $product['meta']['checkout_reservations'][$operationId] = $reservation;
            }
            $product['meta']['order_cancellations'][$orderId] = ['status' => 'released', 'at' => gmdate('c')];
            $response = $this->documents->put($product);
            if ($response->statusCode === 409) continue;
            $this->assertWrite($response, 'hoàn tồn kho');
            return;
        }
        throw new RuntimeException('Product changed repeatedly while cancelling order.');
    }

    private function releaseVoucherForCancellation(string $code, string $orderId, ?string $operationId): void
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $voucher = $this->documents->get('voucher:' . $code);
            if ($voucher === null) throw new OrderWorkflowException('Không tìm thấy voucher cần hoàn lại lượt sử dụng: ' . $code . '.');
            $voucher['meta'] = is_array($voucher['meta'] ?? null) ? $voucher['meta'] : [];
            $voucher['meta']['order_cancellations'] = is_array($voucher['meta']['order_cancellations'] ?? null) ? $voucher['meta']['order_cancellations'] : [];
            $cancelMarker = $voucher['meta']['order_cancellations'][$orderId] ?? null;
            if (is_array($cancelMarker) && ($cancelMarker['status'] ?? null) === 'released') return;
            $voucher['meta']['checkout_redemptions'] = is_array($voucher['meta']['checkout_redemptions'] ?? null) ? $voucher['meta']['checkout_redemptions'] : [];
            $redemption = $operationId === null ? null : ($voucher['meta']['checkout_redemptions'][$operationId] ?? null);
            if (is_array($redemption) && ($redemption['status'] ?? null) === 'reserved') {
                $voucher['remaining_quantity'] = (int) ($voucher['remaining_quantity'] ?? 0) + 1;
                $voucher['meta']['checkout_redemptions'][$operationId]['status'] = 'released';
            } elseif (!is_array($redemption)) {
                $voucher['remaining_quantity'] = (int) ($voucher['remaining_quantity'] ?? 0) + 1;
            }
            $voucher['meta']['order_cancellations'][$orderId] = ['status' => 'released', 'at' => gmdate('c')];
            $response = $this->documents->put($voucher);
            if ($response->statusCode === 409) continue;
            $this->assertWrite($response, 'hoàn voucher');
            return;
        }
        throw new RuntimeException('Voucher changed repeatedly while cancelling order.');
    }

    /** @param array<string, mixed> $order
     *  @return array<string, mixed>
     */
    private function initializeDeliveryTracking(array $order, string $staffId): array
    {
        if (($order['status'] ?? null) !== 'shipping') throw new OrderWorkflowException('Chỉ có thể tạo thông tin giao hàng cho đơn đang giao.');
        $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
        $legacyCode = trim((string) ($shipping['tracking_code'] ?? ''));
        $code = $legacyCode !== '' && strlen($legacyCode) <= 64 && !$this->documents->trackingCodeExists($legacyCode, (string) ($order['_id'] ?? ''))
            ? $legacyCode
            : $this->newTrackingCode((string) ($order['_id'] ?? ''));
        $estimatedDate = $this->validCalendarDate($shipping['estimated_delivery_date'] ?? null)
            ? (string) $shipping['estimated_delivery_date']
            : gmdate('Y-m-d', strtotime('+3 days'));
        unset($shipping['tracking_code'], $shipping['estimated_delivery_date']);
        $order['shipping'] = $shipping;
        if (trim((string) ($order['assigned_staff_id'] ?? '')) === '') $order['assigned_staff_id'] = $staffId;
        $createdAt = gmdate('c');
        $order['delivery_tracking'] = [
            'tracking_code' => $code,
            'estimated_delivery_date' => $estimatedDate,
            'status' => 'created',
            'delivered_at' => null,
            'history' => [[
                'status' => 'created',
                'at' => $createdAt,
                'note' => $this->defaultDeliveryNote('created'),
                'updated_by' => $staffId,
            ]],
        ];
        return $order;
    }

    private function newTrackingCode(string $orderId): string
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $code = 'DEL-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
            if (!$this->documents->trackingCodeExists($code, $orderId)) return $code;
        }
        throw new RuntimeException('Could not allocate a unique delivery tracking code.');
    }

    private function validCalendarDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function defaultDeliveryNote(string $status): string
    {
        return match ($status) {
            'created' => 'Đã tạo thông tin giao hàng',
            'picked_up' => 'Đã nhận hàng từ kho',
            'in_transit' => 'Đơn hàng đang được vận chuyển',
            'out_for_delivery' => 'Đơn hàng đang được giao tới khách',
            'delivered' => 'Đơn hàng đã giao thành công',
            'failed_delivery' => 'Giao hàng không thành công',
            default => 'Cập nhật giao hàng',
        };
    }

    /** @param array<string, mixed> $order */
    private function completeDeliveryFromReceipt(array &$order, string $at, string $updatedBy, string $note, string $source): void
    {
        if (!is_array($order['delivery_tracking'] ?? null)) return;
        $tracking = $order['delivery_tracking'];
        if (($tracking['status'] ?? null) === 'delivered') return;
        $tracking['status'] = 'delivered';
        $tracking['delivered_at'] = $at;
        $tracking['history'] = is_array($tracking['history'] ?? null) ? $tracking['history'] : [];
        $tracking['history'][] = ['status' => 'delivered', 'at' => $at, 'note' => $note, 'updated_by' => $updatedBy, 'source' => $source];
        $order['delivery_tracking'] = $tracking;
    }

    private function updateOrder(string $orderId, callable $change): array
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $order = $this->documents->get($orderId);
            if ($order === null) throw new OrderWorkflowException('Đơn hàng không còn tồn tại.');
            $updated = $change($order);
            if ($updated === $order) return $order;
            $response = $this->documents->put($updated);
            if ($response->statusCode === 409) continue;
            $this->assertWrite($response, 'cập nhật đơn hàng');
            return $updated;
        }
        throw new RuntimeException('Order changed repeatedly during status transition.');
    }

    private function assertWrite(CouchDbResponse $response, string $operation): void
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Không thể ' . $operation . ' (' . $response->statusCode . ').');
    }
}
