<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renewal_runs', function (Blueprint $table): void {
            if (!Schema::hasColumn('renewal_runs', 'currency')) {
                $table->string('currency', 3)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('renewal_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('renewal_runs', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
