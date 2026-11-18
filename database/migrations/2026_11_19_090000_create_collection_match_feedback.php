<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why staff activated a profile other than the system's suggestion. One row per receipt and chosen profile.
        Schema::create('collection_match_feedback', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('receipt_id');
            $t->uuid('account_id');
            $t->unsignedBigInteger('platform_id')->nullable();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('suggested_client_id')->nullable();
            $t->unsignedTinyInteger('suggested_score')->nullable();
            $t->json('suggested_rules')->nullable();
            $t->boolean('agreed');
            $t->string('reason', 32)->nullable();
            $t->text('note')->nullable();
            $t->string('source', 24);
            $t->unsignedBigInteger('actor_id');
            $t->string('rule_version', 40)->nullable();
            $t->timestamps();
            $t->unique(['receipt_id', 'client_id'], 'collection_match_feedback_choice');
            $t->index(['account_id', 'created_at'], 'collection_match_feedback_account_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_match_feedback');
    }
};
