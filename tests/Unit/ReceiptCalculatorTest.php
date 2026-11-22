<?php

namespace Tests\Unit;

use App\Services\Purchasing\ReceiptCalculator;
use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

class ReceiptCalculatorTest extends TestCase
{
    public function test_allocation_by_quantity_reconciles_exactly(): void
    {
        $calc = new ReceiptCalculator;

        $this->assertSame(['a' => '33.34', 'b' => '33.33', 'c' => '33.33'], $calc->allocate('100.00', ['a' => '1', 'b' => '1', 'c' => '1']));
        $this->assertSame([1 => '100.00', 2 => '200.00'], $calc->allocate('300', [1 => '10', 2 => '20']));
        $this->assertSame([1 => '0.00', 2 => '0.01'], $calc->allocate('0.01', [1 => '1', 2 => '2']));
        $this->assertSame([], $calc->allocate('50', []));
        $this->assertSame([1 => '0.00'], $calc->allocate('0', [1 => '5']));
    }

    public function test_random_allocations_always_sum_to_the_total(): void
    {
        $calc = new ReceiptCalculator;
        mt_srand(42);

        for ($run = 0; $run < 200; $run++) {
            $total = number_format(mt_rand(1, 10_000_000) / 100, 2, '.', '');
            $weights = [];
            for ($i = 0; $i < mt_rand(1, 12); $i++) {
                $weights[$i] = number_format(mt_rand(1, 500_000) / 1000, 4, '.', '');
            }

            $shares = $calc->allocate($total, $weights);
            $sum = array_reduce($shares, fn ($c, $s) => Decimal::add($c, $s, 2), '0.00');

            $this->assertSame($total, $sum, "run {$run}");
            foreach ($shares as $s) {
                $this->assertFalse(Decimal::isNegative($s));
            }
        }
    }

    public function test_landed_unit_cost(): void
    {
        $this->assertSame('1010.0000', (new ReceiptCalculator)->landedUnitCost('10000.00', '100.00', '10'));
        $this->assertSame('333.3367', (new ReceiptCalculator)->landedUnitCost('1000.00', '0.01', '3'));
    }
}
