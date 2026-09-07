<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            $table->boolean('client_sync_include_agencies')
                ->default(false)
                ->after('client_sync_capability_status');
            $table->json('client_sync_capability_meta')
                ->nullable()
                ->after('client_sync_include_agencies');
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            $table->dropColumn([
                'client_sync_include_agencies',
                'client_sync_capability_meta',
            ]);
        });
    }
};
