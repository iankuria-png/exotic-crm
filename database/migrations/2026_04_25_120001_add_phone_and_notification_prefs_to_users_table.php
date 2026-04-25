<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $needsPhone = !Schema::hasColumn('users', 'phone');
        $needsNotificationPrefs = !Schema::hasColumn('users', 'notification_prefs');

        if (!$needsPhone && !$needsNotificationPrefs) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($needsPhone, $needsNotificationPrefs) {
            if ($needsPhone) {
                $table->string('phone', 30)->nullable()->after('status');
            }

            if ($needsNotificationPrefs) {
                $table->json('notification_prefs')->nullable()->after($needsPhone ? 'phone' : 'status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'notification_prefs')) {
                $table->dropColumn('notification_prefs');
            }

            if (Schema::hasColumn('users', 'phone')) {
                $table->dropColumn('phone');
            }
        });
    }
};
