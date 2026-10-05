<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->char('api_token_hash', 64)->nullable()->unique();
        });
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->char('currency', 3)->default('INR');
            $table->string('billing_cycle', 16);
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('included_usage_units');
            $table->unsignedBigInteger('overage_rate_micros');
            $table->timestamps();
            $table->unique(['merchant_id', 'name']);
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $table->string('billing_cycle', 16);
            $table->char('currency', 3);
            $table->dateTime('starts_at');
            $table->dateTime('cycle_starts_at');
            $table->dateTime('cycle_ends_at')->index();
            $table->timestamps();
            $table->index(['merchant_id', 'id']);
        });
        Schema::create('subscription_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('plan_name', 100);
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('included_usage_units');
            $table->unsignedBigInteger('overage_rate_micros');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'starts_at']);
        });
        Schema::create('daily_usage_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_segment_id')->constrained()->restrictOnDelete();
            $table->date('usage_date');
            $table->unsignedBigInteger('units')->default(0);
            $table->timestamps();
            $table->unique(['subscription_segment_id', 'usage_date'], 'daily_segment_date_unique');
            $table->index(['merchant_id', 'usage_date', 'customer_id'], 'daily_merchant_date_customer');
            $table->index(['customer_id', 'usage_date']);
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->dateTime('cycle_starts_at');
            $table->dateTime('cycle_ends_at');
            $table->char('currency', 3);
            $table->unsignedBigInteger('base_total_minor');
            $table->unsignedBigInteger('overage_total_minor');
            $table->unsignedBigInteger('total_minor');
            $table->timestamps();
            $table->unique(['subscription_id', 'cycle_starts_at'], 'invoice_subscription_cycle_unique');
            $table->index(['merchant_id', 'id']);
        });
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_segment_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedBigInteger('duration_seconds');
            $table->unsignedBigInteger('cycle_seconds');
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('overage_rate_micros');
            $table->unsignedBigInteger('included_units');
            $table->unsignedBigInteger('usage_units');
            $table->unsignedBigInteger('overage_units');
            $table->unsignedBigInteger('base_total_minor');
            $table->unsignedBigInteger('overage_total_minor');
            $table->unsignedBigInteger('total_minor');
            $table->timestamps();
            $table->unique(['invoice_id', 'subscription_segment_id']);
        });
    }

    public function down(): void
    {
        foreach (['invoice_lines', 'invoices', 'daily_usage_aggregates', 'subscription_segments', 'subscriptions', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropUnique(['api_token_hash']);
            $table->dropColumn('api_token_hash');
        });
    }
};
