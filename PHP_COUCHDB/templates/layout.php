<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#14221d">
    <title><?= h($title ?? 'Shop thời trang') ?> | Smart Casual</title>
    <link rel="stylesheet" href="/assets/vendors/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendors/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/catalog.css">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a href="/" class="brand"><span class="brand-mark">S</span><span>SMART<span class="brand-light">CASUAL</span><small>THỜI TRANG MỖI NGÀY</small></span></a>
        <nav class="main-nav" aria-label="Điều hướng chính"><a href="/">Trang chủ</a><a href="/about">Giới thiệu</a><a href="/#shop-area">Sản phẩm</a><a href="/?category=Áo#shop-area">Áo</a><a href="/?category=Quần#shop-area">Quần</a><a href="/?category=Giày#shop-area">Giày</a></nav>
        <div class="header-actions"><a class="header-search" href="/#shop-area" aria-label="Tìm sản phẩm"><i class="fa-solid fa-magnifying-glass"></i></a><?php $user = currentUser(); ?><?php if ($user !== null): ?><?php if (($user['type'] ?? null) === 'customer'): ?><a class="account-link" href="/account/orders">Đơn hàng</a><a class="account-link" href="/account/password">Đổi mật khẩu</a><?php elseif (($user['type'] ?? null) === 'staff'): ?><a class="account-link" href="/admin/orders">Quản lý đơn</a><a class="account-link" href="/admin/revenue">Doanh thu</a><a class="account-link" href="/admin/products">Sản phẩm</a><a class="account-link" href="/admin/reviews">Đánh giá</a><?php endif; ?><span class="account-greeting">Xin chào, <?= h($user['name'] ?: $user['username']) ?></span><form class="logout-form" action="/logout" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><button type="submit" aria-label="Đăng xuất">Đăng xuất</button></form><?php else: ?><?php if (!empty($_SESSION['_guest_order_ids'])): ?><a class="account-link" href="/orders">Đơn đã đặt</a><?php endif; ?><a class="account-link" href="/login">Đăng nhập</a><?php endif; ?><a class="cart-link" href="/cart" aria-label="Giỏ hàng"><i class="fa-solid fa-bag-shopping"></i><span>Giỏ hàng</span><?php if (cartLineCount() > 0): ?><b><?= cartLineCount() ?></b><?php endif; ?></a></div>
    </div>
</header>
<main><?= $content ?? '' ?></main>
<footer class="site-footer"><div class="container d-flex flex-wrap justify-content-between gap-3"><span>SMART CASUAL · Phong cách của bạn, mỗi ngày.</span><span>Danh mục sản phẩm dùng PHP và CouchDB</span></div></footer>
<script src="/assets/vendors/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/catalog.js"></script>
<script src="/assets/js/order-realtime.js"></script>
</body>
</html>
