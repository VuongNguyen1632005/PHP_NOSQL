<?php
declare(strict_types=1);
ob_start();
?>
<section class="hero-band">
    <div class="container hero-content"><div><span class="eyebrow">BỘ SƯU TẬP MỚI</span><h1>Mặc đẹp theo<br><em>cách của bạn.</em></h1><p>Những lựa chọn thoải mái, chỉn chu cho mọi ngày.</p><a class="btn btn-light rounded-pill px-4 py-2" href="#shop-area">Khám phá sản phẩm <i class="fa-solid fa-arrow-down ms-2"></i></a></div><div class="hero-art"><img src="/assets/img/banner/Banner-1.jpg" alt="Bộ sưu tập thời trang Smart Casual"></div></div>
</section>
<?php if ($trending !== []): ?>
<section class="container section-block"><div class="section-heading"><div><span class="eyebrow">ĐƯỢC YÊU THÍCH</span><h2>Trang phục thịnh hành</h2></div><a href="#shop-area" class="text-link">Xem tất cả <i class="fa-solid fa-arrow-right"></i></a></div><div class="row g-3 g-lg-4">
<?php foreach ($trending as $item): ?>
    <div class="col-6 col-lg-3"><?php require __DIR__ . '/product-card.php'; ?></div>
<?php endforeach; ?>
</div></section>
<?php endif; ?>
<section id="shop-area" class="container section-block catalog-section">
    <div class="section-heading"><div><span class="eyebrow">TÌM PHONG CÁCH CỦA BẠN</span><h2>Tất cả sản phẩm</h2><p class="muted mb-0">Tìm thấy <?= (int) $total ?> sản phẩm</p></div></div>
    <form class="filter-panel row g-2 align-items-end" action="/" method="get">
        <div class="col-12 col-md-4"><label for="searchName">Tìm theo tên</label><div class="input-icon"><i class="fa-solid fa-magnifying-glass"></i><input id="searchName" class="form-control" name="searchName" value="<?= h($search) ?>" placeholder="Ví dụ: áo thêu..."></div></div>
        <div class="col-6 col-md-2"><label for="category">Danh mục</label><select id="category" class="form-select" name="category"><option value="">Tất cả</option><?php foreach (['Áo', 'Quần', 'Giày'] as $option): ?><option value="<?= h($option) ?>" <?= $category === $option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label for="priceRange">Khoảng giá</label><select id="priceRange" class="form-select" name="priceRange"><option value="">Tất cả mức giá</option><?php foreach (['0-300000' => 'Dưới 300.000₫', '300000-600000' => '300.000₫ – 600.000₫', '600000-1000000' => '600.000₫ – 1.000.000₫', '1000000-999999999' => 'Trên 1.000.000₫'] as $value => $label): ?><option value="<?= h($value) ?>" <?= $priceRange === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label for="sortType">Sắp xếp</label><select id="sortType" class="form-select" name="sortType"><option value="default" <?= $sort === 'default' ? 'selected' : '' ?>>Mặc định</option><option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>Giá tăng dần</option><option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Giá giảm dần</option><option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Đánh giá cao</option></select></div>
        <div class="col-6 col-md-2 d-grid"><button class="btn btn-dark rounded-pill" type="submit"><i class="fa-solid fa-sliders me-2"></i>Lọc sản phẩm</button></div>
    </form>
    <?php if ($products === []): ?><div class="empty-state"><i class="fa-regular fa-face-frown"></i><h3>Chưa tìm thấy sản phẩm</h3><p>Thử thay đổi từ khóa hoặc bộ lọc nhé.</p><a class="btn btn-outline-dark rounded-pill" href="/">Xóa bộ lọc</a></div><?php else: ?>
    <div class="row g-3 g-lg-4 product-grid"><?php foreach ($products as $item): ?><div class="col-6 col-md-4 col-lg-3"><?php require __DIR__ . '/product-card.php'; ?></div><?php endforeach; ?></div>
    <?php if ($totalPages > 1): ?><nav class="pagination-wrap" aria-label="Phân trang"><ul class="pagination"><?php for ($number = 1; $number <= $totalPages; ++$number): $params = $_GET; $params['page'] = $number; ?><li class="page-item <?= $number === $page ? 'active' : '' ?>"><a class="page-link" href="/?<?= h(http_build_query($params)) ?>#shop-area"><?= $number ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
