<?php

namespace Tests\Feature\Http;

use App\DTOs\RecordUsageDTO;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsageControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function tenant(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(12, 0));
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);
        $customer = Customer::factory()->for($merchant)->create();
        $subscription = Subscription::factory()->for($customer)->create();
        $segment = SubscriptionSegment::factory()->for($subscription)->create();

        return [$merchant, $customer, $segment, ['Authorization' => 'Bearer '.$token]];
    }

    private function payload(Customer $customer): array
    {
        return ['customer_id' => $customer->id, 'units' => 50, 'occurred_at' => '2026-09-30T10:30:00Z', 'idempotency_key' => 'evt_abc123'];
    }

    public function test_records_usage_and_returns_201_without_synchronous_aggregation(): void
    {
        [$merchant,$customer,$segment,$headers] = $this->tenant();

        $this->postJson('/api/usage', $this->payload($customer) + ['merchant_id' => 999, 'subscription_segment_id' => 999], $headers)->assertCreated()->assertJsonPath('data.subscription_segment_id', $segment->id)->assertJsonPath('data.units', 50);

        $this->assertDatabaseHas('usage_events', ['merchant_id' => $merchant->id, 'customer_id' => $customer->id, 'units' => 50, 'aggregated_at' => null]);
        $this->assertDatabaseCount('daily_usage_aggregates', 0);
    }

    public function test_retry_returns_200_and_does_not_double_count(): void
    {
        [, $customer, , $headers] = $this->tenant();
        $original = $this->postJson('/api/usage', $this->payload($customer), $headers)->assertCreated()->json('data.id');

        $this->postJson('/api/usage', $this->payload($customer), $headers)->assertOk()->assertJsonPath('data.id', $original);

        $this->assertDatabaseCount('usage_events', 1);
    }

    public function test_changed_payload_returns_409_without_modifying_original_usage(): void
    {
        [, $customer, , $headers] = $this->tenant();
        $payload = $this->payload($customer);
        $this->postJson('/api/usage', $payload, $headers)->assertCreated();
        $payload['units'] = 51;

        $this->postJson('/api/usage', $payload, $headers)->assertConflict()->assertJsonPath('message', 'The idempotency key was already used with different usage data.');

        $this->assertDatabaseCount('usage_events', 1);
        $this->assertDatabaseHas('usage_events', ['units' => 50]);
    }

    public function test_idempotency_keys_are_case_sensitive(): void
    {
        [, $customer, , $headers] = $this->tenant();
        $payload = $this->payload($customer);
        $this->postJson('/api/usage', $payload, $headers)->assertCreated();
        $payload['idempotency_key'] = 'EVT_ABC123';

        $this->postJson('/api/usage', $payload, $headers)->assertCreated();

        $this->assertDatabaseCount('usage_events', 2);
    }

    public function test_nonexistent_customer_returns_404_without_writing_usage(): void
    {
        [, $customer, , $headers] = $this->tenant();
        $payload = $this->payload($customer);
        $payload['customer_id'] = $customer->id + 1000;

        $this->postJson('/api/usage', $payload, $headers)->assertNotFound();

        $this->assertDatabaseCount('usage_events', 0);
    }

    public function test_usage_before_subscription_start_returns_409_without_writing(): void
    {
        [, $customer, , $headers] = $this->tenant();
        $payload = $this->payload($customer);
        $payload['occurred_at'] = '2026-08-31T23:59:59Z';

        $this->postJson('/api/usage', $payload, $headers)->assertConflict();

        $this->assertDatabaseCount('usage_events', 0);
    }

    public function test_foreign_customer_returns_404_without_writing(): void
    {
        [,,, $headers] = $this->tenant();
        $foreign = Customer::factory()->create();
        SubscriptionSegment::factory()->for(Subscription::factory()->for($foreign)->create())->create();

        $this->postJson('/api/usage', $this->payload($foreign), $headers)->assertNotFound();

        $this->assertDatabaseCount('usage_events', 0);
    }

    public function test_missing_authentication_returns_401(): void
    {
        $this->postJson('/api/usage', [])->assertUnauthorized()->assertJsonPath('message', 'A valid merchant bearer token is required.');
    }

    public function test_invalid_authentication_returns_401(): void
    {
        $this->postJson('/api/usage', [], ['Authorization' => 'Bearer '.str_repeat('b', 64)])->assertUnauthorized();
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_returns_422(string $field, mixed $value): void
    {
        [, $customer, , $headers] = $this->tenant();
        $payload = $this->payload($customer);
        $payload[$field] = $value;

        $this->postJson('/api/usage', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('usage_events', 0);
    }

    public static function invalidPayloads(): array
    {
        return ['invalid customer' => ['customer_id', 0], 'zero units' => ['units', 0], 'fractional units' => ['units', 1.5], 'too many units' => ['units', 2147483648], 'missing units' => ['units', null], 'invalid timestamp' => ['occurred_at', 'nonsense'], 'missing timezone' => ['occurred_at', '2026-09-30 10:30:00'], 'future timestamp' => ['occurred_at', '2026-10-02T00:00:00Z'], 'fractional seconds' => ['occurred_at', '2026-09-30T10:30:00.500Z'], 'empty key' => ['idempotency_key', ''], 'too long key' => ['idempotency_key', str_repeat('a', 101)], 'invalid key' => ['idempotency_key', "key' OR 1=1"]];
    }

    public function test_rate_limit_returns_429_after_the_configured_tenant_budget(): void
    {
        [, $customer, , $headers] = $this->tenant();
        config(['billing.usage_requests_per_minute' => 2]);
        $this->postJson('/api/usage', $this->payload($customer), $headers)->assertCreated();
        $this->postJson('/api/usage', $this->payload($customer), $headers)->assertOk();

        $this->postJson('/api/usage', $this->payload($customer), $headers)->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertDatabaseCount('usage_events', 1);
    }

    public function test_timestamp_timezone_is_normalized_to_utc(): void
    {
        [, $customer, , $headers] = $this->tenant();
        $payload = $this->payload($customer);
        $payload['occurred_at'] = '2026-09-30T16:00:00+05:30';

        $this->postJson('/api/usage', $payload, $headers)->assertCreated()->assertJsonPath('data.occurred_at', '2026-09-30T10:30:00+00:00');

        $this->assertDatabaseHas('usage_events', ['occurred_at' => '2026-09-30 10:30:00']);
    }

    public function test_database_duplicate_insert_returns_existing_event(): void
    {
        [$merchant,$customer,$segment] = $this->tenant();
        $event = UsageEvent::factory()->metered($segment)->create(['units' => 50, 'occurred_at' => '2026-09-30 10:30:00', 'idempotency_key' => 'evt_abc123']);
        $dto = new RecordUsageDTO($merchant->id, $customer->id, 50, CarbonImmutable::parse('2026-09-30T10:30:00Z'), 'evt_abc123');

        $retry = $this->app->make(UsageEventRepositoryInterface::class)->recordUsage($dto, $segment);

        $this->assertSame($event->id, $retry->id);
        $this->assertDatabaseCount('usage_events', 1);
    }

    public function test_empty_payload_returns_422_for_all_required_usage_fields(): void
    {
        [,,, $headers] = $this->tenant();

        $this->postJson('/api/usage', [], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'units', 'occurred_at', 'idempotency_key']);

        $this->assertDatabaseCount('usage_events', 0);
    }
}
