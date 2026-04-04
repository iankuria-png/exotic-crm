<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('billing_provider_profiles')) {
            Schema::create('billing_provider_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('provider_type_key', 80)->index();
                $table->string('profile_name', 160);
                $table->string('country_code', 8)->nullable();
                $table->foreignId('market_id')->nullable()->constrained('platforms')->nullOnDelete();
                $table->json('merchant_scope_json')->nullable();
                $table->string('environment', 32)->default('production');
                $table->json('config_json')->nullable();
                $table->json('secrets_json')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index(['market_id', 'provider_type_key'], 'billing_profiles_market_provider_idx');
                $table->index(['provider_type_key', 'environment', 'active'], 'billing_profiles_provider_env_active_idx');
            });
        }

        if (!Schema::hasTable('billing_market_provider_bindings')) {
            Schema::create('billing_market_provider_bindings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('market_id')->constrained('platforms')->cascadeOnDelete();
                $table->foreignId('provider_profile_id')->constrained('billing_provider_profiles')->cascadeOnDelete();
                $table->string('billing_surface', 64);
                $table->boolean('enabled')->default(true);
                $table->boolean('operator_enabled')->default(true);
                $table->boolean('self_service_enabled')->default(false);
                $table->string('execution_mode', 32)->default('direct');
                $table->unsignedSmallInteger('priority')->default(100);
                $table->string('fallback_group', 80)->nullable();
                $table->json('restriction_json')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['market_id', 'billing_surface', 'enabled'], 'billing_bindings_market_surface_enabled_idx');
                $table->unique(
                    ['market_id', 'provider_profile_id', 'billing_surface'],
                    'billing_bindings_market_profile_surface_uq'
                );
            });
        }

        if (!Schema::hasTable('billing_routing_rules')) {
            Schema::create('billing_routing_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('market_id')->constrained('platforms')->cascadeOnDelete();
                $table->string('billing_surface', 64);
                $table->foreignId('primary_binding_id')->nullable()->constrained('billing_market_provider_bindings')->nullOnDelete();
                $table->json('fallback_strategy_json')->nullable();
                $table->json('risk_policy_json')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(['market_id', 'billing_surface'], 'billing_routing_rules_market_surface_uq');
            });
        }

        if (!Schema::hasTable('billing_wallet_rules')) {
            Schema::create('billing_wallet_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('market_id')->constrained('platforms')->cascadeOnDelete();
                $table->boolean('enabled')->default(false);
                $table->string('currency_code', 8)->nullable();
                $table->json('topup_preset_json')->nullable();
                $table->json('limit_json')->nullable();
                $table->json('auto_renew_json')->nullable();
                $table->json('ui_json')->nullable();
                $table->timestamps();

                $table->unique('market_id', 'billing_wallet_rules_market_uq');
            });
        }

        if (!Schema::hasTable('billing_subscription_rules')) {
            Schema::create('billing_subscription_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('market_id')->constrained('platforms')->cascadeOnDelete();
                $table->json('activation_method_json')->nullable();
                $table->json('renewal_method_json')->nullable();
                $table->json('free_trial_json')->nullable();
                $table->json('discount_json')->nullable();
                $table->json('expiry_policy_json')->nullable();
                $table->timestamps();

                $table->unique('market_id', 'billing_subscription_rules_market_uq');
            });
        }

        if (!Schema::hasTable('billing_routing_decisions')) {
            Schema::create('billing_routing_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
                $table->foreignId('market_id')->nullable()->constrained('platforms')->nullOnDelete();
                $table->string('billing_surface', 64);
                $table->foreignId('chosen_binding_id')->nullable()->constrained('billing_market_provider_bindings')->nullOnDelete();
                $table->foreignId('provider_profile_id')->nullable()->constrained('billing_provider_profiles')->nullOnDelete();
                $table->string('provider_type_key', 80)->nullable();
                $table->string('execution_mode', 32)->nullable();
                $table->string('environment', 32)->nullable();
                $table->boolean('fallback_taken')->default(false);
                $table->unsignedInteger('decision_version')->default(1);
                $table->json('shadow_diff_json')->nullable();
                $table->string('surface_cutover_flag', 120)->nullable();
                $table->json('snapshot_json');
                $table->boolean('immutable_until_terminal_state')->default(true);
                $table->json('decision_json')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['payment_id', 'billing_surface'], 'billing_route_decisions_payment_surface_idx');
                $table->index(['market_id', 'billing_surface'], 'billing_route_decisions_market_surface_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_routing_decisions');
        Schema::dropIfExists('billing_subscription_rules');
        Schema::dropIfExists('billing_wallet_rules');
        Schema::dropIfExists('billing_routing_rules');
        Schema::dropIfExists('billing_market_provider_bindings');
        Schema::dropIfExists('billing_provider_profiles');
    }
};
