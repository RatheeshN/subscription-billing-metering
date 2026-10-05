<?php

namespace Tests\Feature\Services;

use App\Models\Customer;
use App\Models\DailyUsageAggregate;
use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function segment(Merchant $merchant, int $allowance = 100): SubscriptionSegment
    {
        $customer = Customer::factory()->for($merchant)->create();
        $subscription = Subscription::factory()->for($customer)->create(['starts_at' => '2026-08-01', 'cycle_starts_at' => '2026-09-01', 'cycle_ends_at' => '2026-10-01']);

        return SubscriptionSegment::factory()->for($subscription)->create(['included_usage_units' => $allowance]);
    }

    private function aggregate(SubscriptionSegment $segment, string $date, int $units): void
    {
        DailyUsageAggregate::factory()->for($segment, 'subscriptionSegment')->create(['usage_date' => $date, 'units' => $units]);
    }

    public function test_top_five_customers_are_ranked_using_monthly_aggregates_and_scoped_to_tenant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16', 'UTC'));
        $merchant = Merchant::factory()->create();
        $expected = [];
        foreach ([10, 60, 20, 50, 30, 40] as $units) {
            $segment = $this->segment($merchant);
            $this->aggregate($segment, '2026-09-01', $units);
            $expected[$segment->subscription->customer_id] = $units;
        }
        $foreign = $this->segment(Merchant::factory()->create());
        $this->aggregate($foreign, '2026-09-01', 999999);
        arsort($expected);

        $dashboard = $this->app->make(DashboardService::class)->get($merchant->id);

        $this->assertSame(array_slice(array_keys($expected), 0, 5), $dashboard['top_customers']->pluck('customer_id')->all());
        $this->assertSame([60, 50, 40, 30, 20], $dashboard['top_customers']->pluck('usage_units')->all());
    }

    public function test_projection_extrapolates_current_segment_usage_and_keeps_currency_totals_separate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16', 'UTC'));
        $merchant = Merchant::factory()->create();
        $segment = $this->segment($merchant);
        $segment->update(['starts_at' => '2026-09-01']);
        $this->aggregate($segment, '2026-09-10', 100);
        $usd = $this->segment($merchant);
        $usd->subscription->update(['currency' => 'USD']);
        $usd->update(['starts_at' => '2026-09-01']);
        $this->aggregate($usd, '2026-09-10', 150);

        $dashboard = $this->app->make(DashboardService::class)->get($merchant->id);

        $this->assertSame(['INR' => 100, 'USD' => 200], $dashboard['projected_overage_revenue']);
    }

    public function test_churn_compares_completed_months_and_excludes_exact_50_percent_and_zero_prior_usage(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15', 'UTC'));
        $merchant = Merchant::factory()->create();
        $risk = $this->segment($merchant);
        $this->aggregate($risk, '2026-08-01', 100);
        $this->aggregate($risk, '2026-09-01', 49);
        $this->aggregate($risk, '2026-10-01', 999);
        $exact = $this->segment($merchant);
        $this->aggregate($exact, '2026-08-01', 100);
        $this->aggregate($exact, '2026-09-01', 50);
        $belowThreshold = $this->segment($merchant);
        $this->aggregate($belowThreshold, '2026-08-01', 100);
        $this->aggregate($belowThreshold, '2026-09-01', 51);
        $zero = $this->segment($merchant);
        $this->aggregate($zero, '2026-09-01', 10);
        $stopped = $this->segment($merchant);
        $this->aggregate($stopped, '2026-08-01', 10);

        $dashboard = $this->app->make(DashboardService::class)->get($merchant->id);

        $this->assertSame([$risk->subscription->customer_id, $stopped->subscription->customer_id], $dashboard['churn_risk_customers']->pluck('customer_id')->all());
    }

    public function test_projection_includes_subscriptions_across_chunk_boundaries_without_reading_raw_events(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16', 'UTC'));
        $merchant = Merchant::factory()->create();
        for ($index = 0; $index < 101; $index++) {
            $segment = $this->segment($merchant);
            $segment->update(['starts_at' => '2026-09-01']);
            $this->aggregate($segment, '2026-09-10', 100);
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $dashboard = $this->app->make(DashboardService::class)->get($merchant->id);

        $this->assertSame(['INR' => 10100], $dashboard['projected_overage_revenue']);
        $this->assertSame(5, $dashboard['top_customers']->count());
        $this->assertStringNotContainsString('usage_events', implode('\n', $queries));
    }

    public function test_dashboard_endpoint_returns_404_for_another_merchant(): void
    {
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);
        $other = Merchant::factory()->create();

        $this->getJson('/api/merchants/'.$other->id.'/dashboard', ['Authorization' => 'Bearer '.$token])->assertNotFound();
    }

    public function test_dashboard_endpoint_returns_expected_panels(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16', 'UTC'));
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);
        $segment = $this->segment($merchant);
        $this->aggregate($segment, '2026-09-01', 10);

        $this->getJson('/api/merchants/'.$merchant->id.'/dashboard', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonCount(1, 'top_customers')->assertJsonPath('usage_source', 'daily_usage_aggregates')->assertJsonPath('top_customers.0.usage_units', 10);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/merchants/1/dashboard')->assertUnauthorized();
    }

    public function test_churn_risk_excludes_other_merchants_and_customers_with_no_usage_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15', 'UTC'));
        $merchant = Merchant::factory()->create();
        $this->segment($merchant);
        $foreign = $this->segment(Merchant::factory()->create());
        $this->aggregate($foreign, '2026-08-01', 100);
        $this->aggregate($foreign, '2026-09-01', 49);

        $dashboard = $this->app->make(DashboardService::class)->get($merchant->id);

        $this->assertSame([], $dashboard['churn_risk_customers']->all());
        $this->assertSame(['INR' => 0], $dashboard['projected_overage_revenue']);
    }

    public function test_projection_uses_actual_closed_segment_usage_and_extrapolates_only_the_new_segment(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21', 'UTC'));
        $merchant = Merchant::factory()->create();
        $old = $this->segment($merchant);
        $old->update(['starts_at' => '2026-09-01', 'ends_at' => '2026-09-11', 'included_usage_units' => 300, 'overage_rate_micros' => 10000]);
        $new = SubscriptionSegment::factory()->for($old->subscription)->create(['starts_at' => '2026-09-11', 'included_usage_units' => 600, 'overage_rate_micros' => 20000]);
        $this->aggregate($old, '2026-09-10', 150);
        $this->aggregate($new, '2026-09-20', 300);
        $old->plan->update(['included_usage_units' => 999999, 'overage_rate_micros' => 999999]);

        $dashboard = $this->app->make(DashboardService::class)->get($merchant->id);

        $this->assertSame(['INR' => 450], $dashboard['projected_overage_revenue']);
        $this->assertSame(450, $dashboard['top_customers']->sole()['usage_units']);
    }

    public function test_projection_at_the_exact_plan_change_timestamp_has_no_division_by_zero(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16', 'UTC'));
        $merchant = Merchant::factory()->create();
        $old = $this->segment($merchant);
        $old->update(['starts_at' => '2026-09-01', 'ends_at' => '2026-09-16']);
        SubscriptionSegment::factory()->for($old->subscription)->create(['starts_at' => '2026-09-16']);
        $this->aggregate($old, '2026-09-15', 80);

        $dashboard = $this->app->make(DashboardService::class)->get($merchant->id);

        $this->assertSame(['INR' => 30], $dashboard['projected_overage_revenue']);
    }

    public function test_yearly_projection_uses_year_to_date_usage_while_top_five_uses_this_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'));
        $merchant = Merchant::factory()->create();
        $segment = $this->segment($merchant);
        $segment->subscription->update(['billing_cycle' => 'yearly', 'starts_at' => '2026-01-01', 'cycle_starts_at' => '2026-01-01', 'cycle_ends_at' => '2027-01-01']);
        $segment->update(['starts_at' => '2026-01-01', 'included_usage_units' => 100]);
        $this->aggregate($segment, '2026-01-01', 100);
        $this->aggregate($segment, '2026-07-01', 50);

        $dashboard = $this->app->make(DashboardService::class)->get($merchant->id);

        $this->assertSame(['INR' => 200], $dashboard['projected_overage_revenue']);
        $this->assertSame(50, $dashboard['top_customers']->sole()['usage_units']);
    }
}
