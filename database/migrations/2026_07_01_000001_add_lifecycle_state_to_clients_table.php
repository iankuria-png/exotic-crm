<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->enum('lifecycle_state', ['active', 'expired', 'archived', 'removed'])
                ->default('active')
                ->after('profile_status');
            $table->timestamp('lifecycle_expired_at')->nullable()->after('lifecycle_state');
            $table->timestamp('lifecycle_archived_at')->nullable()->after('lifecycle_expired_at');
            $table->index('lifecycle_state');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['lifecycle_state']);
            $table->dropColumn(['lifecycle_state', 'lifecycle_expired_at', 'lifecycle_archived_at']);
        });
    }
};
