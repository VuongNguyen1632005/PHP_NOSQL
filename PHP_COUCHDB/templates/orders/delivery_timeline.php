<?php
declare(strict_types=1);

$deliveryLabels = [
    'created' => 'Đã tạo vận đơn',
    'picked_up' => 'Đã lấy hàng',
    'in_transit' => 'Đang vận chuyển',
    'out_for_delivery' => 'Đang giao tới khách',
    'delivered' => 'Giao thành công',
    'failed_delivery' => 'Giao hàng thất bại',
];
$delivery = is_array($order['delivery_tracking'] ?? null) ? $order['delivery_tracking'] : null;
$deliveryHistory = $delivery !== null && is_array($delivery['history'] ?? null) ? $delivery['history'] : [];
$legacyShipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
$trackingCode = $delivery['tracking_code'] ?? $legacyShipping['tracking_code'] ?? '';
$estimatedDate = $delivery['estimated_delivery_date'] ?? $legacyShipping['estimated_delivery_date'] ?? '';
$deliveredAt = $delivery['delivered_at'] ?? '';
$hasDeliveryFacts = $delivery !== null || $trackingCode !== '' || $estimatedDate !== '';
?>
<section class="order-detail-panel order-delivery-panel mt-4" aria-labelledby="deliveryTimelineTitle">
    <div class="order-panel-heading">
        <div>
            <span class="eyebrow">VẬN CHUYỂN</span>
            <h2 id="deliveryTimelineTitle">Theo dõi giao hàng</h2>
        </div>
        <?php $deliveryStatus = (string) ($delivery['status'] ?? ''); ?>
        <span id="realtimeDeliveryStatus" class="order-status delivery-status-<?= h($deliveryStatus) ?>" <?= $deliveryStatus === '' ? 'hidden' : '' ?>><?= h($deliveryLabels[$deliveryStatus] ?? 'Đang cập nhật') ?></span>
    </div>
    <p class="muted mb-2" data-delivery-empty <?= $hasDeliveryFacts ? 'hidden' : '' ?>>Đơn hàng chưa có thông tin theo dõi giao hàng.</p>
    <dl class="order-facts order-delivery-facts" data-delivery-facts <?= $hasDeliveryFacts ? '' : 'hidden' ?>>
        <div><dt>Mã vận đơn</dt><dd id="realtimeTrackingCode"><?= h($trackingCode !== '' ? $trackingCode : 'Chưa có mã vận đơn') ?></dd></div>
        <div><dt>Dự kiến giao</dt><dd id="realtimeEstimatedDate"><?= h($estimatedDate !== '' ? date('d/m/Y', strtotime((string) $estimatedDate)) : 'Chưa có thông tin') ?></dd></div>
        <div data-delivered-at-fact <?= $deliveredAt === '' ? 'hidden' : '' ?>><dt>Đã giao lúc</dt><dd id="realtimeDeliveredAt"><?= h($deliveredAt !== '' ? date('d/m/Y H:i', strtotime((string) $deliveredAt)) : '') ?></dd></div>
    </dl>
    <p class="muted mb-0" data-delivery-history-empty <?= $deliveryHistory !== [] ? 'hidden' : '' ?>>Chưa có cập nhật vận chuyển.</p>
    <ol class="order-timeline delivery-timeline" data-delivery-history aria-label="Lịch sử giao hàng" <?= $deliveryHistory === [] ? 'hidden' : '' ?>>
        <?php foreach ($deliveryHistory as $event): if (!is_array($event)) continue; $eventStatus = (string) ($event['status'] ?? ''); $failed = $eventStatus === 'failed_delivery'; ?>
            <li class="<?= $failed ? 'is-failed' : '' ?>">
                <strong><?= $failed ? '⚠ ' : '✓ ' ?><?= h($deliveryLabels[$eventStatus] ?? 'Cập nhật giao hàng') ?></strong>
                <span><?= h(!empty($event['at']) ? date('d/m/Y H:i', strtotime((string) $event['at'])) : 'Thời điểm chưa được ghi nhận') ?></span>
                <?php if (!empty($event['note'])): ?><span class="delivery-event-note"><?= h($event['note']) ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
