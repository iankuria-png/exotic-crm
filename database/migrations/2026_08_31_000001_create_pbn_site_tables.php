<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pbn_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain')->unique();
            $table->foreignId('default_source_platform_id')->nullable()->constrained('platforms')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->string('country')->nullable();
            $table->string('timezone', 64)->default('Africa/Nairobi');
            $table->char('currency_code', 3)->default('KES');
            $table->string('phone_prefix', 8)->default('254');
            $table->string('wp_api_url')->nullable();
            $table->string('wp_api_user', 100)->nullable();
            $table->text('wp_api_password')->nullable();
            $table->string('db_host')->nullable();
            $table->string('db_name')->nullable();
            $table->string('db_user')->nullable();
            $table->text('db_pass')->nullable();
            $table->string('db_prefix', 32)->nullable()->default('wp_');
            $table->json('copy_policy')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_status', 24)->default('draft');
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'last_status']);
            $table->index(['default_source_platform_id', 'is_active']);
        });

        Schema::create('pbn_site_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbn_site_id')->constrained('pbn_sites')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('weight')->default(100);
            $table->timestamps();

            $table->unique(['pbn_site_id', 'platform_id']);
            $table->index(['pbn_site_id', 'is_default']);
        });

        Schema::create('pbn_seed_previews', function (Blueprint $table) {
            $table->id();
            $table->char('preview_token', 64)->unique();
            $table->foreignId('pbn_site_id')->constrained('pbn_sites')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('payload_hash', 64);
            $table->timestamp('expires_at');
            $table->string('status', 24)->default('active');
            $table->json('source_platform_ids');
            $table->json('targets');
            $table->json('copy_policy')->nullable();
            $table->json('candidate_summary')->nullable();
            $table->timestamps();

            $table->index(['pbn_site_id', 'created_by', 'status', 'expires_at']);
        });

        Schema::create('pbn_seed_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbn_site_id')->constrained('pbn_sites')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('queued');
            $table->json('source_platform_ids');
            $table->unsignedSmallInteger('target_count')->default(0);
            $table->unsignedSmallInteger('selected_count')->default(0);
            $table->unsignedSmallInteger('created_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->json('warnings')->nullable();
            $table->json('copy_policy')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['pbn_site_id', 'status', 'created_at']);
            $table->index(['created_by', 'created_at']);
        });

        Schema::create('pbn_seed_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('pbn_seed_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('target_region_id')->nullable();
            $table->unsignedBigInteger('target_city_id')->nullable();
            $table->string('region_name')->nullable();
            $table->string('city_name')->nullable();
            $table->unsignedSmallInteger('target_count')->default(0);
            $table->unsignedSmallInteger('selected_count')->default(0);
            $table->unsignedSmallInteger('created_count')->default(0);
            $table->timestamps();

            $table->index(['batch_id']);
            $table->index(['target_region_id', 'target_city_id']);
        });

        Schema::create('pbn_seed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('pbn_seed_batches')->cascadeOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('pbn_seed_targets')->nullOnDelete();
            $table->foreignId('pbn_site_id')->constrained('pbn_sites')->cascadeOnDelete();
            $table->foreignId('source_platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('source_client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('source_wp_post_id');
            $table->unsignedBigInteger('target_region_id')->nullable();
            $table->unsignedBigInteger('target_city_id')->nullable();
            $table->unsignedBigInteger('target_wp_post_id')->nullable();
            $table->unsignedBigInteger('target_wp_user_id')->nullable();
            $table->string('status', 32)->default('selected');
            $table->string('duplicate_state', 32)->default('none');
            $table->unsignedSmallInteger('quality_score')->nullable();
            $table->char('payload_hash', 64);
            $table->json('eligibility_snapshot')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['pbn_site_id', 'source_platform_id', 'source_client_id'], 'pbn_seed_items_source_unique');
            $table->index(['batch_id', 'status']);
            $table->index(['target_wp_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pbn_seed_items');
        Schema::dropIfExists('pbn_seed_targets');
        Schema::dropIfExists('pbn_seed_batches');
        Schema::dropIfExists('pbn_seed_previews');
        Schema::dropIfExists('pbn_site_sources');
        Schema::dropIfExists('pbn_sites');
    }
};
