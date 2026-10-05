<?php

namespace Tests\Feature\Services;

use App\Models\Merchant;
use App\Models\Plan;
use App\Services\PlanPricingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanPricingServiceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_database_cache_round_trips_pricing_without_serializing_domain_objects(): void
    {
        config(['cache.default' => 'database']);
        $plan = Plan::factory()->create();
        $service = $this->app->make(PlanPricingService::class);

        $first = $service->get($plan->merchant_id, $plan->id);
        $cached = $service->get($plan->merchant_id, $plan->id);

        $this->assertSame($first->toCacheArray(), $cached->toCacheArray());
        $this->assertIsArray(Cache::get(PlanPricingService::cacheKey($plan->merchant_id, $plan->id)));
    }

    public function test_cache_aside_and_plan_api_update_invalidate_pricing_after_commit(): void
    {
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);
        $plan = Plan::factory()->for($merchant)->create();
        $service = $this->app->make(PlanPricingService::class);
        $key = PlanPricingService::cacheKey($merchant->id, $plan->id);
        $this->assertSame(10000, $service->get($merchant->id, $plan->id)->basePriceMinor);
        $this->assertTrue(Cache::has($key));
        $payload = ['name' => $plan->name, 'currency' => 'INR', 'billing_cycle' => 'monthly', 'base_price_minor' => 20000, 'included_usage_units' => 100, 'overage_rate_micros' => 10000, 'merchant_id' => 999];

        $this->putJson('/api/plans/'.$plan->id, $payload, ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('data.base_price_minor', 20000);

        $this->assertFalse(Cache::has($key));
        $this->assertSame(20000, $service->get($merchant->id, $plan->id)->basePriceMinor);
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'merchant_id' => $merchant->id, 'base_price_minor' => 20000]);
    }

    public function test_rolled_back_plan_update_keeps_committed_cache_value(): void
    {
        $plan = Plan::factory()->create();
        $service = $this->app->make(PlanPricingService::class);
        $service->get($plan->merchant_id, $plan->id);
        DB::beginTransaction();
        $plan->update(['base_price_minor' => 99999]);

        DB::rollBack();

        $this->assertSame(10000, $service->get($plan->merchant_id, $plan->id)->basePriceMinor);
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'base_price_minor' => 10000]);
    }

    public function test_plan_creation_validates_money_and_rejects_unsupported_currency(): void
    {
        $token = str_repeat('a', 64);
        Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);
        $payload = ['name' => 'Test', 'currency' => 'JPY', 'billing_cycle' => 'weekly', 'base_price_minor' => -1, 'included_usage_units' => -1, 'overage_rate_micros' => 1.5];

        $this->postJson('/api/plans', $payload, ['Authorization' => 'Bearer '.$token])->assertUnprocessable()->assertJsonValidationErrors(['currency', 'billing_cycle', 'base_price_minor', 'included_usage_units', 'overage_rate_micros']);

        $this->assertDatabaseCount('plans', 0);
    }

    public function test_foreign_plan_cannot_be_read_from_cache_or_updated(): void
    {
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);
        $foreign = Plan::factory()->create();
        $payload = ['name' => 'Unrelated', 'currency' => 'INR', 'billing_cycle' => 'monthly', 'base_price_minor' => 1, 'included_usage_units' => 1, 'overage_rate_micros' => 1];

        $this->putJson('/api/plans/'.$foreign->id, $payload, ['Authorization' => 'Bearer '.$token])->assertNotFound();
        $this->getJson('/api/plans', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('plans', ['id' => $foreign->id, 'base_price_minor' => 10000]);
        $this->expectException(ModelNotFoundException::class);
        $this->app->make(PlanPricingService::class)->get($merchant->id, $foreign->id);
    }

    public function test_creates_and_lists_tenant_plans_with_explicit_minor_units(): void
    {
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);
        $payload = ['name' => 'Starter', 'currency' => 'INR', 'billing_cycle' => 'monthly', 'base_price_minor' => 10000, 'included_usage_units' => 100, 'overage_rate_micros' => 10000];

        $this->postJson('/api/plans', $payload, ['Authorization' => 'Bearer '.$token])->assertCreated()->assertJsonPath('data.merchant_id', $merchant->id);
        $this->getJson('/api/plans', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonCount(1, 'data');

        $this->assertDatabaseCount('plans', 1);
    }

    public function test_cached_pricing_avoids_another_plan_query(): void
    {
        $plan = Plan::factory()->create();
        $service = $this->app->make(PlanPricingService::class);
        $service->get($plan->merchant_id, $plan->id);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $pricing = $service->get($plan->merchant_id, $plan->id);

        $this->assertSame(10000, $pricing->basePriceMinor);
        $this->assertSame([], $queries);
    }
}
