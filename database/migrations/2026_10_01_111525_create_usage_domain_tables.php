<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
            $table->unique(['merchant_id', 'email']);
        });

        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->unsignedInteger('units');
            $table->date('usage_date');
            $table->timestamps();
            $table->unique(['customer_id', 'idempotency_key']);
            $table->index(['customer_id', 'usage_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('merchants');
    }
};
