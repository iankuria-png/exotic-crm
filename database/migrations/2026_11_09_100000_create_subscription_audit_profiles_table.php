<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_audit_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('subscription_audit_runs')->cascadeOnDelete();
            $table->string('profile_ref', 45);
            foreach (['platform_id', 'client_id', 'wp_post_id', 'wp_user_id'] as $key) {
                $table->unsignedBigInteger($key)->nullable();
            }
            $table->string('country', 100)->nullable();
            $table->boolean('business_active');
            $table->boolean('published');
            $table->dateTime('profile_as_of');
            $table->dateTime('source_last_synced_at')->nullable();
            $table->string('payment_history_state', 40);
            $table->string('action_payment_state', 40);
            $table->string('authorisation_state', 60);
            $table->json('payload');
            $table->unique(['run_id', 'platform_id', 'client_id'], 'sub_profile_identity');
            $table->index(['run_id', 'platform_id', 'business_active'], 'sub_profile_scope');
            $table->index(['run_id', 'country', 'payment_history_state'], 'sub_profile_payment');
        });
    }

    public function down(): void
    {
        // Derived evidence is retained on rollback; old application code ignores it.
    }
};
