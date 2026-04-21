<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('subscription_lifecycle', 20)->nullable()->after('origin');
            $table->string('subscription_lifecycle_source', 40)->nullable()->after('subscription_lifecycle');
            $table->text('subscription_lifecycle_reason')->nullable()->after('subscription_lifecycle_source');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('subscription_lifecycle', 20)->nullable()->after('purpose');
            $table->string('subscription_lifecycle_source', 40)->nullable()->after('subscription_lifecycle');
            $table->text('subscription_lifecycle_reason')->nullable()->after('subscription_lifecycle_source');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_lifecycle',
                'subscription_lifecycle_source',
                'subscription_lifecycle_reason',
            ]);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_lifecycle',
                'subscription_lifecycle_source',
                'subscription_lifecycle_reason',
            ]);
        });
    }
};
