<?php
declare(strict_types=1);
ob_start();
?>
<section class="container empty-state not-found"><span class="eyebrow">404</span><h1>Không tìm thấy sản phẩm</h1><p>Sản phẩm có thể đã ngừng kinh doanh hoặc đường dẫn không chính xác.</p><a class="btn btn-dark rounded-pill px-4" href="/">Quay lại cửa hàng</a></section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
