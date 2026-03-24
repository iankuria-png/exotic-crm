<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('push_campaign_items', function (Blueprint $table) {
            $table->string('profile_city', 100)->nullable()->after('profile_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_campaign_items', function (Blueprint $table) {
            $table->dropColumn('profile_city');
        });
    }
};
