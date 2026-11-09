<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_sales_items', function (Blueprint $t) {
            $t->unsignedInteger('generation')->default(1);
        });
        Schema::table('collection_outbox', function (Blueprint $t) {
            $t->unsignedInteger('source_generation')->nullable();
            $t->unsignedBigInteger('source_deal_id')->nullable();
        });
        Schema::create('collection_sales_corrections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('item_id');
            $t->unsignedBigInteger('actor_id');
            $t->unsignedInteger('item_version');
            $t->unsignedInteger('generation');
            $t->unsignedInteger('version')->default(1);
            $t->string('action', 24);
            $t->string('state', 32)->default('previewed');
            $t->text('reason');
            $t->json('preview');
            $t->string('request_key', 191)->nullable()->unique();
            $t->uuid('compensation_key')->unique();
            $t->uuid('target_key')->unique();
            $t->unsignedBigInteger('target_deal_id')->nullable();
            $t->json('compensation_result')->nullable();
            $t->json('target_result')->nullable();
            $t->dateTime('expires_at');
            $t->timestamps();
            $t->index(['item_id', 'state']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain sale correction provenance and generation fences; pause dispatch instead.');
    }
};
