<?php
declare(strict_types=1);
ob_start();
$statusLabels = ['pending' => 'Chờ xác nhận', 'confirmed' => 'Đã xác nhận', 'packing' => 'Đang đóng gói', 'processing' => 'Đang xử lý', 'shipping' => 'Đang giao', 'delivered' => 'Đã giao', 'cancelled' => 'Đã hủy', 'returned' => 'Đã hoàn trả'];
$deliveryLabels = ['created' => 'Đã tạo vận đơn', 'picked_up' => 'Đã lấy hàng', 'in_transit' => 'Đang vận chuyển', 'out_for_delivery' => 'Đang giao tới khách', 'delivered' => 'Giao thành công', 'failed_delivery' => 'Giao hàng thất bại'];
$isGuest = ($isGuest ?? false) === true;
$ordersPath = $isGuest ? '/orders' : '/account/orders';
?>
<div class="container breadcrumb-line"><a href="/">Trang chủ</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span><?= $isGuest ? 'Đơn đã đặt' : 'Đơn hàng của tôi' ?></span></div>
<section class="container orders-section">
    <div class="section-heading"><div><span class="eyebrow"><?= $isGuest ? 'KHÁCH VÃNG LAI' : 'TÀI KHOẢN' ?></span><h1><?= $isGuest ? 'Đơn đã đặt trên trình duyệt này' : 'Đơn hàng của tôi' ?></h1><p class="muted mb-0"><?= $isGuest ? 'Lịch sử được lưu trong phiên trình duyệt hiện tại.' : 'Theo dõi các đơn hàng được đặt bằng tài khoản này.' ?></p></div><a class="text-link" href="/">Tiếp tục mua sắm <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div>
    <?php if (is_array($flash ?? null)): ?><div class="alert alert-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'danger' ?>" role="status"><?= h($flash['message'] ?? '') ?></div><?php endif; ?>
    <?php if ($orders === []): ?>
        <div class="cart-empty"><i class="fa-solid fa-box-open" aria-hidden="true"></i><h2>Bạn chưa có đơn hàng nào</h2><p>Các đơn hàng sau khi đặt sẽ xuất hiện tại đây.</p><a class="btn btn-dark rounded-pill px-4" href="/">Khám phá sản phẩm</a></div>
    <?php else: ?>
        <div class="order-list">
            <?php foreach ($orders as $order):
                $status = (string) ($order['status'] ?? 'pending');
                $delivery = is_array($order['delivery_tracking'] ?? null) ? $order['delivery_tracking'] : null;
                $deliveryStatus = (string) ($delivery['status'] ?? '');
                $paymentPaid = !empty($order['payment']['paid']) || ($order['payment']['status'] ?? null) === 'paid';
            ?>
                <article class="order-card customer-order-card">
                    <div class="order-card-heading"><div><span class="eyebrow">MÃ ĐƠN</span><h2><?= h($order['legacy_id'] ?? $order['_id'] ?? '') ?></h2></div><span class="order-status order-status-<?= h($status) ?>"><?= h($statusLabels[$status] ?? 'Đang cập nhật') ?></span></div>
                    <div class="order-card-meta"><span>Ngày đặt: <?= h(!empty($order['ordered_at']) ? date('d/m/Y H:i', strtotime((string) $order['ordered_at'])) : '—') ?></span><span><?= (int) ($order['totals']['total_quantity'] ?? 0) ?> sản phẩm</span><strong><?= money($order['totals']['grand_total'] ?? 0) ?></strong></div>
                    <div class="order-card-badges"><span class="order-status payment-status-<?= $paymentPaid ? 'paid' : 'unpaid' ?>">Thanh toán: <?= $paymentPaid ? 'Đã thanh toán' : 'Chưa thanh toán' ?></span>
                        <?php if ($delivery !== null && $deliveryStatus !== ''): ?><span class="order-status delivery-status-<?= h($deliveryStatus) ?>">Giao hàng: <?= h($deliveryLabels[$deliveryStatus] ?? 'Đang cập nhật') ?></span><?php endif; ?>
                    </div>
                    <a class="btn btn-outline-dark rounded-pill" href="<?= h($ordersPath) ?>/<?= rawurlencode((string) ($order['_id'] ?? '')) ?>">Xem chi tiết</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (is_string($nextCursor) && $nextCursor !== ''): ?><nav class="d-flex justify-content-end mt-4" aria-label="Phân trang đơn hàng"><a class="btn btn-outline-dark rounded-pill" href="<?= h($ordersPath) ?>?cursor=<?= rawurlencode($nextCursor) ?>">Đơn cũ hơn <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></a></nav><?php endif; ?>
</section>
<?php $content = (string) ob_get_clean(); require dirname(__DIR__) . '/layout.php';
