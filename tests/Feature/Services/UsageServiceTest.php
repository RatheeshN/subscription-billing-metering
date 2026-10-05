<?php

namespace Tests\Feature\Services;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageEvent;
use App\Services\UsageService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsageServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retries_return_original_event_without_double_counting(): void
    {
        $customer = Customer::factory()->create();
        $service = $this->app->make(UsageService::class);
        $date = CarbonImmutable::parse('2026-10-01');
        $original = $service->record($customer->merchant_id, $customer->id, 'request-1', 25, $date);

        $retry = $service->record($customer->merchant_id, $customer->id, 'request-1', 25, $date);

        $this->assertSame($original->id, $retry->id);
        $this->assertDatabaseCount('usage_events', 1);
        $this->assertDatabaseHas('usage_events', ['customer_id' => $customer->id, 'units' => 25, 'usage_date' => '2026-10-01']);
    }

    #[DataProvider('conflictingUsage')]
    public function test_rejects_reused_keys_with_changed_payload_without_modifying_usage(int $units, string $date): void
    {
        $customer = Customer::factory()->create();
        UsageEvent::factory()->for($customer)->create(['idempotency_key' => 'request-1', 'units' => 25]);

        try {
            $this->app->make(UsageService::class)->record($customer->merchant_id, $customer->id, 'request-1', $units, CarbonImmutable::parse($date));
            $this->fail('Expected a conflicting idempotency key to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('The idempotency key was already used with different usage data.', $exception->getMessage());
        }

        $this->assertDatabaseCount('usage_events', 1);
        $this->assertDatabaseHas('usage_events', ['units' => 25, 'usage_date' => '2026-10-01']);
    }

    public static function conflictingUsage(): array
    {
        return ['changed units' => [26, '2026-10-01'], 'changed day' => [25, '2026-10-02']];
    }

    public function test_rejects_recording_usage_for_another_merchants_customer(): void
    {
        $customer = Customer::factory()->create();
        $otherMerchant = Merchant::factory()->create();

        try {
            $this->app->make(UsageService::class)->record($otherMerchant->id, $customer->id, 'request-1', 25, CarbonImmutable::parse('2026-10-01'));
            $this->fail('Expected tenant isolation to reject this customer.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(Customer::class, $exception->getModel());
        }

        $this->assertDatabaseCount('usage_events', 0);
    }

    public function test_same_key_can_be_used_independently_by_different_customers(): void
    {
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        $service = $this->app->make(UsageService::class);
        $date = CarbonImmutable::parse('2026-10-01');
        $service->record($first->merchant_id, $first->id, 'request-1', 25, $date);

        $event = $service->record($second->merchant_id, $second->id, 'request-1', 50, $date);

        $this->assertSame($second->id, $event->customer_id);
        $this->assertDatabaseCount('usage_events', 2);
    }

    public function test_totals_include_start_and_exclude_end_and_other_customers(): void
    {
        $customer = Customer::factory()->create();
        UsageEvent::factory()->for($customer)->create(['usage_date' => '2026-09-30', 'units' => 100]);
        UsageEvent::factory()->for($customer)->create(['usage_date' => '2026-10-01', 'units' => 25]);
        UsageEvent::factory()->for($customer)->create(['usage_date' => '2026-10-31', 'units' => 50]);
        UsageEvent::factory()->for($customer)->create(['usage_date' => '2026-11-01', 'units' => 200]);
        UsageEvent::factory()->create(['usage_date' => '2026-10-01', 'units' => 1000]);

        $total = $this->app->make(UsageService::class)->totalUnits($customer->merchant_id, $customer->id, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(75, $total);
    }

    public function test_totals_return_zero_without_events(): void
    {
        $customer = Customer::factory()->create();

        $total = $this->app->make(UsageService::class)->totalUnits($customer->merchant_id, $customer->id, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(0, $total);
    }

    public function test_totals_reject_another_merchants_customer(): void
    {
        $customer = Customer::factory()->create();
        $otherMerchant = Merchant::factory()->create();
        $this->expectException(ModelNotFoundException::class);

        $this->app->make(UsageService::class)->totalUnits($otherMerchant->id, $customer->id, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01'));
    }

    #[DataProvider('invalidUsage')]
    public function test_rejects_invalid_usage_before_writing(string $key, int $units): void
    {
        $customer = Customer::factory()->create();

        try {
            $this->app->make(UsageService::class)->record($customer->merchant_id, $customer->id, $key, $units, CarbonImmutable::parse('2026-10-01'));
            $this->fail('Expected invalid usage to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertDatabaseCount('usage_events', 0);
    }

    public static function invalidUsage(): array
    {
        return ['zero units' => ['key', 0], 'negative units' => ['key', -1], 'overflow' => ['key', 2147483648], 'empty key' => ['', 1], 'blank key' => ['  ', 1], 'long key' => [str_repeat('a', 101), 1]];
    }

    #[DataProvider('invalidRanges')]
    public function test_rejects_empty_or_reversed_date_ranges(string $endDate): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(UsageService::class)->totalUnits(1, 1, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse($endDate));
    }

    public static function invalidRanges(): array
    {
        return ['empty range' => ['2026-10-01'], 'reversed range' => ['2026-09-30']];
    }
}
