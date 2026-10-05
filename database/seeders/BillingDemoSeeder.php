<?php

namespace Database\Seeders;

use App\Actions\RecordUsageAction;
use App\Actions\StartSubscriptionAction;
use App\DTOs\PricingDTO;
use App\DTOs\RecordUsageDTO;
use App\Jobs\AggregateDailyUsageJob;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\AggregationService;
use App\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BillingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $existingDemo = Merchant::query()->where('name', 'Demo SaaS')->first();
        $now = $existingDemo?->created_at->toImmutable()->utc()->startOfSecond() ?? CarbonImmutable::now('UTC')->startOfSecond();
        $month = $now->startOfMonth();
        $previous = $month->subMonths(2);
        $last = $month->subMonth();
        $start = $this->container->make(StartSubscriptionAction::class);
        $record = $this->container->make(RecordUsageAction::class);
        $subscriptions = $this->container->make(SubscriptionRepositoryInterface::class);
        $billing = $this->container->make(BillingService::class);
        foreach (['Demo SaaS' => 10, 'Other Merchant' => 5] as $name => $count) {
            $merchant = Merchant::query()->firstOrCreate(['name' => $name]);
            $starter = Plan::query()->firstOrCreate(['merchant_id' => $merchant->id, 'name' => 'Starter'], ['currency' => 'INR', 'billing_cycle' => 'monthly', 'base_price_minor' => 10000, 'included_usage_units' => 1000, 'overage_rate_micros' => 1000]);
            $pro = Plan::query()->firstOrCreate(['merchant_id' => $merchant->id, 'name' => 'Pro'], ['currency' => 'INR', 'billing_cycle' => 'monthly', 'base_price_minor' => 25000, 'included_usage_units' => 5000, 'overage_rate_micros' => 500]);
            for ($index = 0; $index < $count; $index++) {
                $customer = Customer::query()->firstOrCreate(['merchant_id' => $merchant->id, 'email' => 'customer'.($index + 1).'@'.($name === 'Demo SaaS' ? 'demo' : 'other').'.example'], ['name' => 'Customer '.($index + 1)]);
                $initial = $index % 2 === 0 ? $starter : $pro;
                $subscription = $customer->subscription()->with('segments')->first() ?? $start->handle($merchant->id, $customer->id, $initial->id, $previous);
                if ($index % 3 === 0 && $subscription->segments->count() === 1) {
                    DB::transaction(function () use ($subscriptions, $subscription, $initial, $starter, $pro, $last): void {
                        $locked = $subscriptions->lockForMerchant($subscription->merchant_id, $subscription->id);
                        $old = $subscriptions->openSegment($locked);
                        $subscriptions->replaceSegment($locked, $old, PricingDTO::fromPlan($initial->id === $starter->id ? $pro : $starter), $last->addDays(15));
                    });
                }
                for ($day = $previous; $day < $now->startOfDay(); $day = $day->addDay()) {
                    $units = $day < $last ? 100 + $index * 20 : ($index < 2 ? 10 : 180 + $index * 30);
                    $record->handle(new RecordUsageDTO($merchant->id, $customer->id, $units, $day->addHours(12), 'demo-'.$customer->id.'-'.$day->toDateString()));
                }
                if ($now > $now->startOfDay()) {
                    $record->handle(new RecordUsageDTO($merchant->id, $customer->id, 50 + $index * 10, $now->startOfDay(), 'demo-'.$customer->id.'-today'));
                }
                $billing->generate($merchant->id, $subscription->id, $previous);
                if ($month->addSeconds((int) config('billing.invoice_grace_seconds')) <= $now) {
                    $billing->generate($merchant->id, $subscription->id, $last);
                }
            }
            $this->command?->info("{$name}: merchant ID {$merchant->id}, {$count} customers. Issue a token with merchant:token {$merchant->id}.");
        }
        (new AggregateDailyUsageJob)->handle($this->container->make(AggregationService::class));
    }
}
