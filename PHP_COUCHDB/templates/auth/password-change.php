<?php
declare(strict_types=1);
ob_start();
?>
<section class="container auth-section">
    <div class="auth-card">
        <span class="eyebrow">BẢO MẬT TÀI KHOẢN</span>
        <h1>Đổi mật khẩu</h1>
        <?php if (($required ?? false) === true): ?><p class="muted">Bạn đang dùng mật khẩu tạm. Hãy đổi mật khẩu trước khi tiếp tục sử dụng tài khoản.</p><?php else: ?><p class="muted">Dùng mật khẩu mới có ít nhất 12 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt.</p><?php endif; ?>
        <?php if (is_array($flash) && isset($flash['message'])): ?><div class="alert alert-<?= h($flash['type'] ?? 'info') ?> py-2" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?><div class="alert alert-danger py-2" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form action="/account/password" method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <label for="currentPassword">Mật khẩu hiện tại</label>
            <input class="form-control" id="currentPassword" name="current_password" type="password" autocomplete="current-password" required maxlength="128">
            <label for="newPassword">Mật khẩu mới</label>
            <input class="form-control" id="newPassword" name="new_password" type="password" autocomplete="new-password" required minlength="12" maxlength="128">
            <label for="confirmPassword">Xác nhận mật khẩu mới</label>
            <input class="form-control" id="confirmPassword" name="confirm_password" type="password" autocomplete="new-password" required minlength="12" maxlength="128">
            <button class="btn btn-dark rounded-pill w-100" type="submit">Cập nhật mật khẩu</button>
        </form>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
