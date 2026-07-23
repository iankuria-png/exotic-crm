<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Turn the global-default before-expiry SMS reminders (-7 and -3 days) OFF to
     * reduce spam. Markets that inherit the global cadence now only get the
     * on-expiry (0) and post-expiry (+3) nudges; any market can re-enable an early
     * reminder for itself via the Reminder cadence editor. Scoped strictly to the
     * global rows (platform_id IS NULL) so per-market overrides are untouched.
     */
    public function up(): void
    {
        DB::table('renewal_campaigns')
            ->whereNull('platform_id')
            ->where('channel', 'sms')
            ->whereIn('trigger_days', [-7, -3])
            ->update(['enabled' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('renewal_campaigns')
            ->whereNull('platform_id')
            ->where('channel', 'sms')
            ->whereIn('trigger_days', [-7, -3])
            ->update(['enabled' => true, 'updated_at' => now()]);
    }
};
