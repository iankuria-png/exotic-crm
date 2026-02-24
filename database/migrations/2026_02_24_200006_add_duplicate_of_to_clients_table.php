<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'duplicate_of')) {
                $table->unsignedBigInteger('duplicate_of')->nullable()->after('assigned_to');
                $table->index('duplicate_of');
                $table->foreign('duplicate_of')->references('id')->on('clients');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'duplicate_of')) {
                $table->dropForeign(['duplicate_of']);
                $table->dropIndex(['duplicate_of']);
                $table->dropColumn('duplicate_of');
            }
        });
    }
};
