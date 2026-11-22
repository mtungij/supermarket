<?php

namespace App\Services\Pos;

use App\Exceptions\BusinessRuleException;
use App\Support\Decimal;

/**
 * Pure, exact sale arithmetic (shared by the POS screen and checkout).
 *
 *   gross    = round2(quantity x unit_price)
 *   taxable  = gross - discount                  (0 <= discount <= gross)
 *   prices INCLUDE tax (company setting prices_include_tax = true):
 *       tax   = round2(taxable x rate / (100 + rate));  net = taxable - tax;  total = taxable
 *   prices EXCLUDE tax:
 *       tax   = round2(taxable x rate / 100);           net = taxable;        total = taxable + tax
 *
 * Tax rates come only from the product's tax class configuration; this
 * class never assumes a rate.
 */
class CartCalculator
{
    /** @return array{gross: string, discount: string, tax: string, net: string, total: string} */
    public function line(string $quantity, string $unitPrice, string $discount, string $taxRate, bool $pricesIncludeTax, string $field = 'discount'): array
    {
        $gross = Decimal::round(Decimal::mul($quantity, $unitPrice), Decimal::MONEY);
        $discount = Decimal::round($discount === '' ? '0' : $discount, Decimal::MONEY);

        if (Decimal::isNegative($discount) || Decimal::cmp($discount, $gross) > 0) {
            throw BusinessRuleException::make($field, __('The discount must be between 0 and the line amount.'));
        }

        $taxable = Decimal::sub($gross, $discount, Decimal::MONEY);

        if ($pricesIncludeTax) {
            $tax = Decimal::round(Decimal::div(Decimal::mul($taxable, $taxRate), Decimal::add('100', $taxRate)), Decimal::MONEY);
            $net = Decimal::sub($taxable, $tax, Decimal::MONEY);
            $total = $taxable;
        } else {
            $tax = Decimal::round(Decimal::div(Decimal::mul($taxable, $taxRate), '100'), Decimal::MONEY);
            $net = $taxable;
            $total = Decimal::add($taxable, $tax, Decimal::MONEY);
        }

        return compact('gross', 'discount', 'tax', 'net', 'total');
    }

    /**
     * @param  list<array{gross: string, discount: string, tax: string, net: string, total: string}>  $lines
     * @return array{subtotal: string, discount: string, tax: string, net: string, total: string}
     */
    public function totals(array $lines): array
    {
        $sum = fn (string $key) => array_reduce($lines, fn ($c, $l) => Decimal::add($c, $l[$key], Decimal::MONEY), '0.00');

        return ['subtotal' => $sum('gross'), 'discount' => $sum('discount'), 'tax' => $sum('tax'), 'net' => $sum('net'), 'total' => $sum('total')];
    }
}
