<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer activity events. Signed retention: 180 days, enforced by
 * `crm:purge-customer-data`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('object_type', 40)->nullable();
            $table->unsignedBigInteger('object_ref')->nullable();
            $table->timestamp('occurred_at');
            $table->json('context_json')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'event_type', 'occurred_at'], 'customer_events_platform_type_time_idx');
            $table->index(['customer_account_id', 'occurred_at'], 'customer_events_account_time_idx');
            $table->index('occurred_at', 'customer_events_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_activity_events');
    }
};
