<?php
declare(strict_types=1);
ob_start();
$statusLabels = ['pending' => 'Chờ xác nhận', 'confirmed' => 'Đã xác nhận', 'packing' => 'Đang đóng gói', 'processing' => 'Đang xử lý', 'shipping' => 'Đang giao', 'delivered' => 'Đã giao', 'cancelled' => 'Đã hủy'];
$deliveryLabels = ['created' => 'Đã tạo vận đơn', 'picked_up' => 'Đã lấy hàng', 'in_transit' => 'Đang vận chuyển', 'out_for_delivery' => 'Đang giao tới khách', 'delivered' => 'Giao thành công', 'failed_delivery' => 'Giao hàng thất bại'];
$paymentLabels = ['cod' => 'Thanh toán khi nhận hàng', 'bank_transfer' => 'Chuyển khoản', 'card' => 'Thẻ'];
$status = (string) ($order['status'] ?? 'pending');
$receiver = is_array($order['receiver'] ?? null) ? $order['receiver'] : [];
$payment = is_array($order['payment'] ?? null) ? $order['payment'] : [];
$shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
$delivery = is_array($order['delivery_tracking'] ?? null) ? $order['delivery_tracking'] : null;
$paymentPaid = !empty($payment['paid']) || ($payment['status'] ?? null) === 'paid';
$items = is_array($order['items'] ?? null) ? $order['items'] : [];
$statusHistory = is_array($order['status_history'] ?? null) ? $order['status_history'] : [];
?>
<div class="container breadcrumb-line"><a href="/">Trang chủ</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="/admin/orders">Quản lý đơn hàng</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span>Chi tiết đơn</span></div>
<section class="container orders-section">
    <div class="section-heading order-detail-heading"><div><span class="eyebrow">MÃ ĐƠN <?= h($order['legacy_id'] ?? $order['_id'] ?? '') ?></span><h1>Chi tiết đơn hàng</h1><p class="muted mb-0">Đặt lúc <?= h(!empty($order['ordered_at']) ? date('d/m/Y H:i', strtotime((string) $order['ordered_at'])) : 'Chưa có thông tin') ?></p></div><span class="order-status order-status-<?= h($status) ?>"><?= h($statusLabels[$status] ?? 'Đang cập nhật') ?></span></div>
    <?php if (is_string($error) && $error !== ''): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if (is_string($success) && $success !== ''): ?><div class="alert alert-success" role="status"><?= h($success) ?></div><?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <section class="order-detail-panel" aria-labelledby="adminOrderItemsTitle">
                <h2 id="adminOrderItemsTitle">Sản phẩm trong đơn</h2>
                <?php if ($items === []): ?><p class="muted mb-0">Không có thông tin sản phẩm trong đơn hàng này.</p><?php else: ?>
                    <div class="table-responsive order-items-table-wrap"><table class="table order-items-table align-middle mb-0"><caption class="visually-hidden">Danh sách sản phẩm, kích cỡ, số lượng và đơn giá</caption><thead><tr><th scope="col">Ảnh</th><th scope="col">Sản phẩm</th><th scope="col">Size</th><th scope="col">SL</th><th scope="col">Đơn giá</th><th scope="col">Thành tiền</th></tr></thead><tbody>
                        <?php foreach ($items as $item): ?><tr><td><span class="order-item-image-placeholder" role="img" aria-label="Đơn không lưu ảnh sản phẩm"><i class="fa-regular fa-image" aria-hidden="true"></i></span></td><th scope="row"><span class="order-table-product-name"><?= h($item['product_name'] ?? $item['product_id'] ?? 'Sản phẩm') ?></span></th><td><?= h($item['size'] ?? '—') ?></td><td><?= (int) ($item['quantity'] ?? 0) ?></td><td><?= money($item['unit_price'] ?? 0) ?></td><td><strong><?= money($item['line_total'] ?? 0) ?></strong></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
                <dl class="order-totals"><div><dt>Tạm tính</dt><dd><?= money($order['totals']['subtotal'] ?? 0) ?></dd></div><div><dt>Giảm giá</dt><dd>−<?= money($order['totals']['discount_amount'] ?? 0) ?></dd></div><div><dt>Phí vận chuyển</dt><dd><?= money($order['totals']['shipping_fee'] ?? 0) ?></dd></div><div class="summary-total"><dt>Tổng cộng</dt><dd><?= money($order['totals']['grand_total'] ?? 0) ?></dd></div></dl>
            </section>
            <section class="order-detail-panel" aria-labelledby="adminOrderTimelineTitle"><h2 id="adminOrderTimelineTitle">Lịch sử trạng thái đơn</h2>
                <?php if ($statusHistory === []): ?><p class="muted mb-0">Chưa có lịch sử cập nhật trạng thái cho đơn hàng này.</p><?php else: ?><ol class="order-timeline order-history-timeline">
                    <?php foreach ($statusHistory as $event): if (!is_array($event)) continue; $eventStatus = (string) ($event['status'] ?? ''); ?><li><strong><?= h($statusLabels[$eventStatus] ?? 'Cập nhật trạng thái') ?></strong><span><?= h(!empty($event['at']) ? date('d/m/Y H:i', strtotime((string) $event['at'])) : 'Thời điểm chưa được ghi nhận') ?></span></li><?php endforeach; ?>
                </ol><?php endif; ?>
            </section>
        </div>
        <div class="col-lg-4">
            <section class="order-detail-panel" aria-labelledby="adminOrderSummaryTitle"><h2 id="adminOrderSummaryTitle">Thông tin đơn hàng</h2>
                <dl class="order-facts"><div><dt>Trạng thái đơn</dt><dd><span class="order-status order-status-<?= h($status) ?>"><?= h($statusLabels[$status] ?? 'Đang cập nhật') ?></span></dd></div><div><dt>Thanh toán</dt><dd><span class="order-status payment-status-<?= $paymentPaid ? 'paid' : 'unpaid' ?>"><?= $paymentPaid ? 'Đã thanh toán' : 'Chưa thanh toán' ?></span></dd></div><div><dt>Phương thức</dt><dd><?= h($paymentLabels[$payment['method'] ?? ''] ?? ($payment['legacy_label'] ?? 'Chưa có thông tin')) ?></dd></div></dl>
            </section>
            <section class="order-detail-panel" aria-labelledby="adminReceiverTitle"><h2 id="adminReceiverTitle">Khách hàng / người nhận</h2>
                <?php if (!empty($order['customer']['name'])): ?><p class="order-customer-name"><strong>Khách hàng:</strong> <?= h($order['customer']['name']) ?></p><?php endif; ?>
                <dl class="order-facts"><div><dt>Người nhận</dt><dd><?= h($receiver['name'] ?? 'Chưa có thông tin') ?></dd></div><div><dt>Số điện thoại</dt><dd><?= h($receiver['phone'] ?? 'Chưa có thông tin') ?></dd></div><div><dt>Email</dt><dd><?= h($receiver['email'] ?? 'Chưa có thông tin') ?></dd></div><div><dt>Địa chỉ</dt><dd><?= nl2br(h($receiver['address'] ?? 'Chưa có thông tin')) ?></dd></div></dl>
                <?php if (!empty($order['note'])): ?><div class="order-note"><strong>Ghi chú đơn hàng</strong><p><?= nl2br(h($order['note'])) ?></p></div><?php endif; ?>
            </section>
            <section class="order-detail-panel" aria-labelledby="adminShippingTitle"><h2 id="adminShippingTitle">Vận chuyển</h2>
                <dl class="order-facts"><div><dt>Phương thức</dt><dd><?= h($shipping['method_name'] ?? $shipping['method_code'] ?? 'Chưa có thông tin') ?></dd></div><div><dt>Phí vận chuyển</dt><dd><?= money($order['totals']['shipping_fee'] ?? 0) ?></dd></div><div><dt>Dự kiến giao</dt><dd><?= h(!empty($delivery['estimated_delivery_date']) ? date('d/m/Y', strtotime((string) $delivery['estimated_delivery_date'])) : 'Chưa có thông tin') ?></dd></div></dl>
            </section>
            <section class="order-detail-panel order-action-panel" aria-labelledby="adminOrderActionsTitle"><h2 id="adminOrderActionsTitle">Cập nhật đơn hàng</h2>
                <?php if (($transitions ?? []) === []): ?><p class="muted mb-0"><?= $status === 'shipping' ? 'Trạng thái đơn sẽ hoàn tất khi giao hàng được cập nhật thành công.' : 'Đơn đã ở trạng thái kết thúc, không có bước tiếp theo.' ?></p><?php else: ?>
                    <form action="/admin/orders/<?= rawurlencode((string) $order['_id']) ?>/status" method="post" class="order-status-form" onsubmit="return this.status.value !== 'cancelled' || confirm('Hủy đơn và hoàn lại tồn kho/voucher?')"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><label for="newOrderStatus">Trạng thái tiếp theo</label><select class="form-select" id="newOrderStatus" name="status" required><?php foreach ($transitions as $target): ?><option value="<?= h($target) ?>"><?= h($statusLabels[$target] ?? 'Cập nhật trạng thái') ?></option><?php endforeach; ?></select><button class="btn btn-dark rounded-pill w-100" type="submit">Lưu trạng thái đơn</button><p class="muted small mb-0">Chỉ hiển thị các bước được phép tiếp theo.</p></form>
                <?php endif; ?>
                <?php if ($status === 'delivered' && ($payment['method'] ?? null) === 'cod' && !$paymentPaid): ?><form class="order-payment-action" action="/admin/orders/<?= rawurlencode((string) $order['_id']) ?>/payment/collect" method="post" onsubmit="return confirm('Xác nhận cửa hàng đã thu đủ tiền COD cho đơn này?')"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><button class="btn btn-outline-success rounded-pill w-100" type="submit">Ghi nhận đã thu COD</button><p class="muted small mt-2 mb-0">Chỉ đánh dấu khoản COD đã thu; chưa hỗ trợ hoàn tiền.</p></form><?php endif; ?>
                <?php if (in_array(($payment['method'] ?? ''), ['bank_transfer', 'card'], true) && !$paymentPaid && $status !== 'cancelled'): ?><form class="order-payment-action" action="/admin/orders/<?= rawurlencode((string) $order['_id']) ?>/payment/verify" method="post" onsubmit="return confirm('Bạn đã đối soát giao dịch thực nhận ngoài hệ thống và xác nhận khoản thanh toán này?')"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><button class="btn btn-outline-success rounded-pill w-100" type="submit">Ghi nhận đã xác minh thanh toán</button><p class="muted small mt-2 mb-0">Chỉ xác nhận sau khi đối chiếu giao dịch.</p></form><?php endif; ?>
            </section>
            <section class="order-detail-panel order-delivery-management" aria-labelledby="adminDeliveryTitle"><h2 id="adminDeliveryTitle">Cập nhật giao hàng</h2>
                <dl class="order-facts"><div><dt>Nhân viên phụ trách</dt><dd><?= h($order['assigned_staff_id'] ?? 'Chưa phân công') ?></dd></div></dl>
                <?php if (($deliveryTransitions ?? []) !== []): ?>
                    <form action="/admin/orders/<?= rawurlencode((string) $order['_id']) ?>/delivery" method="post" class="order-status-form"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><label for="deliveryStatus">Trạng thái giao hàng tiếp theo</label><select class="form-select" id="deliveryStatus" name="delivery_status" required><?php foreach ($deliveryTransitions as $target): ?><option value="<?= h($target) ?>"><?= h($deliveryLabels[$target] ?? 'Cập nhật giao hàng') ?></option><?php endforeach; ?></select><label for="deliveryNote">Ghi chú (không bắt buộc)</label><textarea class="form-control" id="deliveryNote" name="note" maxlength="500" rows="3" placeholder="Ví dụ: Đã bàn giao cho đơn vị vận chuyển"></textarea><button class="btn btn-outline-dark rounded-pill w-100" type="submit">Lưu cập nhật giao hàng</button></form>
                <?php elseif ($delivery === null && $status === 'shipping'): ?><p class="muted mb-0">Chưa có thông tin giao hàng. Cập nhật bước đầu tiên để tạo vận đơn.</p>
                <?php else: ?><p class="muted mb-0">Không còn bước giao hàng nào có thể cập nhật.</p><?php endif; ?>
            </section>
        </div>
    </div>
    <?php require dirname(__DIR__, 2) . '/orders/delivery_timeline.php'; ?>
    <a class="continue-link d-inline-block mt-4" href="/admin/orders">← Quay lại danh sách</a>
</section>
<?php $content = (string) ob_get_clean(); require dirname(__DIR__, 2) . '/layout.php';
