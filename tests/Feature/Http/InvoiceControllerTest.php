<?php

namespace Tests\Feature\Http;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Merchant;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_invoices_are_paginated_and_foreign_records_return_404(): void
    {
        $token = str_repeat('a', 64);
        $merchant = Merchant::factory()->create(['api_token_hash' => hash('sha256', $token)]);
        $customer = Customer::factory()->for($merchant)->create();
        $subscription = Subscription::factory()->for($customer)->create();
        $invoice = Invoice::factory()->for($subscription)->create();
        InvoiceLine::factory()->for($invoice)->create();
        $foreign = Invoice::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/invoices', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $invoice->id);
        $this->getJson('/api/invoices/'.$invoice->id, $headers)->assertOk()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.total_minor', 10000);
        $this->getJson('/api/invoices/'.$foreign->id, $headers)->assertNotFound();
    }

    public function test_invoice_endpoints_require_authentication(): void
    {
        $this->getJson('/api/invoices')->assertUnauthorized();
        $this->getJson('/api/invoices/1')->assertUnauthorized();
    }
}
