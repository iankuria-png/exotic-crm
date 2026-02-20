<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->boolean('renewal_reminders_paused')->default(false)->after('assigned_to');
            $table->dateTime('renewal_paused_until')->nullable()->after('renewal_reminders_paused');
            $table->string('renewal_pause_reason', 500)->nullable()->after('renewal_paused_until');

            $table->index('renewal_reminders_paused');
            $table->index('renewal_paused_until');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['renewal_reminders_paused']);
            $table->dropIndex(['renewal_paused_until']);

            $table->dropColumn([
                'renewal_reminders_paused',
                'renewal_paused_until',
                'renewal_pause_reason',
            ]);
        });
    }
};
