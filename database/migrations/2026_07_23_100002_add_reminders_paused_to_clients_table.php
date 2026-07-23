<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Client-level outreach pause. NULL = active. A future timestamp
            // suppresses both lifecycle SMS and renewal reminders until then;
            // a far-future value acts as an indefinite pause.
            $table->timestamp('reminders_paused_until')->nullable()->after('last_online_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('reminders_paused_until');
        });
    }
};
