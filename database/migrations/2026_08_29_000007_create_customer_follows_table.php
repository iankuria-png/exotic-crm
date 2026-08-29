<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table for everything a member follows.
 *
 * `profile` rows point at a WordPress profile post id. `location` rows point at
 * a WordPress location term id. WordPress validates those references before the
 * signed service call reaches the CRM; the CRM owns the durable account state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('follow_type', 40);
            $table->unsignedBigInteger('object_ref');
            $table->string('source', 80)->default('my_exotic');
            $table->timestamp('followed_at');
            $table->timestamps();

            $table->unique(
                ['customer_account_id', 'follow_type', 'object_ref'],
                'customer_follows_unique'
            );
            $table->index(['customer_account_id', 'followed_at'], 'customer_follows_account_time_idx');
            $table->index(['platform_id', 'follow_type', 'object_ref'], 'customer_follows_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_follows');
    }
};
