<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('assigned_market_ids')->nullable()->after('role');
            $table->enum('status', ['active', 'inactive'])->default('active')->after('assigned_market_ids');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['assigned_market_ids', 'status']);
        });
    }
};
