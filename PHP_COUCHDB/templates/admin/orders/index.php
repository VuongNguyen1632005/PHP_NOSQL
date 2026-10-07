<?php
declare(strict_types=1);
ob_start();
$statusLabels = ['pending' => 'Chờ xác nhận', 'confirmed' => 'Đã xác nhận', 'packing' => 'Đang đóng gói', 'processing' => 'Đang xử lý', 'shipping' => 'Đang giao', 'delivered' => 'Đã giao', 'cancelled' => 'Đã hủy'];
$deliveryLabels = ['created' => 'Đã tạo vận đơn', 'picked_up' => 'Đã lấy hàng', 'in_transit' => 'Đang vận chuyển', 'out_for_delivery' => 'Đang giao tới khách', 'delivered' => 'Giao thành công', 'failed_delivery' => 'Giao hàng thất bại'];
?>
<div class="container breadcrumb-line"><a href="/">Trang chủ</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span>Quản lý đơn hàng</span></div>
<section class="container orders-section">
    <div class="section-heading"><div><span class="eyebrow">KHU VỰC NHÂN VIÊN</span><h1>Quản lý đơn hàng</h1><p class="muted mb-0">Đơn được sắp xếp theo thời điểm đặt gần nhất.</p></div></div>
    <?php if (is_array($flash) && isset($flash['message'])): ?><div class="alert alert-<?= h($flash['type'] ?? 'info') ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
    <form class="order-filter" action="/admin/orders" method="get"><label for="orderStatusFilter">Lọc theo trạng thái đơn</label><select class="form-select" id="orderStatusFilter" name="status"><option value="">Tất cả đơn</option><?php foreach ($statusLabels as $code => $label): ?><option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select><button class="btn btn-outline-dark rounded-pill" type="submit">Lọc đơn hàng</button></form>
    <?php if ($orders === []): ?>
        <div class="cart-empty"><i class="fa-solid fa-box-open" aria-hidden="true"></i><h2>Không có đơn phù hợp</h2><p>Thử chọn trạng thái khác để xem thêm đơn hàng.</p></div>
    <?php else: ?>
        <div class="order-list admin-order-list">
            <?php foreach ($orders as $order):
                $currentStatus = (string) ($order['status'] ?? 'pending');
                $delivery = is_array($order['delivery_tracking'] ?? null) ? $order['delivery_tracking'] : null;
                $deliveryStatus = (string) ($delivery['status'] ?? '');
                $paymentPaid = !empty($order['payment']['paid']) || ($order['payment']['status'] ?? null) === 'paid';
                $trackingCode = (string) ($delivery['tracking_code'] ?? $order['shipping']['tracking_code'] ?? '');
            ?>
                <article class="order-card admin-order-card">
                    <div class="order-card-heading"><div><span class="eyebrow">MÃ ĐƠN</span><h2><?= h($order['legacy_id'] ?? $order['_id'] ?? '') ?></h2></div><span class="order-status order-status-<?= h($currentStatus) ?>"><?= h($statusLabels[$currentStatus] ?? 'Đang cập nhật') ?></span></div>
                    <dl class="admin-order-facts">
                        <div><dt>Khách hàng</dt><dd><?= h($order['customer']['name'] ?? $order['receiver']['name'] ?? 'Khách vãng lai') ?></dd></div>
                        <div><dt>Ngày đặt</dt><dd><?= h(!empty($order['ordered_at']) ? date('d/m/Y H:i', strtotime((string) $order['ordered_at'])) : '—') ?></dd></div>
                        <div><dt>Sản phẩm</dt><dd><?= (int) ($order['totals']['total_quantity'] ?? 0) ?></dd></div>
                        <div><dt>Tổng tiền</dt><dd class="admin-order-total"><?= money($order['totals']['grand_total'] ?? 0) ?></dd></div>
                    </dl>
                    <div class="order-card-badges"><span class="order-status payment-status-<?= $paymentPaid ? 'paid' : 'unpaid' ?>">Thanh toán: <?= $paymentPaid ? 'Đã thanh toán' : 'Chưa thanh toán' ?></span>
                        <?php if ($delivery !== null && $deliveryStatus !== ''): ?><span class="order-status delivery-status-<?= h($deliveryStatus) ?>">Giao hàng: <?= h($deliveryLabels[$deliveryStatus] ?? 'Đang cập nhật') ?></span><?php else: ?><span class="order-status delivery-status-missing">Chưa có thông tin giao hàng</span><?php endif; ?>
                    </div>
                    <?php if ($trackingCode !== ''): ?><p class="order-tracking-summary"><strong>Mã vận đơn:</strong> <span><?= h($trackingCode) ?></span></p><?php endif; ?>
                    <a class="btn btn-outline-dark rounded-pill" href="/admin/orders/<?= rawurlencode((string) ($order['_id'] ?? '')) ?>">Mở chi tiết đơn hàng</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (is_string($nextCursor) && $nextCursor !== ''): ?><nav class="d-flex justify-content-end mt-4" aria-label="Phân trang đơn hàng"><a class="btn btn-outline-dark rounded-pill" href="/admin/orders?status=<?= rawurlencode($status) ?>&amp;cursor=<?= rawurlencode($nextCursor) ?>">Đơn tiếp theo <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></a></nav><?php endif; ?>
</section>
<?php $content = (string) ob_get_clean(); require dirname(__DIR__, 2) . '/layout.php';
