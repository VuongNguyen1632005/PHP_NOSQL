<?php
declare(strict_types=1);
ob_start();
?>
<section class="container auth-section">
    <div class="auth-card">
        <span class="eyebrow">TÀI KHOẢN SMART CASUAL</span>
        <h1>Tạo tài khoản</h1>
        <p class="muted">Thông tin của bạn được lưu an toàn trong CouchDB.</p>
        <?php if (is_string($error) && $error !== ''): ?><div class="alert alert-danger py-2" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form action="/register" method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <label for="registerName">Họ tên</label>
            <input class="form-control" id="registerName" name="fullName" type="text" autocomplete="name" value="<?= h($fullName) ?>" required maxlength="100">
            <label for="registerEmail">Email</label>
            <input class="form-control" id="registerEmail" name="email" type="email" autocomplete="email" value="<?= h($email) ?>" required maxlength="254">
            <label for="registerPassword">Mật khẩu</label>
            <input class="form-control" id="registerPassword" name="password" type="password" autocomplete="new-password" required minlength="8" maxlength="128" aria-describedby="passwordHint">
            <small id="passwordHint" class="muted">Ít nhất 8 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt.</small>
            <label for="confirmPassword">Xác nhận mật khẩu</label>
            <input class="form-control" id="confirmPassword" name="confirmPassword" type="password" autocomplete="new-password" required minlength="8" maxlength="128">
            <button class="btn btn-dark rounded-pill w-100" type="submit">Đăng ký</button>
        </form>
        <p class="auth-switch">Đã có tài khoản? <a href="/login">Đăng nhập</a></p>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
