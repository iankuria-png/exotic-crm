<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pbn_seed_batches', function (Blueprint $table) {
            $table->unsignedSmallInteger('reverted_count')->default(0)->after('failed_count');
            $table->timestamp('reverted_at')->nullable()->after('completed_at');
            $table->foreignId('reverted_by')->nullable()->after('reverted_at')->constrained('users')->nullOnDelete();
            $table->text('revert_reason')->nullable()->after('reverted_by');

            $table->index(['status', 'created_at']);
            $table->index(['reverted_at']);
        });

        Schema::table('pbn_seed_items', function (Blueprint $table) {
            $table->timestamp('provision_started_at')->nullable()->after('failure_reason');
            $table->timestamp('provision_finished_at')->nullable()->after('provision_started_at');
            $table->string('original_target_post_status', 32)->nullable()->after('provision_finished_at');
            $table->timestamp('reverted_at')->nullable()->after('original_target_post_status');
            $table->foreignId('reverted_by')->nullable()->after('reverted_at')->constrained('users')->nullOnDelete();
            $table->text('revert_reason')->nullable()->after('reverted_by');
            $table->text('revert_failure_reason')->nullable()->after('revert_reason');

            $table->index(['pbn_site_id', 'status', 'created_at']);
            $table->index(['source_platform_id', 'status']);
            $table->index(['source_client_id']);
            $table->index(['source_wp_post_id']);
            $table->index(['reverted_at']);
        });

        Schema::create('pbn_seed_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbn_site_id')->constrained('pbn_sites')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('pbn_seed_batches')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('pbn_seed_items')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 80);
            $table->string('level', 16)->default('info');
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['pbn_site_id', 'created_at']);
            $table->index(['batch_id', 'created_at']);
            $table->index(['item_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pbn_seed_events');

        Schema::table('pbn_seed_items', function (Blueprint $table) {
            $table->dropIndex(['pbn_site_id', 'status', 'created_at']);
            $table->dropIndex(['source_platform_id', 'status']);
            $table->dropIndex(['source_client_id']);
            $table->dropIndex(['source_wp_post_id']);
            $table->dropIndex(['reverted_at']);
            $table->dropConstrainedForeignId('reverted_by');
            $table->dropColumn([
                'provision_started_at',
                'provision_finished_at',
                'original_target_post_status',
                'reverted_at',
                'revert_reason',
                'revert_failure_reason',
            ]);
        });

        Schema::table('pbn_seed_batches', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['reverted_at']);
            $table->dropConstrainedForeignId('reverted_by');
            $table->dropColumn([
                'reverted_count',
                'reverted_at',
                'revert_reason',
            ]);
        });
    }
};
