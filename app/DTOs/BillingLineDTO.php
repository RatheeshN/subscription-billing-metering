<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class BillingLineDTO
{
    public function __construct(
        public int $includedUnits,
        public int $usageUnits,
        public int $overageUnits,
        public int $baseTotalMinor,
        public int $overageTotalMinor,
        public int $totalMinor,
    ) {}

    /** @return array<string, int> */
    public function toArray(): array
    {
        return ['included_units' => $this->includedUnits, 'usage_units' => $this->usageUnits, 'overage_units' => $this->overageUnits, 'base_total_minor' => $this->baseTotalMinor, 'overage_total_minor' => $this->overageTotalMinor, 'total_minor' => $this->totalMinor];
    }
}
