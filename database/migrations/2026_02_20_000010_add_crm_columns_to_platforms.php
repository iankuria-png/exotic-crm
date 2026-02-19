<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->string('wp_api_url', 255)->nullable()->after('product_id');
            $table->string('wp_api_user', 100)->nullable()->after('wp_api_url');
            $table->text('wp_api_password')->nullable()->after('wp_api_user');
            $table->string('phone_prefix', 5)->default('254')->after('wp_api_password');
            $table->string('timezone', 50)->default('Africa/Nairobi')->after('phone_prefix');
            $table->string('currency_code', 3)->default('KES')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn(['wp_api_url', 'wp_api_user', 'wp_api_password', 'phone_prefix', 'timezone', 'currency_code']);
        });
    }
};
