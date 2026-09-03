<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_sync_runs', function (Blueprint $table) {
            // A run is now executed as a series of bounded slices rather than
            // one long job, so it has to remember which stage it stopped in.
            $table->string('phase', 20)->default('clients')->after('status');
            $table->unsignedInteger('slices')->default(0)->after('phase');
        });
    }

    public function down(): void
    {
        Schema::table('client_sync_runs', function (Blueprint $table) {
            $table->dropColumn(['phase', 'slices']);
        });
    }
};
