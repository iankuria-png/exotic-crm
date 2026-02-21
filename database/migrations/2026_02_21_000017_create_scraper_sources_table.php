<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->string('name', 255);
            $table->string('source_url', 500);
            $table->string('parser_profile', 50)->default('contact_cards');
            $table->json('parser_rules')->nullable();
            $table->string('fetch_schedule', 50)->default('manual_only');
            $table->string('dedupe_mode', 50)->default('phone_or_email');
            $table->boolean('is_active')->default(true);
            $table->boolean('compliance_ack_robots')->default(false);
            $table->boolean('compliance_ack_tos')->default(false);
            $table->string('compliance_notes', 500)->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->string('last_run_status', 30)->nullable();
            $table->json('last_run_summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('platform_id');
            $table->index(['platform_id', 'is_active']);
            $table->unique(['platform_id', 'source_url']);

            $table->foreign('platform_id')->references('id')->on('platforms')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_sources');
    }
};
