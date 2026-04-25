<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('new_badge_mode', 20)->default('auto')->after('force_new');
        });

        DB::table('clients')
            ->where('force_new', true)
            ->update(['new_badge_mode' => 'force_on']);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('new_badge_mode');
        });
    }
};
