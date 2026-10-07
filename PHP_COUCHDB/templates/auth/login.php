<?php
declare(strict_types=1);
ob_start();
?>
<section class="container auth-section">
    <div class="auth-card">
        <span class="eyebrow">CHÀO MỪNG BẠN TRỞ LẠI</span>
        <h1>Đăng nhập</h1>
        <p class="muted">Đăng nhập để xem giỏ hàng đã lưu trong tài khoản.</p>
        <?php if (is_array($flash) && isset($flash['message'])): ?><div class="alert alert-<?= h($flash['type'] ?? 'info') ?> py-2" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?><div class="alert alert-danger py-2" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form action="/login" method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <label for="loginEmail">Email hoặc tên đăng nhập</label>
            <input class="form-control" id="loginEmail" name="email" type="text" autocomplete="username" value="<?= h($email) ?>" required maxlength="254">
            <label for="loginPassword">Mật khẩu</label>
            <input class="form-control" id="loginPassword" name="password" type="password" autocomplete="current-password" required maxlength="128">
            <button class="btn btn-dark rounded-pill w-100" type="submit">Đăng nhập</button>
        </form>
        <p class="auth-switch">Chưa có tài khoản? <a href="/register">Đăng ký</a></p>
        <p class="auth-switch"><a href="/password-help">Quên mật khẩu?</a></p>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
