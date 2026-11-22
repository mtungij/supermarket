<?php

namespace App\Services\Purchasing;

use App\Support\Decimal;

/**
 * Pure calculations for goods receiving, shared by the live receiving
 * screen (preview) and GoodsReceiptService (authoritative posting).
 *
 * Landed-cost allocation method: PROPORTIONAL TO RECEIVED BASE QUANTITY.
 *   share_i = total x qty_i / sum(qty)
 * Shares are rounded to cents with the largest-remainder method, so the
 * allocated amounts always add up EXACTLY to the entered total:
 *   1. floor every share to 2 dp
 *   2. hand the leftover cents, one each, to the lines with the largest
 *      discarded remainder (ties: earlier line first)
 */
class ReceiptCalculator
{
    public const ALLOCATION_METHOD = 'received_base_quantity';

    /**
     * @param  array<int|string, string>  $weights  key => received base quantity
     * @return array<int|string, string> key => allocated amount (2 dp)
     */
    public function allocate(string $total, array $weights): array
    {
        $total = Decimal::round($total, Decimal::MONEY);
        $weights = array_filter($weights, fn ($w) => Decimal::isPositive((string) $w));
        $sum = array_reduce($weights, fn ($c, $w) => Decimal::add($c, (string) $w), '0');
        $result = array_map(fn () => '0.00', $weights);

        if (! Decimal::isPositive($total) || ! Decimal::isPositive($sum)) {
            return $result;
        }

        $remainders = [];
        $allocated = '0';
        $order = 0;

        foreach ($weights as $key => $weight) {
            $raw = Decimal::div(Decimal::mul($total, (string) $weight), $sum);
            $floor = bcadd($raw, '0', Decimal::MONEY); // truncation (raw is positive)
            $result[$key] = $floor;
            $allocated = Decimal::add($allocated, $floor);
            $remainders[] = ['key' => $key, 'rem' => Decimal::sub($raw, $floor), 'order' => $order++];
        }

        $cents = (int) Decimal::round(Decimal::mul(Decimal::sub($total, $allocated), '100'), 0);

        usort($remainders, fn ($a, $b) => Decimal::cmp($b['rem'], $a['rem']) ?: $a['order'] <=> $b['order']);

        for ($i = 0; $i < $cents; $i++) {
            $key = $remainders[$i % count($remainders)]['key'];
            $result[$key] = Decimal::add($result[$key], '0.01', Decimal::MONEY);
        }

        return $result;
    }

    /** Inventory cost per base unit, rounded half-up to 4 dp. */
    public function landedUnitCost(string $goodsValue, string $landedAmount, string $quantityBase): string
    {
        return Decimal::round(Decimal::div(Decimal::add($goodsValue, $landedAmount), $quantityBase), Decimal::COST);
    }

    /**
     * Live receiving summary.
     *
     * @param  list<array{qty_base: string, goods_value: string, location: ?string, remaining_after: string}>  $lines  valid lines only
     * @return array<string, string|int|null>
     */
    public function summary(array $lines, string $additionalCosts, string $remainingBefore, bool $singleBaseUnit): array
    {
        $received = array_filter($lines, fn ($l) => Decimal::isPositive($l['qty_base']));
        $qty = array_reduce($received, fn ($c, $l) => Decimal::add($c, $l['qty_base']), '0');
        $goods = array_reduce($received, fn ($c, $l) => Decimal::add($c, $l['goods_value']), '0');
        $costs = Decimal::round($additionalCosts, Decimal::MONEY);
        $landed = Decimal::add($goods, $costs, Decimal::MONEY);

        return [
            'selected_products' => count($received),
            'received_quantity' => Decimal::trim(Decimal::round($qty, Decimal::QTY)),
            'goods_value' => Decimal::round($goods, Decimal::MONEY),
            'additional_costs' => $costs,
            'landed_value' => $landed,
            // Per-unit landed cost is only meaningful when all lines share one base unit.
            'landed_cost_per_unit' => $singleBaseUnit && Decimal::isPositive($qty) ? Decimal::round(Decimal::div($landed, $qty), Decimal::COST) : null,
            'locations' => count(array_unique(array_filter(array_column($received, 'location')))),
            'remaining_after' => Decimal::trim(Decimal::round(Decimal::max(Decimal::sub($remainingBefore, $qty), '0'), Decimal::QTY)),
        ];
    }
}
