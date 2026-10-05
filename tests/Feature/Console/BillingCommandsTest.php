<?php

namespace Tests\Feature\Console;

use App\Jobs\GenerateInvoiceJob;
use App\Models\Merchant;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BillingCommandsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dispatch_command_queues_only_due_cycles_after_grace_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:10:00', 'UTC'));
        $due = Subscription::factory()->create();
        Subscription::factory()->create(['cycle_starts_at' => '2026-10-01', 'cycle_ends_at' => '2026-11-01']);
        Queue::fake([GenerateInvoiceJob::class]);

        $this->artisan('billing:dispatch')->expectsOutput('Dispatched 1 due billing cycles.')->assertSuccessful();

        Queue::assertPushed(GenerateInvoiceJob::class, fn ($job) => $job->subscriptionId === $due->id && $job->cycleStart === '2026-09-01 00:00:00' && $job->queue === 'billing');
    }

    public function test_token_rotation_stores_only_hash_and_invalidates_old_token(): void
    {
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);

        $this->artisan('merchant:token', ['merchant' => $merchant->id])->assertSuccessful();

        $this->assertNotSame(hash('sha256', $token), $merchant->fresh()->api_token_hash);
        $this->assertSame(64, strlen($merchant->fresh()->api_token_hash));
        $this->getJson('/api/plans', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }
}
