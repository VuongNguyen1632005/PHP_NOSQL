<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Core\Response;
use App\Core\View;

final class CatalogController
{
    private const PAGE_SIZE = 16;

    public function __construct(private readonly ProductRepository $products)
    {
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function index(array $routeParams, array $query): Response
    {
        $products = $this->products->activeProducts();
        $ratings = $this->ratingSummary($this->products->activeReviews());
        foreach ($products as &$product) {
            $product = $this->decorate($product, $ratings);
        }
        unset($product);
        $trendingOrder = ['A06', 'G09', 'Q09', 'G06'];
        $trending = array_values(array_filter($products, static fn (array $product): bool => in_array($product['legacy_id'] ?? '', $trendingOrder, true)));
        usort($trending, static fn (array $left, array $right): int => array_search($left['legacy_id'], $trendingOrder, true) <=> array_search($right['legacy_id'], $trendingOrder, true));

        $search = trim((string) ($query['searchName'] ?? ''));
        $category = trim((string) ($query['category'] ?? ''));
        $priceRange = trim((string) ($query['priceRange'] ?? ''));
        $sort = (string) ($query['sortType'] ?? 'default');
        $products = array_values(array_filter($products, static function (array $product) use ($search, $category, $priceRange): bool {
            if ($search !== '' && mb_stripos((string) ($product['name'] ?? ''), $search) === false) {
                return false;
            }
            if ($category !== '' && ($product['category']['name'] ?? '') !== $category) {
                return false;
            }
            [$minimum, $maximum] = self::parsePriceRange($priceRange);
            if ($maximum === null) {
                return true;
            }
            foreach ($product['variants'] as $variant) {
                $price = (float) ($variant['price'] ?? 0);
                if ($price >= $minimum && $price <= $maximum) {
                    return true;
                }
            }
            return false;
        }));

        if ($sort === 'default') {
            $categoryOrder = ['A' => 1, 'Q' => 2, 'G' => 3];
            usort($products, static fn (array $left, array $right): int =>
                [($categoryOrder[$left['category']['code'] ?? ''] ?? 4), (string) ($left['legacy_id'] ?? '')]
                <=> [($categoryOrder[$right['category']['code'] ?? ''] ?? 4), (string) ($right['legacy_id'] ?? '')]
            );
        } elseif ($sort === 'asc') {
            usort($products, static fn (array $left, array $right): int => $left['display_price'] <=> $right['display_price']);
        } elseif ($sort === 'desc') {
            usort($products, static fn (array $left, array $right): int => $right['display_price'] <=> $left['display_price']);
        } elseif ($sort === 'rating') {
            usort($products, static fn (array $left, array $right): int => [$right['rating_average'], $right['rating_count']] <=> [$left['rating_average'], $left['rating_count']]);
        }

        $total = count($products);
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, (int) ($query['page'] ?? 1)), $totalPages);
        $pageProducts = array_slice($products, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        return new Response(View::render('catalog/index', [
            'title' => 'Thời trang Smart Casual', 'products' => $pageProducts, 'trending' => $trending,
            'search' => $search, 'category' => $category, 'priceRange' => $priceRange, 'sort' => $sort,
            'page' => $page, 'totalPages' => $totalPages, 'total' => $total,
        ]));
    }

    /** @param array<string, string> $routeParams
     *  @param array<string, mixed> $query
     */
    public function detail(array $routeParams, array $query): Response
    {
        $product = $this->products->productByLegacyId($routeParams['id'] ?? '');
        if ($product === null) {
            return new Response(View::render('errors/not-found', ['title' => 'Không tìm thấy sản phẩm']), 404);
        }
        $allReviews = $this->products->activeReviews();
        $ratings = $this->ratingSummary($allReviews);
        $product = $this->decorate($product, $ratings);
        $reviews = array_values(array_filter($allReviews, static fn (array $review): bool => ($review['product_id'] ?? '') === ($product['legacy_id'] ?? '')));
        usort($reviews, static fn (array $left, array $right): int => strcmp((string) ($right['legacy_id'] ?? ''), (string) ($left['legacy_id'] ?? '')));

        $related = array_values(array_filter($this->products->activeProducts(), static fn (array $item): bool =>
            ($item['legacy_id'] ?? '') !== ($product['legacy_id'] ?? '') && ($item['category']['code'] ?? '') === ($product['category']['code'] ?? '')
        ));
        foreach ($related as &$item) {
            $item = $this->decorate($item, $ratings);
        }
        unset($item);
        $related = array_slice($related, 0, 4);
        $reviewFlash = $_SESSION['review_flash'] ?? null;
        unset($_SESSION['review_flash']);

        return new Response(View::render('catalog/detail', [
            'title' => (string) ($product['name'] ?? 'Chi tiết sản phẩm'), 'product' => $product,
            'reviews' => $reviews, 'related' => $related, 'reviewFlash' => is_array($reviewFlash) ? $reviewFlash : null,
        ]));
    }

    /** @param list<array<string, mixed>> $reviews
     *  @return array<string, array{sum: int, count: int}>
     */
    private function ratingSummary(array $reviews): array
    {
        $summary = [];
        foreach ($reviews as $review) {
            $id = (string) ($review['product_id'] ?? '');
            $summary[$id] ??= ['sum' => 0, 'count' => 0];
            $summary[$id]['sum'] += (int) ($review['rating'] ?? 0);
            ++$summary[$id]['count'];
        }
        return $summary;
    }

    /** @param array<string, mixed> $product
     *  @param array<string, array{sum: int, count: int}> $ratings
     *  @return array<string, mixed>
     */
    private function decorate(array $product, array $ratings): array
    {
        $variants = array_values(array_filter($product['variants'] ?? [], static fn (array $variant): bool => ($variant['active'] ?? false) === true));
        usort($variants, static fn (array $left, array $right): int => (float) $left['price'] <=> (float) $right['price']);
        $images = array_values(array_filter($product['images'] ?? [], static fn (array $image): bool => ($image['active'] ?? false) === true));
        usort($images, static fn (array $left, array $right): int => (int) ($right['is_primary'] ?? false) <=> (int) ($left['is_primary'] ?? false));
        $summary = $ratings[(string) ($product['legacy_id'] ?? '')] ?? ['sum' => 0, 'count' => 0];
        $product['variants'] = $variants;
        $product['images'] = $images;
        $product['display_price'] = (float) ($variants[0]['price'] ?? 0);
        $product['rating_count'] = $summary['count'];
        $product['rating_average'] = $summary['count'] > 0 ? $summary['sum'] / $summary['count'] : 0.0;
        return $product;
    }

    /** @return array{0: float, 1: float|null} */
    private static function parsePriceRange(string $range): array
    {
        if (preg_match('/^(\d+)-(\d+)$/', $range, $matches) !== 1) {
            return [0.0, null];
        }
        return [(float) $matches[1], (float) $matches[2]];
    }
}
