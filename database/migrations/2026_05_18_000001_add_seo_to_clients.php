<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('seo_score')->nullable()->after('source_missing_count');
            $table->json('seo_score_breakdown')->nullable()->after('seo_score');
            $table->timestamp('seo_score_updated_at')->nullable()->after('seo_score_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['seo_score', 'seo_score_breakdown', 'seo_score_updated_at']);
        });
    }
};
