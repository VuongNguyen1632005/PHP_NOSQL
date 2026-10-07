<?php

declare(strict_types=1);

namespace App\Cart;

interface CartServiceInterface
{
    /** @return list<array<string, mixed>> */
    public function items(): array;

    public function lineCount(): int;

    public function add(string $productId, string $variantId, int $quantity): void;

    public function updateQuantity(string $variantId, int $quantity): void;

    public function changeVariant(string $oldVariantId, string $newVariantId): void;

    public function setSelected(string $variantId, bool $selected): void;

    public function setAllSelected(bool $selected): void;

    public function remove(string $variantId): void;

    /** @param array<string, int> $purchased Quantities keyed by variant ID. */
    public function removePurchasedForOrder(string $orderId, array $purchased): void;


    /** @param list<array<string, mixed>> $items */
    public function selectedSubtotal(array $items): float;
}
