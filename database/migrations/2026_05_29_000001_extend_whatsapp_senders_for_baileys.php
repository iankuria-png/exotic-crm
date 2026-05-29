<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_senders', function (Blueprint $table) {
            $table->dropUnique('uniq_whatsapp_sender_phone');
        });

        Schema::table('whatsapp_senders', function (Blueprint $table) {
            $table->string('display_name', 120)->nullable()->after('phone_e164');
            $table->longText('auth_state_encrypted')->nullable()->after('display_name');
            $table->string('connection_status', 32)->default('pairing')->after('auth_state_encrypted');
            $table->string('warmup_phase', 32)->default('day_1_3')->after('connection_status');
            $table->timestamp('warmup_started_at')->nullable()->after('warmup_phase');
            $table->unsignedInteger('daily_limit')->default(20)->after('warmup_started_at');
            $table->unsignedInteger('daily_sent_count')->default(0)->after('daily_limit');
            $table->timestamp('daily_sent_resets_at')->nullable()->after('daily_sent_count');
            $table->timestamp('quarantine_until')->nullable()->after('daily_sent_resets_at');
            $table->timestamp('last_message_at')->nullable()->after('quarantine_until');
            $table->string('last_disconnect_reason')->nullable()->after('last_message_at');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_disconnect_reason');
            $table->timestamp('retired_at')->nullable()->after('consecutive_failures');
            $table->string('retired_reason')->nullable()->after('retired_at');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE whatsapp_senders
                 ADD COLUMN active_phone_marker VARCHAR(32)
                 GENERATED ALWAYS AS (CASE WHEN retired_at IS NULL THEN phone_e164 END) VIRTUAL'
            );
        } else {
            Schema::table('whatsapp_senders', function (Blueprint $table) {
                $table->string('active_phone_marker', 32)->nullable()->after('phone_e164');
            });

            DB::table('whatsapp_senders')->whereNull('retired_at')->update([
                'active_phone_marker' => DB::raw('phone_e164'),
            ]);
        }

        Schema::table('whatsapp_senders', function (Blueprint $table) {
            $table->unique('active_phone_marker', 'uniq_active_sender_phone');
            $table->index(['provider_profile_id', 'connection_status'], 'idx_whatsapp_senders_profile_status');
            $table->index(['quarantine_until', 'daily_sent_resets_at'], 'idx_whatsapp_senders_limits');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_senders', function (Blueprint $table) {
            $table->dropUnique('uniq_active_sender_phone');
            $table->dropIndex('idx_whatsapp_senders_profile_status');
            $table->dropIndex('idx_whatsapp_senders_limits');
        });

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            Schema::table('whatsapp_senders', function (Blueprint $table) {
                $table->dropColumn('active_phone_marker');
            });
        } else {
            DB::statement('ALTER TABLE whatsapp_senders DROP COLUMN active_phone_marker');
        }

        Schema::table('whatsapp_senders', function (Blueprint $table) {
            $table->dropColumn([
                'display_name',
                'auth_state_encrypted',
                'connection_status',
                'warmup_phase',
                'warmup_started_at',
                'daily_limit',
                'daily_sent_count',
                'daily_sent_resets_at',
                'quarantine_until',
                'last_message_at',
                'last_disconnect_reason',
                'consecutive_failures',
                'retired_at',
                'retired_reason',
            ]);

            $table->unique('phone_e164', 'uniq_whatsapp_sender_phone');
        });
    }
};
