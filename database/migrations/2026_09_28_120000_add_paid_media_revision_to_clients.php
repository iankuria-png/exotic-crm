<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'paid_media_revision')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->unsignedBigInteger('paid_media_revision')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'paid_media_revision')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('paid_media_revision');
            });
        }
    }
};
