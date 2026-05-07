<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'google_sub')) {
                $table->string('google_sub')->nullable()->unique()->after('email');
            }

            if (!Schema::hasColumn('users', 'google_linked_at')) {
                $table->timestamp('google_linked_at')->nullable()->after('google_sub');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'google_linked_at')) {
                $table->dropColumn('google_linked_at');
            }

            if (Schema::hasColumn('users', 'google_sub')) {
                $table->dropUnique(['google_sub']);
                $table->dropColumn('google_sub');
            }
        });
    }
};
