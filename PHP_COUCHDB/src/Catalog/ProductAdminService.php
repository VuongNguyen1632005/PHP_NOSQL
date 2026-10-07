<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Infrastructure\CouchDB\CouchDbClient;
use RuntimeException;

final class ProductAdminService
{
    private const CATEGORIES = ['A' => 'Áo', 'Q' => 'Quần', 'G' => 'Giày'];

    public function __construct(
        private readonly CouchDbClient $client,
        private readonly string $database,
        private readonly ProductImageStorage $imageStorage,
    )
    {
    }

    /** @return list<array<string, mixed>> */
    public function allProducts(): array
    {
        $response = $this->client->request('POST', rawurlencode($this->database) . '/_find', [
            'selector' => ['type' => 'product'],
            'use_index' => ['_design/catalog_indexes', 'admin_products'],
            'limit' => 2000,
            'fields' => ['_id', '_rev', 'type', 'legacy_id', 'name', 'description', 'category', 'variants', 'images', 'active', 'meta'],
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB could not load admin products (' . $response->statusCode . ').');
        }
        $docs = $response->json()['docs'] ?? null;
        if (!is_array($docs)) throw new RuntimeException('CouchDB product list is invalid.');
        $products = array_values(array_filter($docs, 'is_array'));
        usort($products, static fn (array $a, array $b): int => strcmp((string) ($b['legacy_id'] ?? ''), (string) ($a['legacy_id'] ?? '')));
        return $products;
    }

    /** @return array<string, mixed>|null */
    public function get(string $legacyId): ?array
    {
        if ($legacyId === '' || strlen($legacyId) > 120) return null;
        $response = $this->client->request('GET', rawurlencode($this->database) . '/' . rawurlencode('product:' . $legacyId));
        if ($response->statusCode === 404) return null;
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException('CouchDB could not load product (' . $response->statusCode . ').');
        }
        $product = $response->json();
        return ($product['type'] ?? null) === 'product' ? $product : null;
    }

    /** @return array<string, mixed> */
    public function save(?string $legacyId, array $input, string $staffId, ?array $imageUpload = null): array
    {
        $old = $legacyId === null ? null : $this->get($legacyId);
        if ($legacyId !== null && $old === null) throw new RuntimeException('Không tìm thấy sản phẩm để cập nhật.');
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $categoryCode = (string) ($input['category_code'] ?? '');
        if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Tên sản phẩm phải có từ 1 đến 160 ký tự.');
        if (mb_strlen($description) > 5000) throw new RuntimeException('Mô tả không được vượt quá 5000 ký tự.');
        if (!isset(self::CATEGORIES[$categoryCode])) throw new RuntimeException('Danh mục không hợp lệ.');

        $rawVariants = json_decode((string) ($input['variants_json'] ?? ''), true);
        if (!is_array($rawVariants) || !array_is_list($rawVariants) || count($rawVariants) < 1 || count($rawVariants) > 20) {
            throw new RuntimeException('Danh sách biến thể phải là JSON gồm 1 đến 20 dòng hợp lệ.');
        }
        $existing = [];
        $existingBySize = [];
        foreach (($old['variants'] ?? []) as $variant) {
            if (is_array($variant) && isset($variant['variant_id'])) {
                $existing[(string) $variant['variant_id']] = $variant;
                $existingBySize[mb_strtolower((string) ($variant['size'] ?? ''))] = $variant;
            }
        }
        $variants = [];
        $seenSizes = [];
        $seenIds = [];
        foreach ($rawVariants as $row) {
            if (!is_array($row)) throw new RuntimeException('Mỗi biến thể phải là một đối tượng JSON.');
            $size = trim((string) ($row['size'] ?? ''));
            $priceRaw = $row['price'] ?? null;
            $stockRaw = $row['stock'] ?? null;
            $activeRaw = $row['active'] ?? true;
            if ($size === '' || mb_strlen($size) > 20) throw new RuntimeException('Size phải có từ 1 đến 20 ký tự.');
            $sizeKey = mb_strtolower($size);
            if (isset($seenSizes[$sizeKey])) throw new RuntimeException('Không được khai báo trùng size.');
            $seenSizes[$sizeKey] = true;
            if (!is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 1000000000) throw new RuntimeException('Giá bán phải là số từ 0 đến 1.000.000.000.');
            if (filter_var($stockRaw, FILTER_VALIDATE_INT) === false || (int) $stockRaw < 0) throw new RuntimeException('Tồn kho phải là số nguyên không âm.');
            if (!is_bool($activeRaw)) throw new RuntimeException('Trường active của biến thể phải là true hoặc false.');
            $id = trim((string) ($row['variant_id'] ?? ''));
            if ($old !== null) {
                if ($id === '' && isset($existingBySize[$sizeKey])) $id = (string) $existingBySize[$sizeKey]['variant_id'];
                if ($id !== '' && !isset($existing[$id])) throw new RuntimeException('Không được tự thay đổi mã biến thể đã tồn tại.');
                if ($id !== '' && mb_strtolower((string) ($existing[$id]['size'] ?? '')) !== $sizeKey) throw new RuntimeException('Không được đổi size của biến thể đã tồn tại.');
            }
            if ($id === '') $id = 'CT' . ($legacyId ?? 'NEW') . '-' . strtoupper(bin2hex(random_bytes(5)));
            if (isset($seenIds[$id])) throw new RuntimeException('Mã biến thể bị trùng.');
            $seenIds[$id] = true;
            $variants[] = [
                'variant_id' => $id, 'size' => $size,
                'price' => (float) $priceRaw, 'stock' => (int) $stockRaw, 'active' => $activeRaw,
            ];
        }
        if ($old !== null) {
            foreach ($existing as $id => $_) if (!isset($seenIds[$id])) throw new RuntimeException('Không được xóa biến thể cũ vì có thể đã được giỏ hàng hoặc đơn hàng tham chiếu; hãy đặt active=false.');
        }

        $id = $legacyId ?? ('P' . strtoupper(bin2hex(random_bytes(6))));
        $storedImage = $this->imageStorage->store($imageUpload);
        $now = gmdate('c');
        $images = is_array($old['images'] ?? null) ? $old['images'] : [[
            'legacy_id' => $id . 'H01', 'path' => 'assets/img/resourse/product-placeholder.png', 'is_primary' => true, 'active' => true,
        ]];
        if ($storedImage !== null) {
            foreach ($images as &$existingImage) {
                if (is_array($existingImage)) $existingImage['is_primary'] = false;
            }
            unset($existingImage);
            array_unshift($images, [
                'legacy_id' => $id . 'H' . strtoupper(substr($storedImage['filename'], 0, 12)),
                'path' => $storedImage['path'], 'is_primary' => true, 'active' => true,
            ]);
        }
        $product = array_merge($old ?? [], [
            '_id' => 'product:' . $id,
            'type' => 'product', 'schema_version' => 2, 'legacy_id' => $id,
            'name' => $name, 'description' => $description,
            'category' => ['code' => $categoryCode, 'name' => self::CATEGORIES[$categoryCode]],
            'variants' => $variants,
            'images' => $images,
            'active' => $old === null ? true : (bool) ($old['active'] ?? true),
            'meta' => array_merge(is_array($old['meta'] ?? null) ? $old['meta'] : [], [
                'admin_updated_at' => $now, 'admin_updated_by' => $staffId,
            ]),
        ]);
        try {
            return $this->put($product);
        } catch (RuntimeException $exception) {
            if ($storedImage !== null) $this->imageStorage->remove($storedImage['filename']);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function softDelete(string $legacyId, string $staffId): array
    {
        $product = $this->get($legacyId);
        if ($product === null) throw new RuntimeException('Không tìm thấy sản phẩm.');
        if (($product['active'] ?? false) === false) return $product;
        $product['active'] = false;
        $product['meta'] = array_merge(is_array($product['meta'] ?? null) ? $product['meta'] : [], [
            'admin_deleted_at' => gmdate('c'), 'admin_deleted_by' => $staffId,
        ]);
        return $this->put($product);
    }

    /** @return array<string, mixed> */
    public function restore(string $legacyId, string $staffId): array
    {
        $product = $this->get($legacyId);
        if ($product === null) throw new RuntimeException('Không tìm thấy sản phẩm.');
        $product['active'] = true;
        $product['meta'] = array_merge(is_array($product['meta'] ?? null) ? $product['meta'] : [], [
            'admin_restored_at' => gmdate('c'), 'admin_restored_by' => $staffId,
        ]);
        return $this->put($product);
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function put(array $product): array
    {
        $response = $this->client->request('PUT', rawurlencode($this->database) . '/' . rawurlencode((string) $product['_id']), $product);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new RuntimeException($response->statusCode === 409 ? 'Sản phẩm vừa được thay đổi ở nơi khác. Hãy tải lại trang rồi thử lại.' : 'Không thể lưu sản phẩm (HTTP ' . $response->statusCode . ').');
        }
        $result = $response->json();
        $product['_rev'] = $result['rev'] ?? $product['_rev'] ?? '';
        return $product;
    }
}
