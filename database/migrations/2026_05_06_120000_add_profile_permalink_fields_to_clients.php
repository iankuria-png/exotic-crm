<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('wp_profile_permalink', 500)->nullable()->after('wp_user_id');
            $table->string('wp_profile_slug', 255)->nullable()->after('wp_profile_permalink');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'wp_profile_permalink',
                'wp_profile_slug',
            ]);
        });
    }
};
