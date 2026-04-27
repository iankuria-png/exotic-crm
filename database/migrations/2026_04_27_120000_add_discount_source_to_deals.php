<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('discount_source', 40)->nullable()->after('discount_approved_by');
        });

        DB::table('deals')
            ->whereNotNull('discount_percentage')
            ->whereNotNull('discount_approved_by')
            ->update(['discount_source' => 'agent_manual']);
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('discount_source');
        });
    }
};
