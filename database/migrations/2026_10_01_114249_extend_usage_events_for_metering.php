<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_segment_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('aggregated_at')->nullable();
            $table->index(['aggregated_at', 'id'], 'usage_pending_aggregation');
            $table->index(['subscription_segment_id', 'aggregated_at', 'id'], 'usage_segment_pending');
            $table->index(['merchant_id', 'occurred_at', 'id'], 'usage_merchant_time');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE usage_events MODIFY idempotency_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
            $table->dropForeign(['subscription_segment_id']);
            $table->dropIndex('usage_pending_aggregation');
            $table->dropIndex('usage_segment_pending');
            $table->dropIndex('usage_merchant_time');
            $table->dropColumn(['merchant_id', 'subscription_segment_id', 'occurred_at', 'aggregated_at']);
        });
    }
};
