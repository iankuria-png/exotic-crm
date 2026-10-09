<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_monetization_settings', function (Blueprint $table) {
            $table->json('preflight_json')->nullable();
            $table->json('setup_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_monetization_settings', fn (Blueprint $table) => $table->dropColumn(['preflight_json', 'setup_json']));
    }
};
