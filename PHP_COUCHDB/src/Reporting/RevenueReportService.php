<?php
declare(strict_types=1);

namespace App\Reporting;

use App\Checkout\CheckoutRepository;

final class RevenueReportService
{
    public function __construct(private readonly CheckoutRepository $orders)
    {
    }

    /** @return array{revenue_vnd:int,delivered_orders:int,processing_orders:int,cancelled_orders:int,total_orders:int,completion_rate:float,latest_delivered_orders:list<array<string,mixed>>} */
    public function report(): array
    {
        $revenue = 0;
        $delivered = 0;
        $processing = 0;
        $cancelled = 0;
        $total = 0;
        $latestDelivered = [];
        $processingStatuses = ['pending', 'confirmed', 'packing', 'shipping'];

        foreach ($this->orders->iterateOrdersForStaff() as $order) {
            ++$total;
            $status = (string) ($order['status'] ?? '');
            if ($status === 'delivered') {
                ++$delivered;
                $revenue += max(0, (int) ($order['totals']['grand_total'] ?? 0));
                $latestDelivered[] = [
                    'id' => (string) ($order['_id'] ?? ''),
                    'legacy_id' => (string) ($order['legacy_id'] ?? $order['_id'] ?? ''),
                    'customer_name' => (string) ($order['receiver']['name'] ?? $order['customer']['name'] ?? 'Khách vãng lai'),
                    'ordered_at' => (string) ($order['ordered_at'] ?? ''),
                    'grand_total' => max(0, (int) ($order['totals']['grand_total'] ?? 0)),
                    'paid' => ($order['payment']['paid'] ?? false) === true || ($order['payment']['status'] ?? null) === 'paid',
                ];
            } elseif ($status === 'cancelled') {
                ++$cancelled;
            } elseif (in_array($status, $processingStatuses, true)) {
                ++$processing;
            }
        }

        usort($latestDelivered, static fn (array $left, array $right): int => strcmp($right['ordered_at'], $left['ordered_at']));
        $latestDelivered = array_slice($latestDelivered, 0, 100);

        return [
            'revenue_vnd' => $revenue,
            'delivered_orders' => $delivered,
            'processing_orders' => $processing,
            'cancelled_orders' => $cancelled,
            'total_orders' => $total,
            'completion_rate' => $total > 0 ? round($delivered / $total * 100, 1) : 0.0,
            'latest_delivered_orders' => $latestDelivered,
        ];
    }
}
