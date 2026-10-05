<?php

namespace App\Repositories\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

interface DashboardRepositoryInterface
{
    /** @return Collection<string, int> */
    public function getDailyUsage(int $merchantId, CarbonImmutable $start, CarbonImmutable $end): Collection;

    /** @return Collection<int, array<string, int|string>> */
    public function getTopCustomersByUsage(int $merchantId, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): Collection;

    /** @return Collection<int, array<string, int|string>> */
    public function getChurnRiskCustomers(int $merchantId, CarbonImmutable $asOf): Collection;
}
