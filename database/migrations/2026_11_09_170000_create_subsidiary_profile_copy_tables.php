<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: paired-market promotion policy and one-time profile copy ledgers. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subsidiary_promotion_policies')) {
            Schema::create('subsidiary_promotion_policies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('source_platform_id');
                $table->unsignedBigInteger('target_platform_id');
                $table->string('duration_mode', 20)->default('match_primary');
                $table->unsignedSmallInteger('fixed_days')->nullable();
                $table->boolean('allow_day_override')->default(true);
                $table->boolean('copy_new_profile')->default(true);
                $table->unsignedInteger('version')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['source_platform_id', 'target_platform_id'], 'subsidiary_promotion_pair_unique');
            });
        }

        if (! Schema::hasTable('subsidiary_profile_copy_runs')) {
            Schema::create('subsidiary_profile_copy_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('request_id')->unique();
                $table->unsignedBigInteger('source_platform_id');
                $table->unsignedBigInteger('source_client_id');
                $table->unsignedBigInteger('source_wp_post_id');
                $table->unsignedBigInteger('target_platform_id');
                $table->unsignedBigInteger('target_client_id')->nullable();
                $table->unsignedBigInteger('target_wp_post_id')->nullable();
                $table->unsignedBigInteger('target_wp_user_id')->nullable();
                $table->string('provision_request_id', 64)->nullable();
                $table->char('provision_payload_hash', 64)->nullable();
                $table->char('target_baseline_digest', 64)->nullable();
                $table->longText('snapshot_json');
                $table->char('snapshot_digest', 64);
                $table->json('media_manifest_json');
                $table->string('duration_mode', 20);
                $table->unsignedSmallInteger('duration_days');
                $table->unsignedInteger('policy_version')->default(0);
                $table->string('state', 20)->default('queued');
                $table->unsignedInteger('version')->default(1);
                $table->uuid('lease_owner')->nullable();
                $table->timestamp('lease_until')->nullable();
                $table->unsignedBigInteger('fence')->default(0);
                $table->timestamp('cancellation_requested_at')->nullable();
                $table->json('progress_json')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->index(['target_platform_id', 'target_wp_post_id'], 'subsidiary_copy_target_idx');
                $table->index(['state', 'lease_until'], 'subsidiary_copy_state_idx');
            });
        }

        if (! Schema::hasTable('subsidiary_profile_copy_items')) {
            Schema::create('subsidiary_profile_copy_items', function (Blueprint $table) {
                $table->id();
                $table->uuid('run_id');
                $table->string('item_key', 64);
                $table->string('kind', 10);
                $table->unsignedSmallInteger('position')->default(0);
                $table->unsignedBigInteger('source_attachment_id')->nullable();
                $table->char('source_sha256', 64)->nullable();
                $table->unsignedBigInteger('target_attachment_id')->nullable();
                $table->string('state', 20)->default('prepared');
                $table->char('payload_hash', 64)->nullable();
                $table->uuid('destination_operation_key');
                $table->unsignedInteger('attempts')->default(0);
                $table->string('error_code', 64)->nullable();
                $table->json('result_json')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['run_id', 'item_key'], 'subsidiary_copy_item_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subsidiary_profile_copy_items');
        Schema::dropIfExists('subsidiary_profile_copy_runs');
        Schema::dropIfExists('subsidiary_promotion_policies');
    }
};
