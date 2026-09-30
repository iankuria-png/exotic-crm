<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_monetization_settings', function (Blueprint $t) {
            $t->json('expiry_video_policy_json')->nullable();
            $t->json('free_pass_policy_json')->nullable();
        });
        Schema::table('premium_content_assets', function (Blueprint $t) {
            $t->string('origin')->default('creator');
        });
        Schema::table('premium_content_offers', function (Blueprint $t) {
            $t->string('origin')->default('creator');
            $t->string('origin_key')->nullable();
            $t->timestamp('owner_opted_out_at')->nullable();
            $t->string('bundle_scope')->nullable();
            $t->json('pricing_snapshot_json')->nullable();
            $t->unique(['platform_id', 'origin_key'], 'premium_offer_origin_unique');
        });
        Schema::table('client_monetization_passes', function (Blueprint $t) {
            $t->string('grant_source')->default('wallet');
            $t->unsignedBigInteger('source_deal_id')->nullable()->index();
            $t->unsignedBigInteger('grant_run_id')->nullable()->index();
        });
        Schema::create('monetization_automation_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('platform_id')->constrained();
            $t->string('kind');
            $t->string('status')->default('queued');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->json('rule_json');
            foreach (['estimated', 'total', 'succeeded', 'skipped', 'failed'] as $count) {
                $t->unsignedInteger($count.'_count')->default(0);
            }
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['platform_id', 'kind', 'id'], 'monetize_runs_lookup');
        });
        Schema::create('monetization_automation_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->nullable()->constrained('monetization_automation_runs')->cascadeOnDelete();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind');
            $t->unsignedBigInteger('deal_id')->nullable();
            $t->string('operation_key')->unique();
            $t->json('rule_json');
            $t->string('status')->default('queued');
            $t->string('result_code')->nullable();
            $t->json('result_json')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps();
            $t->index(['run_id', 'status'], 'monetize_items_run_status');
        });
        Schema::create('visitor_content_purchase_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained('visitor_content_purchases')->cascadeOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->unsignedInteger('share_numerator')->default(1);
            $t->unsignedInteger('share_denominator')->default(1);
            $t->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->default('pending');
            $t->string('idempotency_key')->unique();
            $t->timestamps();
            $t->unique(['purchase_id', 'client_id'], 'premium_allocation_creator_unique');
            $t->index(['client_id', 'status'], 'premium_allocation_creator_status');
        });

        // Settled purchases predate allocation rows: give each its single 100% row so
        // creator reporting reads one source. Pending purchases gain theirs at fulfilment.
        DB::table('visitor_content_purchases')->whereIn('status', ['active', 'refunded', 'revoked'])->whereNotNull('client_id')->orderBy('id')->chunkById(500, function ($rows) {
            DB::table('visitor_content_purchase_allocations')->insertOrIgnore($rows->map(fn ($p) => [
                'purchase_id' => $p->id, 'client_id' => $p->client_id, 'amount_minor' => (int) round((float) $p->gross_amount * 100), 'currency' => $p->currency,
                'share_numerator' => 1, 'share_denominator' => 1, 'wallet_transaction_id' => $p->wallet_transaction_id,
                'status' => $p->is_sandbox ? 'suppressed' : ($p->status === 'active' ? 'credited' : 'reversed'),
                'idempotency_key' => 'premium-content-credit:'.$p->id.':'.$p->client_id, 'created_at' => now(), 'updated_at' => now(),
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_content_purchase_allocations');
        Schema::dropIfExists('monetization_automation_items');
        Schema::dropIfExists('monetization_automation_runs');
        // Indexes first, then one column per statement: portable across MySQL and SQLite.
        Schema::table('client_monetization_passes', function (Blueprint $t) {
            $t->dropIndex(['source_deal_id']);
            $t->dropIndex(['grant_run_id']);
        });
        foreach (['grant_source', 'source_deal_id', 'grant_run_id'] as $column) {
            Schema::table('client_monetization_passes', fn (Blueprint $t) => $t->dropColumn($column));
        }
        Schema::table('premium_content_offers', fn (Blueprint $t) => $t->dropUnique('premium_offer_origin_unique'));
        foreach (['origin', 'origin_key', 'owner_opted_out_at', 'bundle_scope', 'pricing_snapshot_json'] as $column) {
            Schema::table('premium_content_offers', fn (Blueprint $t) => $t->dropColumn($column));
        }
        Schema::table('premium_content_assets', fn (Blueprint $t) => $t->dropColumn('origin'));
        foreach (['expiry_video_policy_json', 'free_pass_policy_json'] as $column) {
            Schema::table('content_monetization_settings', fn (Blueprint $t) => $t->dropColumn($column));
        }
    }
};
