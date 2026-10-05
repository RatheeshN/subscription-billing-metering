<?php

namespace Tests\Feature\Http;

use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SubscriptionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function tenant(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create();

        return [$merchant, $customer, $plan, ['Authorization' => 'Bearer '.$token]];
    }

    public function test_subscription_creation_returns_201_with_pricing_snapshot(): void
    {
        [$merchant,$customer,$plan,$headers] = $this->tenant();

        $this->postJson('/api/subscriptions', ['customer_id' => $customer->id, 'plan_id' => $plan->id, 'starts_at' => '2026-09-01T00:00:00Z', 'merchant_id' => 999], $headers)->assertCreated()->assertJsonPath('data.customer_id', $customer->id)->assertJsonPath('data.segments.0.base_price_minor', 10000);

        $this->assertDatabaseHas('subscriptions', ['merchant_id' => $merchant->id, 'cycle_starts_at' => '2026-09-01 00:00:00', 'cycle_ends_at' => '2026-10-01 00:00:00']);
        $this->assertDatabaseHas('subscription_segments', ['plan_id' => $plan->id, 'base_price_minor' => 10000]);
    }

    public function test_duplicate_customer_subscription_returns_409(): void
    {
        [, $customer,$plan,$headers] = $this->tenant();
        Subscription::factory()->for($customer)->create();

        $this->postJson('/api/subscriptions', ['customer_id' => $customer->id, 'plan_id' => $plan->id], $headers)->assertConflict()->assertJsonPath('message', 'The customer already has a subscription.');

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_foreign_plan_and_foreign_customer_return_404(): void
    {
        [, $customer,$plan,$headers] = $this->tenant();
        $foreignPlan = Plan::factory()->create();
        $foreignCustomer = Customer::factory()->create();

        $this->postJson('/api/subscriptions', ['customer_id' => $customer->id, 'plan_id' => $foreignPlan->id], $headers)->assertNotFound();
        $this->postJson('/api/subscriptions', ['customer_id' => $foreignCustomer->id, 'plan_id' => $plan->id], $headers)->assertNotFound();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_invalid_start_returns_422(): void
    {
        [, $customer,$plan,$headers] = $this->tenant();

        $this->postJson('/api/subscriptions', ['customer_id' => $customer->id, 'plan_id' => $plan->id, 'starts_at' => '2026-09-17T00:00:00Z'], $headers)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_plan_change_closes_old_segment_and_opens_new_snapshot(): void
    {
        [$merchant,$customer,$oldPlan,$headers] = $this->tenant();
        $subscription = Subscription::factory()->for($customer)->create();
        $old = SubscriptionSegment::factory()->for($subscription)->withPricing($oldPlan)->create();
        $newPlan = Plan::factory()->for($merchant)->create(['base_price_minor' => 20000]);

        $this->putJson('/api/subscriptions/'.$subscription->id.'/plan', ['plan_id' => $newPlan->id], $headers)->assertOk()->assertJsonCount(2, 'data.segments');

        $this->assertDatabaseHas('subscription_segments', ['id' => $old->id, 'ends_at' => '2026-09-16 12:00:00', 'base_price_minor' => 10000]);
        $this->assertDatabaseHas('subscription_segments', ['subscription_id' => $subscription->id, 'plan_id' => $newPlan->id, 'starts_at' => '2026-09-16 12:00:00', 'ends_at' => null, 'base_price_minor' => 20000]);
    }

    public function test_same_plan_retry_does_not_add_a_segment(): void
    {
        [, $customer,$plan,$headers] = $this->tenant();
        $subscription = Subscription::factory()->for($customer)->create();
        SubscriptionSegment::factory()->for($subscription)->withPricing($plan)->create();

        $this->putJson('/api/subscriptions/'.$subscription->id.'/plan', ['plan_id' => $plan->id], $headers)->assertOk();

        $this->assertDatabaseCount('subscription_segments', 1);
    }

    public function test_incompatible_cycle_returns_409_without_closing_segment(): void
    {
        [$merchant,$customer,$plan,$headers] = $this->tenant();
        $subscription = Subscription::factory()->for($customer)->create();
        $segment = SubscriptionSegment::factory()->for($subscription)->withPricing($plan)->create();
        $annual = Plan::factory()->for($merchant)->create(['billing_cycle' => BillingCycle::Yearly]);

        $this->putJson('/api/subscriptions/'.$subscription->id.'/plan', ['plan_id' => $annual->id], $headers)->assertConflict();

        $this->assertDatabaseHas('subscription_segments', ['id' => $segment->id, 'ends_at' => null]);
        $this->assertDatabaseCount('subscription_segments', 1);
    }

    public function test_plan_change_cannot_reassign_already_recorded_boundary_usage(): void
    {
        [$merchant,$customer,$plan,$headers] = $this->tenant();
        $subscription = Subscription::factory()->for($customer)->create();
        $segment = SubscriptionSegment::factory()->for($subscription)->withPricing($plan)->create();
        UsageEvent::factory()->metered($segment)->create(['occurred_at' => '2026-09-16 12:00:00']);
        $newPlan = Plan::factory()->for($merchant)->create();

        $this->putJson('/api/subscriptions/'.$subscription->id.'/plan', ['plan_id' => $newPlan->id], $headers)->assertConflict();

        $this->assertDatabaseCount('subscription_segments', 1);
    }

    public function test_foreign_subscription_returns_404_for_read_and_change(): void
    {
        [,,$plan,$headers] = $this->tenant();
        $foreign = Subscription::factory()->create();

        $this->getJson('/api/subscriptions/'.$foreign->id, $headers)->assertNotFound();
        $this->putJson('/api/subscriptions/'.$foreign->id.'/plan', ['plan_id' => $plan->id], $headers)->assertNotFound();
    }

    public function test_subscription_endpoints_require_authentication(): void
    {
        $this->postJson('/api/subscriptions',[])->assertUnauthorized();
        $this->putJson('/api/subscriptions/1/plan',[])->assertUnauthorized();
    }
}
