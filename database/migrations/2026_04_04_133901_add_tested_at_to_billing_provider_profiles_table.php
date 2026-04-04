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
        Schema::table('billing_provider_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_provider_profiles', 'tested_at')) {
                $table->timestamp('tested_at')->nullable()->after('active');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_provider_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('billing_provider_profiles', 'tested_at')) {
                $table->dropColumn('tested_at');
            }
        });
    }
};
