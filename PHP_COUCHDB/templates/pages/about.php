<?php
declare(strict_types=1);
ob_start();
?>
<div class="container breadcrumb-line"><a href="/">Trang chủ</a><i class="fa-solid fa-chevron-right"></i><span>Giới thiệu</span></div>
<section class="container section-block pt-2">
    <div class="row align-items-center g-4 g-lg-5">
        <div class="col-lg-6">
            <div class="row g-2">
                <div class="col-6">
                    <img src="/assets/img/about/about-1-1.jpg" alt="Không gian và phong cách SmartWear" class="img-fluid rounded shadow-sm mb-2">
                    <img src="/assets/img/about/about-1-2.jpg" alt="Chất liệu thời trang SmartWear" class="img-fluid rounded shadow-sm">
                </div>
                <div class="col-6">
                    <img src="/assets/img/about/about-1-3.jpg" alt="Bộ sưu tập SmartWear" class="img-fluid rounded shadow-sm">
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <span class="eyebrow">CÂU CHUYỆN CỦA CHÚNG TÔI</span>
            <h1 class="display-5 fw-semibold">Thanh lịch, thoải mái, mỗi ngày.</h1>
            <p class="muted">SmartWear được thành lập năm 2020 với mong muốn mang đến những lựa chọn thời trang hiện đại, tiện dụng và dễ mặc.</p>
            <p class="muted">Chúng tôi theo đuổi phong cách <strong>Smart Casual</strong>: tối giản, dễ phối và phù hợp từ công sở đến những buổi gặp gỡ thường ngày. Chất liệu, phom dáng và trải nghiệm của khách hàng luôn là những điều chúng tôi quan tâm khi chọn lựa sản phẩm.</p>
            <a class="btn btn-dark rounded-pill px-4 mt-2" href="/#shop-area">Khám phá sản phẩm</a>
        </div>
    </div>
</section>

<section class="container section-block">
    <div class="section-heading"><div><span class="eyebrow">ĐIỀU CHÚNG TÔI THEO ĐUỔI</span><h2>Chọn lựa có chủ đích</h2></div></div>
    <div class="row g-3">
        <div class="col-md-4"><article class="order-detail-panel h-100"><h2>Chỉn chu</h2><p class="muted mb-0">Mỗi sản phẩm được chọn theo tiêu chí dễ mặc, dễ kết hợp và phù hợp nhịp sống hằng ngày.</p></article></div>
        <div class="col-md-4"><article class="order-detail-panel h-100"><h2>Thoải mái</h2><p class="muted mb-0">Ưu tiên cảm giác vừa vặn và tiện dụng để bạn tự tin trong nhiều hoàn cảnh.</p></article></div>
        <div class="col-md-4"><article class="order-detail-panel h-100"><h2>Đồng hành</h2><p class="muted mb-0">Chúng tôi luôn lắng nghe góp ý để hoàn thiện lựa chọn sản phẩm và dịch vụ.</p></article></div>
    </div>
</section>

<section class="container section-block pb-5">
    <div class="row g-4">
        <div class="col-md-6">
            <span class="eyebrow">LIÊN HỆ</span><h2>Ghé thăm hoặc kết nối với chúng tôi</h2>
            <p class="muted mb-2"><i class="fa-solid fa-location-dot me-2"></i>1234/321 HUIT, đường Nguyễn Trọng Tấn, TP. Hồ Chí Minh</p>
            <p class="muted mb-2"><i class="fa-solid fa-phone me-2"></i><a href="tel:0987654321">0987 654 321</a></p>
            <p class="muted mb-2"><i class="fa-solid fa-envelope me-2"></i><a href="mailto:Team9@gmail.com">Team9@gmail.com</a></p>
            <p class="muted"><i class="fa-regular fa-clock me-2"></i>Thời gian làm việc: 8:00–21:00, Thứ Hai–Chủ Nhật</p>
            <a href="https://www.google.com/maps/search/?api=1&query=10.8061539%2C106.6237947" target="_blank" rel="noopener noreferrer" class="text-link">Mở vị trí trên Google Maps <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
        </div>
        <div class="col-md-6"><div class="order-detail-panel h-100"><span class="eyebrow">SMARTWEAR</span><h2>Phong cách của bạn, mỗi ngày.</h2><p class="muted mb-0">Cảm ơn bạn đã ghé thăm và đồng hành cùng chúng tôi.</p></div></div>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
