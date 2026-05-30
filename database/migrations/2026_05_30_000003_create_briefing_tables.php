<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per command invocation.
        Schema::create('briefing_runs', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 16);            // ceo | sales
            $table->string('period', 16)->default('weekly');
            $table->timestamp('period_start');         // stored in UTC
            $table->timestamp('period_end');
            $table->foreignId('triggered_by')->nullable(); // null for scheduler
            $table->boolean('dry_run')->default(false);
            $table->string('status', 16)->default('pending'); // pending|completed|failed|skipped
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['audience', 'period_start']);
            $table->index('status');
        });

        // One row per distinct scope within a run.
        Schema::create('briefings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('briefing_run_id')->constrained('briefing_runs')->cascadeOnDelete();
            $table->string('audience', 16);
            $table->json('scope_platform_ids')->nullable(); // null = org-wide
            $table->string('scope_hash', 64);
            $table->string('period', 16)->default('weekly');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->text('summary_sms')->nullable();
            $table->longText('body_full')->nullable();      // JSON-encoded structured briefing
            $table->foreignId('generated_by')->nullable();
            $table->timestamps();

            // Dedupe: many sales scopes per week allowed, true duplicates blocked.
            $table->unique(['audience', 'period', 'period_start', 'scope_hash'], 'briefings_dedupe_uq');
        });

        // One row per person who receives a link.
        Schema::create('briefing_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('briefing_id')->constrained('briefings')->cascadeOnDelete();
            $table->foreignId('user_id');               // required — deep-link auth depends on it
            $table->string('name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('audience', 16);
            $table->json('scope_platform_ids')->nullable();
            $table->string('share_token', 32)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->text('sms_text')->nullable();
            $table->unsignedInteger('sms_char_count')->default(0);
            $table->unsignedInteger('sms_segments')->default(0);
            $table->string('delivery_status', 24)->default('pending'); // pending|sent|failed|disabled|skipped
            $table->unsignedBigInteger('sms_log_id')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->boolean('opt_out_snapshot')->default(false);
            $table->timestamps();

            $table->index(['briefing_id', 'user_id']);
            $table->index('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('briefing_recipients');
        Schema::dropIfExists('briefings');
        Schema::dropIfExists('briefing_runs');
    }
};
