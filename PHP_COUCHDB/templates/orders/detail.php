<?php
declare(strict_types=1);
ob_start();
$statusLabels = ['pending' => 'Chờ xác nhận', 'confirmed' => 'Đã xác nhận', 'packing' => 'Đang đóng gói', 'processing' => 'Đang xử lý', 'shipping' => 'Đang giao', 'delivered' => 'Đã giao', 'cancelled' => 'Đã hủy', 'returned' => 'Đã hoàn trả'];
$paymentLabels = ['cod' => 'Thanh toán khi nhận hàng', 'bank_transfer' => 'Chuyển khoản', 'card' => 'Thẻ'];
$status = (string) ($order['status'] ?? 'pending');
$flash = is_array($flash ?? null) ? $flash : null;
$isGuest = ($isGuest ?? false) === true;
$ordersPath = $isGuest ? '/orders' : '/account/orders';
$statusHistory = is_array($order['status_history'] ?? null) ? $order['status_history'] : [];
$paymentMethod = (string) ($order['payment']['method'] ?? '');
$paymentPaid = !empty($order['payment']['paid']) || ($order['payment']['status'] ?? null) === 'paid';
?>
<div class="container breadcrumb-line"><a href="/">Trang chủ</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="<?= h($ordersPath) ?>"><?= $isGuest ? 'Đơn đã đặt' : 'Đơn hàng của tôi' ?></a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span>Chi tiết đơn</span></div>
<section class="container orders-section" data-order-id="<?= h($order['_id'] ?? '') ?>" data-order-events-url="<?= h($ordersPath . '/' . rawurlencode((string) ($order['_id'] ?? '')) . '/events') ?>">
    <div class="section-heading order-detail-heading"><div><span class="eyebrow">MÃ ĐƠN <?= h($order['legacy_id'] ?? $order['_id'] ?? '') ?></span><h1>Chi tiết đơn hàng</h1><p class="muted mb-0">Đặt lúc <?= h(!empty($order['ordered_at']) ? date('d/m/Y H:i', strtotime((string) $order['ordered_at'])) : 'Chưa có thông tin') ?></p></div><span id="realtimeOrderStatus" class="order-status order-status-<?= h($status) ?>"><?= h($statusLabels[$status] ?? 'Đang cập nhật') ?></span></div>
    <p class="muted small order-realtime-status" data-order-events-status role="status" aria-live="polite">Đang kết nối cập nhật…</p>
    <?php if ($flash !== null): ?><div class="alert alert-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'danger' ?>" role="status"><?= h($flash['message'] ?? '') ?></div><?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <section class="order-detail-panel" aria-labelledby="orderItemsTitle">
                <h2 id="orderItemsTitle">Sản phẩm trong đơn</h2>
                <?php if (($order['items'] ?? []) === []): ?><p class="muted mb-0">Không có thông tin sản phẩm trong đơn hàng này.</p><?php else: ?>
                    <div class="order-items-list">
                        <?php foreach (($order['items'] ?? []) as $item): ?>
                            <article class="order-item"><div class="order-item-copy"><strong><?= h($item['product_name'] ?? $item['product_id'] ?? 'Sản phẩm') ?></strong><span>Size: <?= h($item['size'] ?? '—') ?> · Số lượng: <?= (int) ($item['quantity'] ?? 0) ?></span><span>Đơn giá: <?= money($item['unit_price'] ?? 0) ?></span></div><strong class="order-item-total"><?= money($item['line_total'] ?? 0) ?></strong></article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <dl class="order-totals"><div><dt>Tạm tính</dt><dd><?= money($order['totals']['subtotal'] ?? 0) ?></dd></div><div><dt>Phí vận chuyển</dt><dd><?= money($order['totals']['shipping_fee'] ?? 0) ?></dd></div><div><dt>Giảm giá</dt><dd>−<?= money($order['totals']['discount_amount'] ?? 0) ?></dd></div><div class="summary-total"><dt>Tổng cộng</dt><dd><?= money($order['totals']['grand_total'] ?? 0) ?></dd></div></dl>
            </section>
            <section class="order-detail-panel" aria-labelledby="orderTimelineTitle">
                <h2 id="orderTimelineTitle">Lịch sử đơn hàng</h2>
                    <p class="muted mb-0" data-order-history-empty <?= $statusHistory !== [] ? 'hidden' : '' ?>>Chưa có lịch sử cập nhật trạng thái cho đơn hàng này.</p>
                    <ol class="order-timeline order-history-timeline" data-order-history aria-label="Lịch sử đơn hàng" <?= $statusHistory === [] ? 'hidden' : '' ?>>
                        <?php foreach ($statusHistory as $event): if (!is_array($event)) continue; $eventStatus = (string) ($event['status'] ?? ''); ?>
                            <li><strong><?= h($statusLabels[$eventStatus] ?? 'Cập nhật trạng thái') ?></strong><span><?= h(!empty($event['at']) ? date('d/m/Y H:i', strtotime((string) $event['at'])) : 'Thời điểm chưa được ghi nhận') ?></span></li>
                        <?php endforeach; ?>
                    </ol>
            </section>
        </div>
        <div class="col-lg-4">
            <section class="order-detail-panel" aria-labelledby="receiverTitle">
                <h2 id="receiverTitle">Thông tin nhận hàng</h2>
                <dl class="order-facts"><div><dt>Người nhận</dt><dd><?= h($order['receiver']['name'] ?? 'Chưa có thông tin') ?></dd></div><div><dt>Số điện thoại</dt><dd><?= h($order['receiver']['phone'] ?? 'Chưa có thông tin') ?></dd></div><div><dt>Email</dt><dd><?= h($order['receiver']['email'] ?? 'Chưa có thông tin') ?></dd></div><div><dt>Địa chỉ</dt><dd><?= nl2br(h($order['receiver']['address'] ?? 'Chưa có thông tin')) ?></dd></div></dl>
                <?php if (!empty($order['note'])): ?><div class="order-note"><strong>Ghi chú đơn hàng</strong><p><?= nl2br(h($order['note'])) ?></p></div><?php endif; ?>
            </section>
            <section class="order-detail-panel" aria-labelledby="paymentTitle">
                <h2 id="paymentTitle">Thanh toán</h2>
                <dl class="order-facts"><div><dt>Phương thức</dt><dd><?= h($paymentLabels[$paymentMethod] ?? ($order['payment']['legacy_label'] ?? 'Chưa có thông tin')) ?></dd></div><div><dt>Trạng thái</dt><dd><span id="realtimePaymentStatus" class="order-status payment-status-<?= $paymentPaid ? 'paid' : 'unpaid' ?>"><?= $paymentPaid ? 'Đã thanh toán' : 'Chưa thanh toán' ?></span></dd></div></dl>
                <?php if (!empty($order['shipping']['method_name']) || !empty($order['shipping']['method_code'])): ?><p class="order-shipping-method mb-0"><strong>Vận chuyển:</strong> <?= h($order['shipping']['method_name'] ?? $order['shipping']['method_code']) ?></p><?php endif; ?>
                <form class="confirm-received-form" data-receive-confirm action="<?= h($ordersPath) ?>/<?= rawurlencode((string) ($order['_id'] ?? '')) ?>/confirm-received" method="post" onsubmit="return confirm('Xác nhận bạn đã nhận được đơn hàng này?')" <?= $status !== 'shipping' ? 'hidden' : '' ?>><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><button class="btn btn-dark rounded-pill w-100" type="submit">Tôi đã nhận hàng</button><?php if ($paymentMethod === 'cod' && !$paymentPaid): ?><p class="muted small mt-2 mb-0">Xác nhận này cũng ghi nhận khoản COD đã thu.</p><?php elseif (!$paymentPaid): ?><p class="muted small mt-2 mb-0">Xác nhận giao hàng không xác minh khoản chuyển khoản hoặc thẻ.</p><?php endif; ?></form>
            </section>
        </div>
    </div>
    <?php require __DIR__ . '/delivery_timeline.php'; ?>
    <a class="continue-link d-inline-block mt-4" href="<?= h($ordersPath) ?>">← Quay lại danh sách đơn hàng</a>
</section>
<?php $content = (string) ob_get_clean(); require dirname(__DIR__) . '/layout.php';
