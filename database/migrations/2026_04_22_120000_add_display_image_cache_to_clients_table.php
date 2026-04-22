<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('display_image_url', 500)->nullable()->after('main_image_url');
            $table->string('display_image_source', 50)->nullable()->after('display_image_url');
            $table->timestamp('display_image_checked_at')->nullable()->after('display_image_source');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'display_image_url',
                'display_image_source',
                'display_image_checked_at',
            ]);
        });
    }
};
