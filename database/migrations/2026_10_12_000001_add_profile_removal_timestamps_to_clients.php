<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->timestamp('profile_removal_requested_at')->nullable();
            $table->timestamp('profile_reverted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['profile_removal_requested_at', 'profile_reverted_at']);
        });
    }
};
