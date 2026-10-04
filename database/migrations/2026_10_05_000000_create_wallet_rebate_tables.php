<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebate_programs', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->foreignId('platform_id')->unique()->constrained();
            $t->string('rollout_mode', 16)->default('off');
            $t->boolean('kill_switch')->default(false);
            $t->json('draft_json');
            $t->unsignedInteger('draft_revision')->default(1);
            $t->unsignedInteger('published_revision')->nullable();
            $t->char('currency', 3);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->json('test_client_ids')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('rebate_program_revisions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->foreignId('rebate_program_id')->constrained();
            $t->unsignedInteger('revision');
            $t->string('action', 16);
            $t->json('snapshot_json');
            $t->json('diff_json');
            $t->string('reason', 1000);
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->timestamp('created_at');
            $t->unique(['rebate_program_id', 'revision']);
        });
        Schema::create('rebate_budget_periods', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->foreignId('platform_id')->constrained();
            $t->char('period_key', 7);
            $t->decimal('budget_amount', 12, 2);
            $t->decimal('issued_amount', 12, 2)->default(0);
            $t->timestamps();
            $t->unique(['platform_id', 'period_key']);
        });
        Schema::create('wallet_rebates', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('client_id')->constrained();
            $t->foreignId('rebate_program_id')->constrained();
            $t->unsignedInteger('program_revision');
            $t->string('trigger', 24);
            $t->string('channel', 32);
            $t->foreignId('source_payment_id')->constrained('payments');
            $t->decimal('base_amount', 12, 2);
            $t->decimal('rate_percent', 5, 2)->default(0);
            $t->decimal('calculated_amount', 12, 2)->default(0);
            $t->decimal('amount', 12, 2)->default(0);
            $t->char('currency', 3);
            $t->string('status', 16);
            $t->string('reason', 64)->nullable();
            $t->char('period_key', 7);
            $t->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions');
            $t->foreignId('reversal_transaction_id')->nullable()->constrained('wallet_transactions');
            $t->decimal('reversal_shortfall', 12, 2)->nullable();
            $t->string('idempotency_key', 100)->unique();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'period_key']);
            $t->index(['platform_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['wallet_rebates', 'rebate_budget_periods', 'rebate_program_revisions', 'rebate_programs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
