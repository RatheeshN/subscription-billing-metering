<?php

namespace Tests\Feature;

use App\Models\DailyUsageAggregate;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_sign_in_without_merchant_data(): void
    {
        $this->get('/')->assertOk()->assertSee('Welcome back')->assertDontSee('Your top customers');
    }

    public function test_valid_token_opens_only_its_merchant_workspace(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2)->startOfDay());
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['name' => 'My workspace', 'api_token_hash' => hash('sha256', $token)]);
        $own = DailyUsageAggregate::factory()->create(['usage_date' => '2026-10-01', 'units' => 123]);
        $own->subscriptionSegment->subscription->update(['merchant_id' => $merchant->id]);
        $own->customer->update(['merchant_id' => $merchant->id, 'name' => 'Visible customer']);
        $own->update(['merchant_id' => $merchant->id]);
        $other = DailyUsageAggregate::factory()->create(['usage_date' => '2026-10-01', 'units' => 999]);
        $other->customer->update(['name' => 'Hidden customer']);

        $this->post(route('dashboard.session.store'), ['token' => $token])
            ->assertRedirect(route('dashboard.index'))->assertSessionHas('merchant_token_hash', hash('sha256', $token));
        $this->get('/')->assertOk()->assertSee('My workspace')->assertSee('Visible customer')
            ->assertDontSee('Hidden customer')->assertViewHas('usageTotal', 123)
            ->assertViewHas('days', fn ($days) => $days->pluck('units')->all() === [123, 0]);
    }

    public function test_invalid_tokens_are_rejected_and_never_flashed(): void
    {
        foreach (['short-secret', str_repeat('x', 64)] as $token) {
            $this->post(route('dashboard.session.store'), ['token' => $token])
                ->assertSessionHasErrors('token')->assertSessionMissing('_old_input.token')
                ->assertSessionMissing('merchant_token_hash');
        }
    }

    public function test_rotation_revokes_existing_browser_access(): void
    {
        $hash = hash('sha256', str_repeat('a', 64));
        $merchant = Merchant::factory()->create(['api_token_hash' => $hash]);
        $merchant->update(['api_token_hash' => hash('sha256', str_repeat('b', 64))]);
        $this->withSession(['merchant_token_hash' => $hash])->get('/')
            ->assertSee('Welcome back')->assertSessionMissing('merchant_token_hash');
    }

    public function test_sign_out_clears_access_and_empty_workspace_renders(): void
    {
        $hash = hash('sha256', str_repeat('a', 64));
        Merchant::factory()->create(['api_token_hash' => $hash]);
        $this->withSession(['merchant_token_hash' => $hash])->get('/')
            ->assertOk()->assertSee('No active subscriptions')->assertSee('No accounts flagged');
        $this->delete(route('dashboard.session.destroy'))->assertRedirect(route('dashboard.index'))
            ->assertSessionMissing('merchant_token_hash');
        $this->get('/')->assertSee('Welcome back');
    }
}
