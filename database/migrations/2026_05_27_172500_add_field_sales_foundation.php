<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('assigned_to');
                $table->index('created_by', 'clients_created_by_idx');
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::table('deals', function (Blueprint $table) {
            if (!Schema::hasColumn('deals', 'activated_by_field_agent')) {
                $table->unsignedBigInteger('activated_by_field_agent')->nullable()->after('assigned_to');
                $table->index('activated_by_field_agent', 'deals_field_agent_idx');
                $table->foreign('activated_by_field_agent')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::table('platforms', function (Blueprint $table) {
            if (!Schema::hasColumn('platforms', 'field_activation_deposit_minor')) {
                $table->unsignedInteger('field_activation_deposit_minor')->default(5000)->after('currency_code');
            }
            if (!Schema::hasColumn('platforms', 'field_trial_duration_days')) {
                $table->unsignedSmallInteger('field_trial_duration_days')->default(7)->after('field_activation_deposit_minor');
            }
            if (!Schema::hasColumn('platforms', 'field_trial_product_id')) {
                $table->unsignedBigInteger('field_trial_product_id')->nullable()->after('field_trial_duration_days');
                $table->foreign('field_trial_product_id')->references('id')->on('products')->nullOnDelete();
            }
            if (!Schema::hasColumn('platforms', 'field_activation_commission_rate')) {
                $table->decimal('field_activation_commission_rate', 5, 4)->default('0.1500')->after('field_trial_product_id');
            }
            if (!Schema::hasColumn('platforms', 'field_renewal_commission_rate')) {
                $table->decimal('field_renewal_commission_rate', 5, 4)->default('0.0500')->after('field_activation_commission_rate');
            }
            if (!Schema::hasColumn('platforms', 'field_renewal_commission_months')) {
                $table->unsignedSmallInteger('field_renewal_commission_months')->default(4)->after('field_renewal_commission_rate');
            }
        });

        if (!Schema::hasTable('feature_settings')) {
            Schema::create('feature_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('commission_payouts')) {
            Schema::create('commission_payouts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agent_user_id');
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->decimal('total_amount', 12, 2);
                $table->string('currency', 3);
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('external_reference')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['agent_user_id', 'paid_at'], 'commission_payouts_agent_paid_idx');
                $table->foreign('agent_user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('commissions')) {
            Schema::create('commissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agent_user_id');
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('deal_id');
                $table->enum('type', ['activation', 'renewal']);
                $table->decimal('basis_amount', 12, 2);
                $table->decimal('rate', 5, 4);
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3);
                $table->enum('status', ['pending', 'earned', 'paid', 'void'])->default('earned');
                $table->timestamp('earned_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('payout_id')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['deal_id', 'type'], 'commissions_deal_type_unique');
                $table->index(['agent_user_id', 'status'], 'commissions_agent_status_idx');
                $table->index(['client_id', 'type'], 'commissions_client_type_idx');
                $table->foreign('agent_user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
                $table->foreign('deal_id')->references('id')->on('deals')->cascadeOnDelete();
                $table->foreign('payout_id')->references('id')->on('commission_payouts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_payouts');
        Schema::dropIfExists('feature_settings');

        Schema::table('platforms', function (Blueprint $table) {
            foreach ([
                'field_trial_product_id',
                'field_activation_deposit_minor',
                'field_trial_duration_days',
                'field_activation_commission_rate',
                'field_renewal_commission_rate',
                'field_renewal_commission_months',
            ] as $column) {
                if (Schema::hasColumn('platforms', $column)) {
                    if ($column === 'field_trial_product_id') {
                        $table->dropForeign(['field_trial_product_id']);
                    }
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('deals', function (Blueprint $table) {
            if (Schema::hasColumn('deals', 'activated_by_field_agent')) {
                $table->dropForeign(['activated_by_field_agent']);
                $table->dropColumn('activated_by_field_agent');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
        });
    }
};
