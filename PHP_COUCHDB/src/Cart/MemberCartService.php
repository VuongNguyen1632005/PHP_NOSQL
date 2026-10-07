<?php

declare(strict_types=1);

namespace App\Cart;

use App\Catalog\ProductRepository;
use RuntimeException;

final class MemberCartService implements CartServiceInterface
{
    public function __construct(
        private readonly MemberCartRepository $carts,
        private readonly ProductRepository $products,
        private readonly string $customerId,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        $productMap = $this->productMap();
        $document = $this->carts->get($this->customerId);
        $lines = is_array($document['items'] ?? null) ? $document['items'] : [];
        $items = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $productId = (string) ($line['product_id'] ?? '');
            $variantId = (string) ($line['variant_id'] ?? '');
            $product = $productMap[$productId] ?? null;
            $variant = $product === null ? null : $this->variantById($product, $variantId);
            $available = $product !== null && $variant !== null;
            $items[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'name' => (string) ($product['name'] ?? $line['product_name_snapshot'] ?? 'Sản phẩm không còn bán'),
                'image' => (string) ($this->primaryImage($product) ?? $line['image_snapshot'] ?? ''),
                'size' => (string) ($variant['size'] ?? $line['size'] ?? ''),
                'unit_price' => (float) ($variant['price'] ?? $line['unit_price_snapshot'] ?? 0),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'stock' => (int) ($variant['stock'] ?? 0),
                'selected' => ($line['selected'] ?? false) === true,
                'available' => $available,
                'variants' => $available ? array_values(array_filter(
                    $product['variants'] ?? [],
                    static fn (array $candidate): bool => ($candidate['active'] ?? false) === true,
                )) : [],
            ];
        }
        $_SESSION['_cart_line_count'] = count($items);
        return $items;
    }

    public function lineCount(): int
    {
        $document = $this->carts->get($this->customerId);
        $count = count(is_array($document['items'] ?? null) ? $document['items'] : []);
        $_SESSION['_cart_line_count'] = $count;
        return $count;
    }

    public function add(string $productId, string $variantId, int $quantity): void
    {
        $this->assertQuantity($quantity);
        $product = $this->requireProduct($productId);
        $variant = $this->requireVariant($product, $variantId);
        $this->mutate(function (array $document) use ($product, $variant, $variantId, $quantity): array {
            $lines = $this->linesByVariant($document);
            $existing = $lines[$variantId] ?? [];
            $newQuantity = (int) ($existing['quantity'] ?? 0) + $quantity;
            $this->assertQuantity($newQuantity);
            $this->assertInStock($variant, $newQuantity);
            $lines[$variantId] = $this->cartLine($product, $variant, $newQuantity, ($existing['selected'] ?? false) === true);
            return $this->withLines($document, $lines);
        });
    }

    public function updateQuantity(string $variantId, int $quantity): void
    {
        $this->assertQuantity($quantity);
        $this->mutate(function (array $document) use ($variantId, $quantity): array {
            $lines = $this->linesByVariant($document);
            $existing = $lines[$variantId] ?? null;
            if (!is_array($existing)) {
                throw new CartInputException('Không tìm thấy dòng sản phẩm trong giỏ.');
            }
            $product = $this->requireProduct((string) ($existing['product_id'] ?? ''));
            $variant = $this->requireVariant($product, $variantId);
            $this->assertInStock($variant, $quantity);
            $lines[$variantId] = $this->cartLine($product, $variant, $quantity, ($existing['selected'] ?? false) === true);
            return $this->withLines($document, $lines);
        });
    }

    public function changeVariant(string $oldVariantId, string $newVariantId): void
    {
        $this->mutate(function (array $document) use ($oldVariantId, $newVariantId): array {
            $lines = $this->linesByVariant($document);
            $existing = $lines[$oldVariantId] ?? null;
            if (!is_array($existing)) {
                throw new CartInputException('Không tìm thấy dòng sản phẩm trong giỏ.');
            }
            if ($oldVariantId === $newVariantId) {
                return $document;
            }
            $product = $this->requireProduct((string) ($existing['product_id'] ?? ''));
            $variant = $this->requireVariant($product, $newVariantId);
            $target = $lines[$newVariantId] ?? [];
            $quantity = (int) $existing['quantity'] + (int) ($target['quantity'] ?? 0);
            $this->assertQuantity($quantity);
            $this->assertInStock($variant, $quantity);
            $selected = ($existing['selected'] ?? false) === true || (($target['selected'] ?? false) === true);
            unset($lines[$oldVariantId]);
            $lines[$newVariantId] = $this->cartLine($product, $variant, $quantity, $selected);
            return $this->withLines($document, $lines);
        });
    }

    public function setSelected(string $variantId, bool $selected): void
    {
        $this->mutate(function (array $document) use ($variantId, $selected): array {
            $lines = $this->linesByVariant($document);
            if (!isset($lines[$variantId])) {
                throw new CartInputException('Không tìm thấy dòng sản phẩm trong giỏ.');
            }
            $lines[$variantId]['selected'] = $selected;
            return $this->withLines($document, $lines);
        });
    }

    public function setAllSelected(bool $selected): void
    {
        $this->mutate(function (array $document) use ($selected): array {
            $lines = $this->linesByVariant($document);
            $products = $this->productMap();
            foreach ($lines as $variantId => &$line) {
                $product = $products[(string) ($line['product_id'] ?? '')] ?? null;
                $available = $product !== null && $this->variantById($product, (string) $variantId) !== null;
                $line['selected'] = $selected && $available;
            }
            unset($line);
            return $this->withLines($document, $lines);
        });
    }

    public function remove(string $variantId): void
    {
        $this->mutate(function (array $document) use ($variantId): array {
            $lines = $this->linesByVariant($document);
            unset($lines[$variantId]);
            return $this->withLines($document, $lines);
        });
    }

    public function removePurchasedForOrder(string $orderId, array $purchased): void
    {
        $this->mutate(function (array $document) use ($orderId, $purchased): array {
            $document['meta']['completed_checkouts'] = is_array($document['meta']['completed_checkouts'] ?? null) ? $document['meta']['completed_checkouts'] : [];
            if (isset($document['meta']['completed_checkouts'][$orderId])) return $document;
            $lines = $this->linesByVariant($document);
            foreach ($purchased as $variantId => $quantity) {
                if (!isset($lines[$variantId])) {
                    continue;
                }
                $remaining = (int) ($lines[$variantId]['quantity'] ?? 0) - max(0, $quantity);
                if ($remaining > 0) {
                    $lines[$variantId]['quantity'] = $remaining;
                } else {
                    unset($lines[$variantId]);
                }
            }
            $document['meta']['completed_checkouts'][$orderId] = gmdate('c');
            return $this->withLines($document, $lines);
        });
    }

    /** @param list<array<string, mixed>> $items */
    public function selectedSubtotal(array $items): float
    {
        $subtotal = 0.0;
        foreach ($items as $item) {
            if (($item['selected'] ?? false) && ($item['available'] ?? false)) {
                $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
            }
        }
        return $subtotal;
    }

    /** @param list<array<string, mixed>> $guestItems
     *  @return array{merged: int, warnings: int}
     */
    public function mergeGuestItems(array $guestItems): array
    {
        if ($guestItems === []) {
            return ['merged' => 0, 'warnings' => 0];
        }
        $productMap = $this->productMap();
        $warnings = 0;
        $merged = 0;
        $this->mutate(function (array $document) use ($guestItems, $productMap, &$warnings, &$merged): array {
            $warnings = 0;
            $merged = 0;
            $lines = $this->linesByVariant($document);
            foreach ($guestItems as $guest) {
                if (($guest['available'] ?? false) !== true) {
                    ++$warnings;
                    continue;
                }
                $productId = (string) ($guest['product_id'] ?? '');
                $variantId = (string) ($guest['variant_id'] ?? '');
                $product = $productMap[$productId] ?? null;
                $variant = $product === null ? null : $this->variantById($product, $variantId);
                if ($product === null || $variant === null || (int) ($variant['stock'] ?? 0) < 1) {
                    ++$warnings;
                    continue;
                }
                $existing = $lines[$variantId] ?? [];
                $requested = (int) ($existing['quantity'] ?? 0) + (int) ($guest['quantity'] ?? 0);
                $quantity = min($requested, (int) $variant['stock'], 999);
                if ($quantity < $requested) {
                    ++$warnings;
                }
                if ($quantity < 1) {
                    continue;
                }
                $lines[$variantId] = $this->cartLine(
                    $product,
                    $variant,
                    $quantity,
                    ($existing['selected'] ?? false) === true || ($guest['selected'] ?? false) === true,
                );
                ++$merged;
            }
            return $this->withLines($document, $lines);
        });
        return ['merged' => $merged, 'warnings' => $warnings];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function mutate(callable $change): array
    {
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $document = $this->carts->get($this->customerId) ?? $this->newDocument();
            $updated = $change($document);
            $updated['updated_at'] = gmdate('c');
            $response = $this->carts->put($this->customerId, $updated);
            if ($response->statusCode === 409) {
                continue;
            }
            if ($response->statusCode < 200 || $response->statusCode >= 300) {
                throw new RuntimeException('CouchDB could not save the member cart (' . $response->statusCode . ').');
            }
            $_SESSION['_cart_line_count'] = count(is_array($updated['items'] ?? null) ? $updated['items'] : []);
            return $updated;
        }
        throw new RuntimeException('The member cart changed repeatedly; reload the page and retry.');
    }

    /** @return array<string, mixed> */
    private function newDocument(): array
    {
        return [
            '_id' => 'cart:' . $this->customerId,
            'type' => 'cart',
            'schema_version' => 2,
            'customer_id' => $this->customerId,
            'items' => [],
            'updated_at' => null,
            'meta' => ['source' => 'PHP member cart'],
        ];
    }

    /** @param array<string, mixed> $document
     *  @return array<string, mixed>
     */
    private function withLines(array $document, array $lines): array
    {
        $document['items'] = array_values($lines);
        return $document;
    }

    /** @param array<string, mixed> $document
     *  @return array<string, array<string, mixed>>
     */
    private function linesByVariant(array $document): array
    {
        $lines = [];
        foreach (is_array($document['items'] ?? null) ? $document['items'] : [] as $line) {
            if (is_array($line) && is_string($line['variant_id'] ?? null)) {
                $lines[$line['variant_id']] = $line;
            }
        }
        return $lines;
    }

    /** @return array<string, array<string, mixed>> */
    private function productMap(): array
    {
        $products = [];
        foreach ($this->products->activeProducts() as $product) {
            $products[(string) ($product['legacy_id'] ?? '')] = $product;
        }
        return $products;
    }

    /** @param array<string, mixed> $product */
    private function requireProduct(string $productId): array
    {
        $product = $this->products->productByLegacyId($productId);
        if ($product === null) {
            throw new CartInputException('Sản phẩm không tồn tại hoặc đã ngừng kinh doanh.');
        }
        return $product;
    }

    /** @param array<string, mixed> $product
     *  @return array<string, mixed>
     */
    private function requireVariant(array $product, string $variantId): array
    {
        $variant = $this->variantById($product, $variantId);
        if ($variant === null) {
            throw new CartInputException('Kích thước này không còn khả dụng.');
        }
        return $variant;
    }

    /** @param array<string, mixed> $product
     *  @return array<string, mixed>|null
     */
    private function variantById(array $product, string $variantId): ?array
    {
        foreach ($product['variants'] ?? [] as $variant) {
            if (($variant['variant_id'] ?? null) === $variantId && ($variant['active'] ?? false) === true) {
                return $variant;
            }
        }
        return null;
    }

    /** @param array<string, mixed>|null $product */
    private function primaryImage(?array $product): ?string
    {
        foreach ($product['images'] ?? [] as $image) {
            if (($image['active'] ?? false) === true && ($image['is_primary'] ?? false) === true) {
                return (string) ($image['path'] ?? '');
            }
        }
        return null;
    }

    /** @param array<string, mixed> $product
     *  @param array<string, mixed> $variant
     *  @return array<string, mixed>
     */
    private function cartLine(array $product, array $variant, int $quantity, bool $selected): array
    {
        return [
            'product_id' => (string) $product['legacy_id'],
            'product_name_snapshot' => (string) $product['name'],
            'image_snapshot' => $this->primaryImage($product),
            'variant_id' => (string) $variant['variant_id'],
            'size' => (string) $variant['size'],
            'quantity' => $quantity,
            'unit_price_snapshot' => (float) $variant['price'],
            'selected' => $selected,
        ];
    }

    /** @param array<string, mixed> $variant */
    private function assertInStock(array $variant, int $quantity): void
    {
        $stock = (int) ($variant['stock'] ?? 0);
        if ($quantity > $stock) {
            throw new CartInputException('Số lượng vượt quá tồn kho hiện tại (' . $stock . ').');
        }
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > 999) {
            throw new CartInputException('Số lượng phải từ 1 đến 999.');
        }
    }
}
