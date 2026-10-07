<?php
declare(strict_types=1);
ob_start();
?>
<div class="container breadcrumb-line"><a href="/">Trang chủ</a><i class="fa-solid fa-chevron-right"></i><a href="/cart">Giỏ hàng</a><i class="fa-solid fa-chevron-right"></i><span>Thanh toán</span></div>
<section class="container checkout-section">
    <div class="section-heading"><div><span class="eyebrow">HOÀN TẤT ĐƠN HÀNG</span><h1>Thông tin giao hàng</h1></div></div>
    <?php if (is_string($error) && $error !== ''): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if (is_array($flash) && isset($flash['message'])): ?><div class="alert alert-<?= h($flash['type'] ?? 'info') ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
    <div class="row g-4 align-items-start">
        <div class="col-lg-7">
            <form class="checkout-form" action="/checkout" method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="checkout_token" value="<?= h($token) ?>">
                <label for="receiverName">Họ tên người nhận</label><input class="form-control" id="receiverName" name="name" value="<?= h($name) ?>" maxlength="100" required>
                <div class="row g-3"><div class="col-md-6"><label for="receiverPhone">Số điện thoại</label><input class="form-control" id="receiverPhone" name="phone" inputmode="numeric" pattern="0[0-9]{9}" maxlength="10" value="<?= h($phone) ?>" required></div><div class="col-md-6"><label for="receiverEmail">Email</label><input class="form-control" id="receiverEmail" name="email" type="email" maxlength="254" value="<?= h($email) ?>" required></div></div>
                <label for="receiverAddress">Địa chỉ giao hàng</label><textarea class="form-control" id="receiverAddress" name="address" rows="3" maxlength="500" required><?= h($address) ?></textarea>
                <label for="shippingCode">Phương thức vận chuyển</label><select class="form-select" id="shippingCode" name="shipping_code" required><?php foreach ($shippingMethods as $method): ?><option value="<?= h($method['code']) ?>" <?= ($method['code'] ?? '') === $shippingCode ? 'selected' : '' ?>><?= h($method['name']) ?> · <?= money($method['fee']) ?></option><?php endforeach; ?></select>
                <label for="voucherCode">Mã giảm giá <span class="muted">(không bắt buộc)</span></label><input class="form-control" id="voucherCode" name="voucher_code" value="<?= h($voucherCode) ?>" maxlength="80" placeholder="Ví dụ: SAVE10">
                <fieldset class="payment-choice"><legend>Thanh toán</legend><label><input type="radio" name="payment_method" value="cod" checked> Thanh toán khi nhận hàng</label><p class="muted mb-0">Đơn được ghi nhận là chưa thanh toán cho đến khi xác nhận thu tiền.</p></fieldset>
                <label for="orderNote">Ghi chú</label><textarea class="form-control" id="orderNote" name="note" rows="2" maxlength="500"><?= h($note) ?></textarea>
                <button class="btn btn-outline-dark rounded-pill w-100" type="submit" name="preview_only" value="1">Cập nhật tạm tính</button>
                <button class="btn btn-dark rounded-pill w-100" type="submit">Đặt hàng</button>
            </form>
        </div>
        <div class="col-lg-5"><aside class="cart-summary checkout-summary"><h2>Sản phẩm đã chọn</h2>
            <?php foreach ($items as $item): ?><div class="checkout-line"><span><?= h($item['name']) ?> · <?= h($item['size']) ?> × <?= (int) $item['quantity'] ?></span><strong><?= money((float) $item['unit_price'] * (int) $item['quantity']) ?></strong></div><?php endforeach; ?>
            <div class="summary-row"><span>Tạm tính sản phẩm</span><strong><?= money($subtotal) ?></strong></div>
            <div class="summary-row"><span>Phí vận chuyển</span><strong><?= money($shippingFee) ?></strong></div>
            <div class="summary-row"><span>Giảm giá</span><strong>−<?= money($discountAmount) ?></strong></div>
            <div class="summary-row summary-total"><span>Tổng cộng</span><strong><?= money($grandTotal) ?></strong></div>
            <p class="cart-checkout-note">Giá, tồn kho, phí giao hàng và mã giảm giá được máy chủ kiểm tra lại khi đặt hàng. Mỗi yêu cầu chỉ tạo một đơn; nếu trang báo lỗi kết nối, hãy gửi lại cùng biểu mẫu.</p>
            <a class="continue-link" href="/cart">Quay lại giỏ hàng</a>
        </aside></div>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
