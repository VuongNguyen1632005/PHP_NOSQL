<?php
declare(strict_types=1);
ob_start();
?>
<section class="container auth-section">
    <div class="auth-card">
        <span class="eyebrow">HỖ TRỢ TÀI KHOẢN</span>
        <h1>Quên mật khẩu?</h1>
        <p class="muted">Vui lòng liên hệ cửa hàng để nhân viên xác minh chủ tài khoản và cấp mật khẩu tạm. Sau khi đăng nhập bằng mật khẩu tạm, bạn sẽ được yêu cầu đổi mật khẩu ngay.</p>
        <p class="muted">Không chia sẻ mật khẩu hoặc mã xác minh với người khác. Nhân viên sẽ không yêu cầu bạn gửi mật khẩu hiện tại.</p>
        <a class="btn btn-dark rounded-pill w-100" href="/login">Quay lại đăng nhập</a>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
