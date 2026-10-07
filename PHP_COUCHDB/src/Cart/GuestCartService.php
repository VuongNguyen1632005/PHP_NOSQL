<?php

declare(strict_types=1);

namespace App\Cart;

use App\Catalog\ProductRepository;

final class GuestCartService implements CartServiceInterface
{
    private const SESSION_KEY = 'guest_cart';

    public function __construct(private readonly ProductRepository $products)
    {
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        $productsById = [];
        foreach ($this->products->activeProducts() as $product) {
            $productsById[(string) ($product['legacy_id'] ?? '')] = $product;
        }

        $items = [];
        foreach ($this->cart() as $variantId => $saved) {
            $product = $productsById[(string) ($saved['product_id'] ?? '')] ?? null;
            $variant = $product === null ? null : $this->variantById($product, (string) $variantId);
            $available = $product !== null && $variant !== null;
            $image = $product['images'][0]['path'] ?? ($saved['image_snapshot'] ?? '');
            $items[] = [
                'product_id' => (string) ($saved['product_id'] ?? ''),
                'variant_id' => (string) $variantId,
                'name' => (string) ($product['name'] ?? $saved['name_snapshot'] ?? 'Sản phẩm không còn bán'),
                'image' => (string) $image,
                'size' => (string) ($variant['size'] ?? $saved['size_snapshot'] ?? ''),
                'unit_price' => (float) ($variant['price'] ?? $saved['price_snapshot'] ?? 0),
                'quantity' => (int) ($saved['quantity'] ?? 0),
                'stock' => (int) ($variant['stock'] ?? 0),
                'selected' => ($saved['selected'] ?? false) === true,
                'available' => $available,
                'variants' => $available ? array_values(array_filter(
                    $product['variants'] ?? [],
                    static fn (array $candidate): bool => ($candidate['active'] ?? false) === true,
                )) : [],
            ];
        }
        return $items;
    }

    public function lineCount(): int
    {
        return count($this->cart());
    }

    public function add(string $productId, string $variantId, int $quantity): void
    {
        $this->assertQuantity($quantity);
        $product = $this->requireProduct($productId);
        $variant = $this->requireVariant($product, $variantId);
        $cart = $this->cart();
        $newQuantity = (int) ($cart[$variantId]['quantity'] ?? 0) + $quantity;
        $this->assertQuantity($newQuantity);
        $this->assertInStock($variant, $newQuantity);

        $existing = $cart[$variantId] ?? [];
        $cart[$variantId] = $this->savedLine($product, $variant, $newQuantity, ($existing['selected'] ?? false) === true);
        $this->save($cart);
    }

    public function updateQuantity(string $variantId, int $quantity): void
    {
        $this->assertQuantity($quantity);
        $cart = $this->cart();
        $saved = $cart[$variantId] ?? null;
        if (!is_array($saved)) {
            throw new CartInputException('Không tìm thấy dòng sản phẩm trong giỏ.');
        }
        $product = $this->requireProduct((string) ($saved['product_id'] ?? ''));
        $variant = $this->requireVariant($product, $variantId);
        $this->assertInStock($variant, $quantity);
        $cart[$variantId] = $this->savedLine($product, $variant, $quantity, ($saved['selected'] ?? false) === true);
        $this->save($cart);
    }

    public function changeVariant(string $oldVariantId, string $newVariantId): void
    {
        $cart = $this->cart();
        $saved = $cart[$oldVariantId] ?? null;
        if (!is_array($saved)) {
            throw new CartInputException('Không tìm thấy dòng sản phẩm trong giỏ.');
        }
        if ($oldVariantId === $newVariantId) {
            return;
        }
        $product = $this->requireProduct((string) ($saved['product_id'] ?? ''));
        $variant = $this->requireVariant($product, $newVariantId);
        $quantity = (int) $saved['quantity'] + (int) ($cart[$newVariantId]['quantity'] ?? 0);
        $this->assertQuantity($quantity);
        $this->assertInStock($variant, $quantity);
        $selected = ($saved['selected'] ?? false) === true || (($cart[$newVariantId]['selected'] ?? false) === true);
        unset($cart[$oldVariantId]);
        $cart[$newVariantId] = $this->savedLine($product, $variant, $quantity, $selected);
        $this->save($cart);
    }

    public function setSelected(string $variantId, bool $selected): void
    {
        $cart = $this->cart();
        if (!isset($cart[$variantId])) {
            throw new CartInputException('Không tìm thấy dòng sản phẩm trong giỏ.');
        }
        $cart[$variantId]['selected'] = $selected;
        $this->save($cart);
    }

    public function setAllSelected(bool $selected): void
    {
        $available = [];
        foreach ($this->items() as $item) {
            if (($item['available'] ?? false) === true) {
                $available[(string) $item['variant_id']] = true;
            }
        }
        $cart = $this->cart();
        foreach ($cart as $variantId => &$line) {
            $line['selected'] = $selected && isset($available[(string) $variantId]);
        }
        unset($line);
        $this->save($cart);
    }

    public function remove(string $variantId): void
    {
        $cart = $this->cart();
        unset($cart[$variantId]);
        $this->save($cart);
    }

    public function removePurchasedForOrder(string $orderId, array $purchased): void
    {
        $_SESSION['_completed_cart_orders'] = is_array($_SESSION['_completed_cart_orders'] ?? null) ? $_SESSION['_completed_cart_orders'] : [];
        if (isset($_SESSION['_completed_cart_orders'][$orderId])) return;
        $cart = $this->cart();
        foreach ($purchased as $variantId => $quantity) {
            if (!isset($cart[$variantId])) {
                continue;
            }
            $remaining = (int) ($cart[$variantId]['quantity'] ?? 0) - max(0, $quantity);
            if ($remaining > 0) {
                $cart[$variantId]['quantity'] = $remaining;
            } else {
                unset($cart[$variantId]);
            }
        }
        $this->save($cart);
        $_SESSION['_completed_cart_orders'][$orderId] = gmdate('c');
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

    /** @return array<string, array<string, mixed>> */
    private function cart(): array
    {
        $cart = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($cart) ? $cart : [];
    }

    /** @param array<string, array<string, mixed>> $cart */
    private function save(array $cart): void
    {
        $_SESSION[self::SESSION_KEY] = $cart;
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
        foreach ($product['variants'] ?? [] as $variant) {
            if (($variant['variant_id'] ?? null) === $variantId && ($variant['active'] ?? false) === true) {
                return $variant;
            }
        }
        throw new CartInputException('Kích thước này không còn khả dụng.');
    }

    /** @param array<string, mixed> $product
     *  @param array<string, mixed> $variant
     *  @return array<string, mixed>
     */
    private function savedLine(array $product, array $variant, int $quantity, bool $selected): array
    {
        $image = '';
        foreach ($product['images'] ?? [] as $candidate) {
            if (($candidate['active'] ?? false) === true && ($candidate['is_primary'] ?? false) === true) {
                $image = (string) ($candidate['path'] ?? '');
                break;
            }
        }
        return [
            'product_id' => (string) $product['legacy_id'],
            'variant_id' => (string) $variant['variant_id'],
            'quantity' => $quantity,
            'selected' => $selected,
            'name_snapshot' => (string) $product['name'],
            'image_snapshot' => $image,
            'size_snapshot' => (string) $variant['size'],
            'price_snapshot' => (float) $variant['price'],
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
}
