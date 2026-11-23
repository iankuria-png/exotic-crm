<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraper_sources', function (Blueprint $table) {
            $table->string('template_key', 80)->nullable()->after('parser_profile');
            $table->string('fetch_driver', 30)->default('http')->after('template_key');
            $table->json('crawl_settings')->nullable()->after('parser_rules');
            $table->json('site_profile')->nullable()->after('crawl_settings');
            $table->string('health_status', 30)->nullable()->after('site_profile');
            $table->dateTime('last_probed_at')->nullable()->after('health_status');
        });

        Schema::table('scraper_runs', function (Blueprint $table) {
            $table->json('progress')->nullable()->after('result');
            $table->unsignedInteger('pages_fetched')->default(0)->after('error_count');
            $table->dateTime('cancel_requested_at')->nullable()->after('completed_at');
        });

        Schema::create('scraper_captures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scraper_source_id')->nullable();
            $table->unsignedBigInteger('platform_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('expected_host', 255)->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->unsignedInteger('bytes')->default(0);
            $table->string('method', 20)->default('bookmarklet');
            $table->string('status', 20)->default('pending');
            $table->dateTime('expires_at');
            $table->dateTime('received_at')->nullable();
            $table->timestamps();

            $table->index(['scraper_source_id', 'status']);
            $table->foreign('scraper_source_id')->references('id')->on('scraper_sources')->nullOnDelete();
            $table->foreign('platform_id')->references('id')->on('platforms')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_captures');

        Schema::table('scraper_runs', function (Blueprint $table) {
            $table->dropColumn(['progress', 'pages_fetched', 'cancel_requested_at']);
        });

        Schema::table('scraper_sources', function (Blueprint $table) {
            $table->dropColumn(['template_key', 'fetch_driver', 'crawl_settings', 'site_profile', 'health_status', 'last_probed_at']);
        });
    }
};
