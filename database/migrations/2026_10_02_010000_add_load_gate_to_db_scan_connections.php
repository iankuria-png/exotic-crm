<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('db_scan_connections', function (Blueprint $table) {
            $table->boolean('load_gate_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('db_scan_connections', function (Blueprint $table) {
            $table->dropColumn('load_gate_enabled');
        });
    }
};
