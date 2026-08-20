<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLUG_INDEX = 'clients_platform_profile_slug_idx';

    private const PERMALINK_INDEX = 'clients_platform_profile_permalink_idx';

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->index(['platform_id', 'wp_profile_slug'], self::SLUG_INDEX);
            $table->index(['platform_id', 'wp_profile_permalink'], self::PERMALINK_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(self::SLUG_INDEX);
            $table->dropIndex(self::PERMALINK_INDEX);
        });
    }
};
