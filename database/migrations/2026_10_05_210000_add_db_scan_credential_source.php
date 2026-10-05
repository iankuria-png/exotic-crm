<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('db_scan_connections', function (Blueprint $table) {
            $table->string('credential_source', 20)->default('dedicated');
            $table->string('preflight_credential_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('db_scan_connections', function (Blueprint $table) {
            $table->dropColumn(['credential_source', 'preflight_credential_fingerprint']);
        });
    }
};
