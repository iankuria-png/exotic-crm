<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('billing_wallet_rules', 'fx_override_json')) {
            Schema::table('billing_wallet_rules', function (Blueprint $table) {
                $table->json('fx_override_json')->nullable()->after('ui_json');
            });
        }
    }

    public function down(): void
    {
        Schema::table('billing_wallet_rules', function (Blueprint $table) {
            $table->dropColumn('fx_override_json');
        });
    }
};
