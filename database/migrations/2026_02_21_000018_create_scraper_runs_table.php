<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scraper_source_id');
            $table->unsignedBigInteger('platform_id');
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->string('mode', 20)->default('dry_run');
            $table->string('status', 30)->default('pending');
            $table->string('reason', 500)->nullable();
            $table->unsignedInteger('discovered_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('preview')->nullable();
            $table->json('result')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'created_at']);
            $table->index(['scraper_source_id', 'status']);

            $table->foreign('scraper_source_id')->references('id')->on('scraper_sources')->cascadeOnDelete();
            $table->foreign('platform_id')->references('id')->on('platforms')->cascadeOnDelete();
            $table->foreign('initiated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_runs');
    }
};
