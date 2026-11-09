<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_match_exclusions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->string('payer_fingerprint', 64);
            $t->unsignedBigInteger('client_id');
            $t->string('source_owner', 128)->unique();
            $t->unsignedBigInteger('receipt_id');
            $t->unsignedBigInteger('actor_id');
            $t->text('reason');
            $t->dateTime('effective_at');
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('cleared_at')->nullable();
            $t->unsignedBigInteger('cleared_by')->nullable();
            $t->text('clear_reason')->nullable();
            $t->uuid('clear_intent_id')->nullable();
            $t->index(['account_id', 'payer_fingerprint', 'client_id', 'cleared_at'], 'collection_match_exclusion_scope');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain correction evidence while automation is paused; destructive rollback is unsupported.');
    }
};
