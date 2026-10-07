<?php
declare(strict_types=1);
ob_start();
?>
<div class="container orders-section">
    <div class="section-heading"><div><span class="eyebrow">KHU VỰC NHÂN VIÊN</span><h1>Báo cáo doanh thu</h1><p class="muted mb-0">Doanh thu ghi nhận bằng tổng giá trị đơn đã giao; trạng thái thanh toán được hiển thị riêng.</p></div><a class="btn btn-outline-dark rounded-pill" href="/admin/revenue/stats">Xem JSON API</a></div>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><article class="order-card h-100"><span class="eyebrow">DOANH THU ĐÃ GIAO</span><h2 class="text-success"><?= money($report['revenue_vnd']) ?></h2><span><?= (int) $report['delivered_orders'] ?> đơn đã giao</span></article></div>
        <div class="col-md-3"><article class="order-card h-100"><span class="eyebrow">ĐANG XỬ LÝ</span><h2><?= (int) $report['processing_orders'] ?></h2><span>Đơn chưa kết thúc</span></article></div>
        <div class="col-md-3"><article class="order-card h-100"><span class="eyebrow">ĐÃ HỦY</span><h2><?= (int) $report['cancelled_orders'] ?></h2><span>Đơn đã hủy</span></article></div>
        <div class="col-md-3"><article class="order-card h-100"><span class="eyebrow">TỶ LỆ HOÀN TẤT</span><h2><?= h(number_format((float) $report['completion_rate'], 1, ',', '.')) ?>%</h2><span><?= (int) $report['delivered_orders'] ?> / <?= (int) $report['total_orders'] ?> đơn</span></article></div>
    </div>
    <div class="section-heading"><div><h2>Đơn đã giao gần đây</h2><p class="muted">Bảng hiển thị tối đa 100 đơn mới nhất; tổng số liệu phía trên tính trên toàn bộ đơn hoàn tất.</p></div></div>
    <?php if ($report['latest_delivered_orders'] === []): ?><div class="cart-empty"><i class="fa-solid fa-chart-line"></i><h2>Chưa có doanh thu ghi nhận</h2><p>Đơn được tính vào doanh thu sau khi chuyển sang trạng thái đã giao.</p></div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Mã đơn</th><th>Khách hàng</th><th>Ngày đặt</th><th>Thanh toán</th><th class="text-end">Giá trị đơn</th></tr></thead><tbody>
        <?php foreach ($report['latest_delivered_orders'] as $order): ?><tr><td><a href="/admin/orders/<?= rawurlencode($order['id']) ?>"><?= h($order['legacy_id']) ?></a></td><td><?= h($order['customer_name']) ?></td><td><?= h($order['ordered_at'] !== '' ? date('d/m/Y H:i', strtotime($order['ordered_at'])) : '—') ?></td><td><span class="badge text-bg-<?= $order['paid'] ? 'success' : 'warning' ?>"><?= $order['paid'] ? 'Đã thanh toán' : 'Chưa thanh toán' ?></span></td><td class="text-end fw-bold"><?= money($order['grand_total']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php $content = (string) ob_get_clean(); require dirname(__DIR__, 2) . '/layout.php';
