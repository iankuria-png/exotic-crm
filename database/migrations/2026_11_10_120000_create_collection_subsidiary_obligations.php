<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_subsidiary_obligations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('allocation_id')->constrained('collection_allocations')->restrictOnDelete();
            $t->uuid('primary_effect_id')->unique();
            $t->uuid('correction_id')->nullable();
            $t->unsignedInteger('source_generation')->default(1);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('source_deal_id');
            $t->unsignedBigInteger('target_platform_id');
            $t->json('interval');
            $t->text('target_identity')->nullable();
            $t->json('policy_snapshot');
            $t->json('setup_options')->nullable();
            $t->unsignedBigInteger('trial_request_id')->nullable()->unique();
            $t->uuid('dependent_effect_id')->nullable()->unique();
            $t->string('state', 32)->default('pending')->index();
            $t->string('last_error_code', 80)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['allocation_id', 'source_generation'], 'collection_subsidiary_generation');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain subsidiary ownership and recovery evidence; disable new dispatch instead.');
    }
};
