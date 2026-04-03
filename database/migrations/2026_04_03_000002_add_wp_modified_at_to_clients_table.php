<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'wp_modified_at')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dateTime('wp_modified_at')->nullable()->after('last_synced_at');
            $table->index(['platform_id', 'wp_modified_at'], 'clients_platform_wp_modified_at_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('clients', 'wp_modified_at')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_platform_wp_modified_at_idx');
            $table->dropColumn('wp_modified_at');
        });
    }
};
