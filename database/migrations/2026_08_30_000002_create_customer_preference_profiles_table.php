<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compact member recommendation aggregate read by WordPress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_preference_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->json('facet_weights_json')->nullable();
            $table->unsignedInteger('signal_count')->default(0);
            $table->unsignedInteger('positive_signal_count')->default(0);
            $table->timestamp('last_signal_at')->nullable();
            $table->timestamp('last_rebuilt_at')->nullable();
            $table->timestamp('reset_at')->nullable();
            $table->timestamps();

            $table->unique('customer_account_id', 'customer_pref_profiles_account_unique');
            $table->index(['platform_id', 'last_signal_at'], 'customer_pref_profiles_platform_signal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_preference_profiles');
    }
};
