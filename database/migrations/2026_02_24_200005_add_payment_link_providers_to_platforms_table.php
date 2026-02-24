<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            if (!Schema::hasColumn('platforms', 'payment_link_providers')) {
                $table->json('payment_link_providers')->nullable()->after('sync_last_result');
            }
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            if (Schema::hasColumn('platforms', 'payment_link_providers')) {
                $table->dropColumn('payment_link_providers');
            }
        });
    }
};
