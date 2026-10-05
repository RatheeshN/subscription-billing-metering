<?php

namespace Tests\Feature\Database;

use App\Models\Merchant;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Database\Seeders\BillingDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BillingDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_data_contains_two_tenants_invoices_and_churn_examples_and_is_safe_to_rerun(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));

        $this->seed(BillingDemoSeeder::class);
        $this->seed(BillingDemoSeeder::class);

        $this->assertDatabaseCount('merchants', 2);
        $this->assertDatabaseCount('customers', 15);
        $this->assertDatabaseCount('invoices', 30);
        $merchant = Merchant::query()->where('name', 'Demo SaaS')->firstOrFail();
        $dashboard = $this->app->make(DashboardService::class)->get($merchant->id);
        $this->assertSame(5, $dashboard['top_customers']->count());
        $this->assertSame(2, $dashboard['churn_risk_customers']->count());
    }
}
