<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageEvent;
use Illuminate\Database\Seeder;

class UsageDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $merchant = Merchant::factory()->create(['name' => 'Demo Merchant']);

        Customer::factory()->for($merchant)->count(5)->create()->each(function (Customer $customer): void {
            UsageEvent::factory()->for($customer)->count(3)->create();
        });
    }
}
