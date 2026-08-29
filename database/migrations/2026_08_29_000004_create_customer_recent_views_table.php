<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-durable recent views for signed-in members.
 *
 * Signed-out visitors keep the existing browser-local `escortwp_recently_viewed`
 * cookie untouched; only members get a row here. One row per viewed profile —
 * re-viewing bumps `last_viewed_at` and `view_count` rather than appending, so
 * the table cannot grow without bound from a single member refreshing a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_recent_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('object_type', 40)->default('profile');
            $table->unsignedBigInteger('object_ref');
            $table->unsignedInteger('view_count')->default(1);
            // Per-account monotonic view counter. `last_viewed_at` only has
            // second precision, so two profiles opened in the same second would
            // tie and fall back to row id — which is creation order, not view
            // order, and would show a just-opened profile below an older one.
            $table->unsignedBigInteger('view_seq')->default(0);
            $table->timestamp('first_viewed_at');
            $table->timestamp('last_viewed_at');
            $table->timestamps();

            $table->unique(
                ['customer_account_id', 'object_type', 'object_ref'],
                'customer_recent_views_unique'
            );
            $table->index(['customer_account_id', 'view_seq'], 'customer_recent_account_seq_idx');
            $table->index(['platform_id', 'last_viewed_at'], 'customer_recent_platform_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_recent_views');
    }
};
