<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_profile_presets', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 255)->unique();
            $table->unsignedBigInteger('platform_id')->nullable();
            $table->string('name_selector', 255)->nullable();
            $table->string('age_selector', 255)->nullable();
            $table->string('city_selector', 255)->nullable();
            $table->string('phone_selector', 255)->nullable();
            $table->string('image_selector', 255)->nullable();
            $table->string('name_regex', 255)->nullable();
            $table->string('age_regex', 255)->nullable();
            $table->string('test_url', 500)->nullable();
            $table->json('test_result')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('platform_id');
            $table->index('created_by');
            $table->index('is_active');

            $table->foreign('platform_id')->references('id')->on('platforms')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_profile_presets');
    }
};
