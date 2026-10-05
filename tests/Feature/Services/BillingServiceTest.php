<?php

namespace Tests\Feature\Services;

use App\Actions\ChangeSubscriptionPlanAction;
use App\Actions\RecordUsageAction;
use App\DTOs\RecordUsageDTO;
use App\Enums\BillingCycle;
use App\Exceptions\BillingConflictException;
use App\Jobs\GenerateInvoiceJob;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use App\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(string $startsAt = '2026-09-01 00:00:00'): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:10:00', 'UTC'));
        $subscription = Subscription::factory()->create(['starts_at' => $startsAt]);
        $segment = SubscriptionSegment::factory()->for($subscription)->create();

        return [$subscription, $segment];
    }

    private function bill(Subscription $subscription): Invoice
    {
        return $this->app->make(BillingService::class)->generate($subscription->merchant_id, $subscription->id, CarbonImmutable::parse('2026-09-01', 'UTC'));
    }

    #[DataProvider('allowanceCases')]
    public function test_bills_usage_with_the_snapshotted_included_allowance(int $units, int $overage): void
    {
        [$subscription,$segment] = $this->fixture();
        UsageEvent::factory()->metered($segment)->create(['units' => $units]);

        $invoice = $this->bill($subscription);

        $this->assertSame(10000 + $overage, $invoice->total_minor);
        $this->assertSame($overage, $invoice->lines->first()->overage_units);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoice_lines', ['usage_units' => $units, 'included_units' => 100]);
        $this->assertSame('2026-10-01', $subscription->fresh()->cycle_starts_at->toDateString());
    }

    public static function allowanceCases(): array
    {
        return ['no overage' => [50, 0], 'exact allowance' => [100, 0], 'overage' => [150, 50]];
    }

    public function test_zero_usage_still_bills_the_base_price_without_overage(): void
    {
        [$subscription] = $this->fixture();

        $invoice = $this->bill($subscription);

        $this->assertSame(10000, $invoice->total_minor);
        $this->assertSame(0, $invoice->overage_total_minor);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'usage_units' => 0, 'included_units' => 100, 'overage_units' => 0]);
        $this->assertDatabaseCount('usage_events', 0);
    }

    public function test_subscription_start_mid_cycle_prorates_base_price_and_allowance(): void
    {
        [$subscription,$segment] = $this->fixture('2026-09-16 00:00:00');
        UsageEvent::factory()->metered($segment)->create(['units' => 80]);

        $invoice = $this->bill($subscription);

        $this->assertSame(5030, $invoice->total_minor);
        $this->assertDatabaseHas('invoice_lines', ['base_total_minor' => 5000, 'included_units' => 50, 'overage_units' => 30, 'duration_seconds' => 1296000, 'cycle_seconds' => 2592000]);
    }

    #[DataProvider('planChanges')]
    public function test_mid_cycle_change_preserves_each_segments_pricing(int $oldBase, int $oldAllowance, int $oldRate, int $oldUsage, int $newBase, int $newAllowance, int $newRate, int $newUsage): void
    {
        [$subscription,$old] = $this->fixture();
        $old->update(['base_price_minor' => $oldBase, 'included_usage_units' => $oldAllowance, 'overage_rate_micros' => $oldRate, 'ends_at' => '2026-09-16 00:00:00']);
        $new = SubscriptionSegment::factory()->for($subscription)->create(['starts_at' => '2026-09-16 00:00:00', 'base_price_minor' => $newBase, 'included_usage_units' => $newAllowance, 'overage_rate_micros' => $newRate]);
        UsageEvent::factory()->metered($old)->create(['units' => $oldUsage, 'occurred_at' => '2026-09-15 23:59:59']);
        UsageEvent::factory()->metered($new)->create(['units' => $newUsage, 'occurred_at' => '2026-09-16 00:00:00']);

        $invoice = $this->bill($subscription);

        $this->assertSame(45250, $invoice->total_minor);
        $this->assertSame(2, $invoice->lines->count());
        $this->assertDatabaseHas('invoice_lines', ['subscription_segment_id' => $old->id, 'base_total_minor' => intdiv($oldBase, 2), 'included_units' => intdiv($oldAllowance, 2), 'overage_rate_micros' => $oldRate]);
        $this->assertDatabaseHas('invoice_lines', ['subscription_segment_id' => $new->id, 'base_total_minor' => intdiv($newBase, 2), 'included_units' => intdiv($newAllowance, 2), 'overage_rate_micros' => $newRate]);
    }

    public static function planChanges(): array
    {
        return ['upgrade' => [30000, 300, 10000, 200, 60000, 600, 20000, 400], 'downgrade' => [60000, 600, 20000, 400, 30000, 300, 10000, 200]];
    }

    #[DataProvider('planChanges')]
    public function test_plan_change_action_and_late_usage_produce_the_correct_segment_charges(int $oldBase, int $oldAllowance, int $oldRate, int $oldUsage, int $newBase, int $newAllowance, int $newRate, int $newUsage): void
    {
        [$subscription, $old] = $this->fixture();
        $old->update(['base_price_minor' => $oldBase, 'included_usage_units' => $oldAllowance, 'overage_rate_micros' => $oldRate]);
        $newPlan = Plan::factory()->create(['merchant_id' => $subscription->merchant_id, 'base_price_minor' => $newBase, 'included_usage_units' => $newAllowance, 'overage_rate_micros' => $newRate]);
        $this->travelTo(CarbonImmutable::parse('2026-09-16 00:00:00', 'UTC'));
        $usage = $this->app->make(RecordUsageAction::class);
        $this->app->make(ChangeSubscriptionPlanAction::class)->handle($subscription->merchant_id, $subscription->id, $newPlan->id);

        $before = $usage->handle(new RecordUsageDTO($subscription->merchant_id, $subscription->customer_id, $oldUsage, CarbonImmutable::parse('2026-09-15 23:59:59', 'UTC'), 'late-before-change'));
        $after = $usage->handle(new RecordUsageDTO($subscription->merchant_id, $subscription->customer_id, $newUsage, CarbonImmutable::parse('2026-09-16 00:00:00', 'UTC'), 'at-change'));
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:10:00', 'UTC'));
        $invoice = $this->bill($subscription);

        $this->assertSame($old->id, $before->subscription_segment_id);
        $this->assertNotSame($old->id, $after->subscription_segment_id);
        $this->assertSame(45000, $invoice->base_total_minor);
        $this->assertSame(250, $invoice->overage_total_minor);
        $this->assertSame(45250, $invoice->total_minor);
        $this->assertSame(2, $invoice->lines->count());
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'subscription_segment_id' => $old->id, 'usage_units' => $oldUsage, 'overage_rate_micros' => $oldRate]);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'subscription_segment_id' => $after->subscription_segment_id, 'usage_units' => $newUsage, 'overage_rate_micros' => $newRate]);
    }

    public function test_usage_exactly_at_plan_change_boundary_belongs_to_new_segment(): void
    {
        [$subscription,$old] = $this->fixture();
        $newPlan = Plan::factory()->create(['merchant_id' => $subscription->merchant_id, 'base_price_minor' => 20000]);
        $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));
        $action = $this->app->make(RecordUsageAction::class);
        $before = $action->handle(new RecordUsageDTO($subscription->merchant_id, $subscription->customer_id, 10, CarbonImmutable::parse('2026-09-16 11:59:59', 'UTC'), 'before'));
        $this->app->make(ChangeSubscriptionPlanAction::class)->handle($subscription->merchant_id, $subscription->id, $newPlan->id);

        $boundary = $action->handle(new RecordUsageDTO($subscription->merchant_id, $subscription->customer_id, 20, CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'), 'boundary'));

        $this->assertSame($old->id, $before->subscription_segment_id);
        $this->assertNotSame($old->id, $boundary->subscription_segment_id);
        $this->assertSame($newPlan->id, SubscriptionSegment::query()->findOrFail($boundary->subscription_segment_id)->plan_id);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:10:00', 'UTC'));
        $invoice = $this->bill($subscription);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'subscription_segment_id' => $old->id, 'usage_units' => 10]);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'subscription_segment_id' => $boundary->subscription_segment_id, 'usage_units' => 20]);
    }

    public function test_multiple_segments_generate_three_prorated_lines(): void
    {
        [$subscription,$first] = $this->fixture();
        $first->update(['base_price_minor' => 9000, 'included_usage_units' => 90, 'ends_at' => '2026-09-11 00:00:00']);
        $second = SubscriptionSegment::factory()->for($subscription)->create(['base_price_minor' => 18000, 'included_usage_units' => 180, 'starts_at' => '2026-09-11 00:00:00', 'ends_at' => '2026-09-21 00:00:00']);
        $third = SubscriptionSegment::factory()->for($subscription)->create(['base_price_minor' => 27000, 'included_usage_units' => 270, 'starts_at' => '2026-09-21 00:00:00']);
        UsageEvent::factory()->metered($first)->create(['units' => 30]);
        UsageEvent::factory()->metered($second)->create(['units' => 60]);
        UsageEvent::factory()->metered($third)->create(['units' => 90]);

        $invoice = $this->bill($subscription);

        $this->assertSame(18000, $invoice->total_minor);
        $this->assertSame(0, $invoice->overage_total_minor);
        $this->assertSame(3, $invoice->lines->count());
    }

    public function test_invoice_and_queued_job_retries_return_original_invoice(): void
    {
        [$subscription,$segment] = $this->fixture();
        UsageEvent::factory()->metered($segment)->create(['units' => 150]);
        $invoice = $this->bill($subscription);

        $retry = $this->bill($subscription);
        (new GenerateInvoiceJob($subscription->merchant_id, $subscription->id, '2026-09-01 00:00:00'))->handle($this->app->make(BillingService::class));

        $this->assertSame($invoice->id, $retry->id);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_lines', 1);
        $this->assertDatabaseHas('daily_usage_aggregates', ['units' => 150]);
    }

    public function test_plan_edit_does_not_reprice_existing_segment(): void
    {
        [$subscription,$segment] = $this->fixture();
        $segment->plan->update(['base_price_minor' => 99999, 'overage_rate_micros' => 99999]);
        UsageEvent::factory()->metered($segment)->create(['units' => 150]);

        $invoice = $this->bill($subscription);

        $this->assertSame(10050, $invoice->total_minor);
    }

    public function test_current_cycle_usage_is_excluded_from_previous_invoice(): void
    {
        [$subscription,$segment] = $this->fixture();
        UsageEvent::factory()->metered($segment)->create(['units' => 100, 'occurred_at' => '2026-09-30 23:59:59']);
        UsageEvent::factory()->metered($segment)->create(['units' => 1000, 'occurred_at' => '2026-10-01 00:00:00']);

        $invoice = $this->bill($subscription);

        $this->assertSame(10000, $invoice->total_minor);
        $this->assertSame(100, $invoice->lines->first()->usage_units);
    }

    public function test_finalized_cycles_reject_new_events_but_accept_identical_retries(): void
    {
        [$subscription,$segment] = $this->fixture();
        $action = $this->app->make(RecordUsageAction::class);
        $dto = new RecordUsageDTO($subscription->merchant_id, $subscription->customer_id, 10, CarbonImmutable::parse('2026-09-30T12:00:00Z'), 'original');
        $event = $action->handle($dto);
        $this->bill($subscription);
        $retry = $action->handle($dto);
        $this->assertSame($event->id, $retry->id);

        try {
            $action->handle(new RecordUsageDTO($subscription->merchant_id, $subscription->customer_id, 10, $dto->occurredAt, 'late'));
            $this->fail('Finalized cycles must reject new events.');
        } catch (BillingConflictException $exception) {
            $this->assertSame('Usage belongs to an already finalized billing cycle.', $exception->getMessage());
        }
        $this->assertDatabaseCount('usage_events', 1);
    }

    public function test_cycle_cannot_be_finalized_before_grace_window_ends(): void
    {
        [$subscription] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:04:59', 'UTC'));

        try {
            $this->bill($subscription);
            $this->fail('Expected finalization to be rejected.');
        } catch (BillingConflictException $exception) {
            $this->assertSame('The billing cycle is not ready for finalization.', $exception->getMessage());
        }
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_yearly_cycle_supports_leap_year_proration(): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-01-01 00:10:00', 'UTC'));
        $subscription = Subscription::factory()->create(['billing_cycle' => BillingCycle::Yearly, 'starts_at' => '2024-07-02 00:00:00', 'cycle_starts_at' => '2024-01-01 00:00:00', 'cycle_ends_at' => '2025-01-01 00:00:00']);
        SubscriptionSegment::factory()->for($subscription)->create(['base_price_minor' => 36600, 'included_usage_units' => 366]);

        $invoice = $this->app->make(BillingService::class)->generate($subscription->merchant_id, $subscription->id, CarbonImmutable::parse('2024-01-01', 'UTC'));

        $this->assertSame(18300, $invoice->total_minor);
        $this->assertSame(183, $invoice->lines->first()->included_units);
    }

    public function test_invoice_generation_rejects_a_different_merchant(): void
    {
        [$subscription] = $this->fixture();
        $this->expectException(ModelNotFoundException::class);

        $this->app->make(BillingService::class)->generate($subscription->merchant_id + 100, $subscription->id, CarbonImmutable::parse('2026-09-01', 'UTC'));
    }

    public function test_invoice_generation_requires_chronological_cycles(): void
    {
        [$subscription] = $this->fixture();
        $this->expectException(BillingConflictException::class);

        $this->app->make(BillingService::class)->generate($subscription->merchant_id, $subscription->id, CarbonImmutable::parse('2026-08-01', 'UTC'));
    }

    public function test_failed_invoice_line_write_rolls_back_aggregation_invoice_and_cycle_advance(): void
    {
        [$subscription,$segment] = $this->fixture();
        UsageEvent::factory()->metered($segment)->create(['units' => 150]);
        InvoiceLine::creating(fn () => throw new \RuntimeException('Simulated line write failure'));

        try {
            $this->bill($subscription);
            $this->fail('Expected a failed invoice write.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated line write failure', $exception->getMessage());
        } finally {
            InvoiceLine::flushEventListeners();
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('daily_usage_aggregates', 0);
        $this->assertDatabaseHas('usage_events', ['aggregated_at' => null]);
        $this->assertSame('2026-09-01', $subscription->fresh()->cycle_starts_at->toDateString());
    }

    #[DataProvider('invalidSegmentStarts')]
    public function test_invalid_segment_history_is_rejected_without_generating_an_invoice(string $secondStart): void
    {
        [$subscription,$first] = $this->fixture();
        $first->update(['ends_at' => '2026-09-16 00:00:00']);
        SubscriptionSegment::factory()->for($subscription)->create(['starts_at' => $secondStart]);

        try {
            $this->bill($subscription);
            $this->fail('Invalid pricing history must not be billed.');
        } catch (BillingConflictException $exception) {
            $this->assertSame('Subscription pricing history has a gap or overlap.', $exception->getMessage());
        }
        $this->assertDatabaseCount('invoices', 0);
    }

    public static function invalidSegmentStarts(): array
    {
        return ['gap' => ['2026-09-17 00:00:00'], 'overlap' => ['2026-09-15 00:00:00']];
    }
}
