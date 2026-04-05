<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE billing_proxy_sessions MODIFY billing_routing_decision_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE billing_proxy_sessions MODIFY provider_profile_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE billing_proxy_sessions MODIFY billing_routing_decision_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE billing_proxy_sessions MODIFY provider_profile_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
