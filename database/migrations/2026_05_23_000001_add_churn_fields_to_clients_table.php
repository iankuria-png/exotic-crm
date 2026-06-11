<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('churned_at')->nullable()->after('purge_after');
            $table->string('churn_reason_code', 64)->nullable()->after('churned_at');
            $table->string('churn_source', 32)->nullable()->after('churn_reason_code');
            $table->timestamp('first_activated_at')->nullable()->after('churn_source');

            $table->index('churned_at');
            $table->index('first_activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['churned_at']);
            $table->dropIndex(['first_activated_at']);
            $table->dropColumn(['churned_at', 'churn_reason_code', 'churn_source', 'first_activated_at']);
        });
    }
};
