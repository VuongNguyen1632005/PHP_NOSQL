<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Core\Response;

final class CatalogApiController
{
    private const DEFAULT_PAGE_SIZE = 16;
    private const MAX_PAGE_SIZE = 50;

    public function __construct(private readonly ProductRepository $products)
    {
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function index(array $params, array $query): Response
    {
        $search = trim((string) ($query['search'] ?? ''));
        $category = trim((string) ($query['category'] ?? ''));
        if (mb_strlen($search) > 100 || mb_strlen($category) > 80) {
            return $this->json(['error' => 'Search and category filters are too long.'], 400);
        }

        $minimum = $this->numberFilter($query, 'min_price');
        $maximum = $this->numberFilter($query, 'max_price');
        if ($minimum instanceof Response) return $minimum;
        if ($maximum instanceof Response) return $maximum;
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            return $this->json(['error' => 'min_price must not be greater than max_price.'], 400);
        }

        $page = $this->integerFilter($query, 'page', 1, 1, 1000000);
        $pageSize = $this->integerFilter($query, 'page_size', self::DEFAULT_PAGE_SIZE, 1, self::MAX_PAGE_SIZE);
        if ($page instanceof Response) return $page;
        if ($pageSize instanceof Response) return $pageSize;

        $sort = (string) ($query['sort'] ?? 'default');
        if (!in_array($sort, ['default', 'price_asc', 'price_desc', 'rating'], true)) {
            return $this->json(['error' => 'sort must be default, price_asc, price_desc, or rating.'], 400);
        }

        $allProducts = $this->products->activeProducts();
        $ratings = $this->ratingSummary($this->products->activeReviews());
        $products = [];
        foreach ($allProducts as $product) {
            $product = $this->decorate($product, $ratings);
            if ($search !== '' && mb_stripos((string) $product['name'], $search) === false) continue;
            if ($category !== '' && ($product['category']['name'] ?? '') !== $category) continue;
            if (!$this->matchesPrice($product['variants'], $minimum, $maximum)) continue;
            $products[] = $product;
        }

        $this->sortProducts($products, $sort);
        $total = count($products);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) $page = $totalPages;
        $items = array_slice($products, ($page - 1) * $pageSize, $pageSize);

        return $this->json([
            'data' => array_map(fn (array $product): array => $this->publicProduct($product, false), $items),
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => $totalPages],
        ]);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function detail(array $params, array $query): Response
    {
        $product = $this->products->productByLegacyId($params['id'] ?? '');
        if ($product === null) return $this->json(['error' => 'Product not found.'], 404);

        $allReviews = $this->products->activeReviews();
        $ratings = $this->ratingSummary($allReviews);
        $product = $this->decorate($product, $ratings);
        $reviews = array_values(array_filter(
            $allReviews,
            static fn (array $review): bool => ($review['product_id'] ?? '') === ($product['legacy_id'] ?? ''),
        ));
        usort($reviews, static fn (array $left, array $right): int => strcmp((string) ($right['legacy_id'] ?? ''), (string) ($left['legacy_id'] ?? '')));

        $related = [];
        foreach ($this->products->activeProducts() as $candidate) {
            if (($candidate['legacy_id'] ?? '') === ($product['legacy_id'] ?? '')
                || ($candidate['category']['code'] ?? '') !== ($product['category']['code'] ?? '')) continue;
            $related[] = $this->publicProduct($this->decorate($candidate, $ratings), false);
            if (count($related) === 4) break;
        }

        $data = $this->publicProduct($product, true);
        $data['reviews'] = array_map(static fn (array $review): array => [
            'reviewer_name' => (string) ($review['reviewer_name'] ?? ''),
            'rating' => (int) ($review['rating'] ?? 0),
            'content' => (string) ($review['content'] ?? ''),
            'admin_response' => is_array($review['admin_response'] ?? null)
                ? ['content' => (string) ($review['admin_response']['content'] ?? '')]
                : null,
        ], array_slice($reviews, 0, 20));
        $data['related'] = $related;
        return $this->json(['data' => $data]);
    }

    /** @param array<string, mixed> $query */
    private function numberFilter(array $query, string $name): float|null|Response
    {
        if (!array_key_exists($name, $query) || $query[$name] === '') return null;
        $value = filter_var($query[$name], FILTER_VALIDATE_FLOAT);
        if ($value === false || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 1000000000) {
            return $this->json(['error' => $name . ' must be a non-negative number.'], 400);
        }
        return (float) $value;
    }

    /** @param array<string, mixed> $query */
    private function integerFilter(array $query, string $name, int $default, int $minimum, int $maximum): int|Response
    {
        if (!array_key_exists($name, $query) || $query[$name] === '') return $default;
        $value = filter_var($query[$name], FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            return $this->json(['error' => $name . ' must be an integer between ' . $minimum . ' and ' . $maximum . '.'], 400);
        }
        return $value;
    }

    /** @param list<array<string, mixed>> $variants */
    private function matchesPrice(array $variants, ?float $minimum, ?float $maximum): bool
    {
        foreach ($variants as $variant) {
            $price = (float) ($variant['price'] ?? 0);
            if (($minimum === null || $price >= $minimum) && ($maximum === null || $price <= $maximum)) return true;
        }
        return false;
    }

    /** @param list<array<string, mixed>> $products */
    private function sortProducts(array &$products, string $sort): void
    {
        if ($sort === 'price_asc') {
            usort($products, static fn (array $left, array $right): int => $left['display_price'] <=> $right['display_price']);
        } elseif ($sort === 'price_desc') {
            usort($products, static fn (array $left, array $right): int => $right['display_price'] <=> $left['display_price']);
        } elseif ($sort === 'rating') {
            usort($products, static fn (array $left, array $right): int => [$right['rating_average'], $right['rating_count']] <=> [$left['rating_average'], $left['rating_count']]);
        } else {
            $categoryOrder = ['A' => 1, 'Q' => 2, 'G' => 3];
            usort($products, static fn (array $left, array $right): int =>
                [($categoryOrder[$left['category']['code'] ?? ''] ?? 4), (string) ($left['legacy_id'] ?? '')]
                <=> [($categoryOrder[$right['category']['code'] ?? ''] ?? 4), (string) ($right['legacy_id'] ?? '')]
            );
        }
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

    /** @param array<string, mixed> $product
     *  @return array<string, mixed>
     */
    private function publicProduct(array $product, bool $includeDescription): array
    {
        $data = [
            'id' => (string) ($product['legacy_id'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'category' => [
                'code' => (string) ($product['category']['code'] ?? ''),
                'name' => (string) ($product['category']['name'] ?? ''),
            ],
            'price' => (float) ($product['display_price'] ?? 0),
            'rating_average' => (float) ($product['rating_average'] ?? 0),
            'rating_count' => (int) ($product['rating_count'] ?? 0),
            'variants' => array_map(static fn (array $variant): array => [
                'id' => (string) ($variant['variant_id'] ?? ''),
                'size' => (string) ($variant['size'] ?? ''),
                'price' => (float) ($variant['price'] ?? 0),
                'stock' => (int) ($variant['stock'] ?? 0),
            ], $product['variants'] ?? []),
            'images' => array_map(static fn (array $image): array => [
                'url' => '/' . ltrim((string) ($image['path'] ?? ''), '/'),
                'primary' => (bool) ($image['is_primary'] ?? false),
            ], $product['images'] ?? []),
        ];
        if ($includeDescription) $data['description'] = (string) ($product['description'] ?? '');
        return $data;
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): Response
    {
        return new Response(
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            'application/json; charset=utf-8',
        );
    }
}
