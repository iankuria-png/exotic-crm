<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preference signals are bounded recommendation facts supplied by WordPress.
 *
 * They never store raw profile text, contact snapshots, private messages, raw
 * search text, or full URLs. Retention is 180 days and is enforced by
 * `crm:purge-customer-data`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_preference_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('signal_type', 60);
            $table->string('object_type', 40);
            $table->unsignedBigInteger('object_ref')->nullable();
            $table->smallInteger('weight');
            $table->json('fact_tokens_json');
            $table->json('context_json')->nullable();
            $table->string('source_surface', 80);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['customer_account_id', 'occurred_at'], 'customer_pref_signals_account_time_idx');
            $table->index(['platform_id', 'signal_type', 'occurred_at'], 'customer_pref_signals_platform_type_time_idx');
            $table->index(['platform_id', 'object_type', 'object_ref', 'occurred_at'], 'customer_pref_signals_object_time_idx');
            $table->index('occurred_at', 'customer_pref_signals_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_preference_signals');
    }
};
