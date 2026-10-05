<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BillingLineDTO;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class BillingCalculator
{
    public function calculate(int $basePriceMinor, int $includedUnits, int $rateMicros, int $usageUnits, int $durationSeconds, int $cycleSeconds): BillingLineDTO
    {
        if (min($basePriceMinor, $includedUnits, $rateMicros, $usageUnits, $durationSeconds) < 0 || $cycleSeconds <= 0 || $durationSeconds > $cycleSeconds) {
            throw new InvalidArgumentException('Invalid billing inputs.');
        }
        $base = $this->ratio($basePriceMinor, $durationSeconds, $cycleSeconds);
        $allowance = $this->ratio($includedUnits, $durationSeconds, $cycleSeconds, RoundingMode::Down);
        $overage = max(0, $usageUnits - $allowance);
        $charge = $this->ratio($overage, $rateMicros, 10000);

        return new BillingLineDTO($allowance, $usageUnits, $overage, $base, $charge, $this->sum([$base, $charge]));
    }

    public function ratio(int $value, int $numerator, int $denominator, RoundingMode $rounding = RoundingMode::HalfUp): int
    {
        return BigInteger::of($value)->multipliedBy($numerator)->dividedBy($denominator, $rounding)->toInt();
    }

    /** @param iterable<int> $values */
    public function sum(iterable $values): int
    {
        $total = BigInteger::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total->toInt();
    }
}
