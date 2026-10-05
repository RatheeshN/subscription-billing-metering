<?php

namespace Tests\Unit\Services;

use App\Services\BillingCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingCalculatorTest extends TestCase
{
    #[DataProvider('billingCases')]
    public function test_calculates_integer_money_and_prorated_allowances(int $base, int $included, int $rate, int $usage, int $duration, int $cycle, array $expected): void
    {
        $result = (new BillingCalculator)->calculate($base, $included, $rate, $usage, $duration, $cycle);

        $this->assertSame($expected, [$result->baseTotalMinor, $result->includedUnits, $result->overageUnits, $result->overageTotalMinor, $result->totalMinor]);
    }

    public static function billingCases(): array
    {
        return [
            'below allowance' => [10000, 100, 10000, 50, 30, 30, [10000, 100, 0, 0, 10000]],
            'exact allowance' => [10000, 100, 10000, 100, 30, 30, [10000, 100, 0, 0, 10000]],
            'overage' => [10000, 100, 10000, 150, 30, 30, [10000, 100, 50, 50, 10050]],
            'mid cycle' => [10000, 100, 10000, 80, 15, 30, [5000, 50, 30, 30, 5030]],
            'half up cents and floor allowance' => [101, 1, 5000, 1, 15, 30, [51, 0, 1, 1, 52]],
            'sub cent rates accumulate before rounding' => [0, 0, 100, 150, 30, 30, [0, 0, 150, 2, 2]],
            'zero rate and allowance' => [0, 0, 0, 100, 30, 30, [0, 0, 100, 0, 0]],
            'empty segment' => [10000, 100, 10000, 0, 0, 30, [0, 0, 0, 0, 0]],
            'large intermediate product' => [1000000000000, 1000000000000, 10000, 0, 2678400, 2678400, [1000000000000, 1000000000000, 0, 0, 1000000000000]],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_rejects_invalid_billing_inputs(array $inputs): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new BillingCalculator)->calculate(...$inputs);
    }

    public static function invalidCases(): array
    {
        return ['negative money' => [[-1, 0, 0, 0, 1, 1]], 'negative allowance' => [[1, -1, 0, 0, 1, 1]], 'negative rate' => [[1, 0, -1, 0, 1, 1]], 'negative usage' => [[1, 0, 0, -1, 1, 1]], 'invalid cycle' => [[1, 0, 0, 0, 1, 0]], 'too long segment' => [[1, 0, 0, 0, 2, 1]]];
    }
}
