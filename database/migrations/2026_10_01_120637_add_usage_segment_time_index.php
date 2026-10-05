<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', fn (Blueprint $table) => $table->index(['subscription_segment_id', 'occurred_at'], 'usage_segment_time'));
    }

    public function down(): void
    {
        Schema::table('usage_events', fn (Blueprint $table) => $table->dropIndex('usage_segment_time'));
    }
};
