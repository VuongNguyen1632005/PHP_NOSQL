<?php
declare(strict_types=1);

namespace App\Checkout;

final class PricingService
{
    /** @param array<string, mixed>|null $voucher
     *  @return array{subtotal:int,shipping_fee:int,discount_amount:int,grand_total:int}
     */
    public function calculate(int $subtotal, int $shippingFee, ?array $voucher): array
    {
        $discount = 0;
        if ($voucher !== null) {
            $discount = match ($voucher['discount_type'] ?? '') {
                'percent' => (int) round($subtotal * ((float) $voucher['value'] / 100), 0, PHP_ROUND_HALF_UP),
                'cash' => (int) ($voucher['value'] ?? 0),
                'shipping' => $shippingFee,
                default => throw new CheckoutException('Loại mã giảm giá không được hỗ trợ.'),
            };
        }
        $discount = min(max(0, $discount), $subtotal + $shippingFee);
        return [
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'discount_amount' => $discount,
            'grand_total' => max(0, $subtotal + $shippingFee - $discount),
        ];
    }
}
