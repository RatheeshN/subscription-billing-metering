<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class BillingPeriod
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end)
    {
        if ($start >= $end) {
            throw new InvalidArgumentException('Billing periods must have positive duration.');
        }
    }

    public static function containing(CarbonImmutable $date, BillingCycle $cycle): self
    {
        $start = $cycle === BillingCycle::Monthly ? $date->utc()->startOfMonth() : $date->utc()->startOfYear();

        return new self($start, $cycle === BillingCycle::Monthly ? $start->addMonth() : $start->addYear());
    }

    public function seconds(): int
    {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }
}
