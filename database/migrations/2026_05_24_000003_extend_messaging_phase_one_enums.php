<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE templates MODIFY COLUMN channel ENUM('email', 'sms', 'whatsapp') NOT NULL");
            DB::statement("ALTER TABLE templates MODIFY COLUMN category ENUM('payment', 'renewal', 'follow_up', 'welcome', 'win_back', 'activation_offer', 'new_signup', 'high_value_offer', 'inactivity_nudge') NOT NULL");
            DB::statement("ALTER TABLE renewal_campaigns MODIFY COLUMN channel ENUM('email', 'sms', 'both', 'whatsapp') NOT NULL");
            DB::statement("ALTER TABLE client_notes MODIFY COLUMN note_type ENUM('call', 'email', 'sms', 'internal', 'system', 'support_chat', 'whatsapp') NOT NULL");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE templates DROP CONSTRAINT IF EXISTS templates_channel_check');
            DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_channel_check CHECK (channel IN ('email', 'sms', 'whatsapp'))");
            DB::statement('ALTER TABLE templates DROP CONSTRAINT IF EXISTS templates_category_check');
            DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_category_check CHECK (category IN ('payment', 'renewal', 'follow_up', 'welcome', 'win_back', 'activation_offer', 'new_signup', 'high_value_offer', 'inactivity_nudge'))");
            DB::statement('ALTER TABLE renewal_campaigns DROP CONSTRAINT IF EXISTS renewal_campaigns_channel_check');
            DB::statement("ALTER TABLE renewal_campaigns ADD CONSTRAINT renewal_campaigns_channel_check CHECK (channel IN ('email', 'sms', 'both', 'whatsapp'))");
            DB::statement('ALTER TABLE client_notes DROP CONSTRAINT IF EXISTS client_notes_note_type_check');
            DB::statement("ALTER TABLE client_notes ADD CONSTRAINT client_notes_note_type_check CHECK (note_type IN ('call', 'email', 'sms', 'internal', 'system', 'support_chat', 'whatsapp'))");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        DB::table('templates')
            ->where('channel', 'whatsapp')
            ->update(['channel' => 'sms']);
        DB::table('templates')
            ->whereIn('category', ['activation_offer', 'new_signup', 'high_value_offer', 'inactivity_nudge'])
            ->update(['category' => 'follow_up']);
        DB::table('renewal_campaigns')
            ->where('channel', 'whatsapp')
            ->update(['channel' => 'sms']);
        DB::table('client_notes')
            ->where('note_type', 'whatsapp')
            ->update(['note_type' => 'sms']);

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE templates MODIFY COLUMN channel ENUM('email', 'sms') NOT NULL");
            DB::statement("ALTER TABLE templates MODIFY COLUMN category ENUM('payment', 'renewal', 'follow_up', 'welcome', 'win_back') NOT NULL");
            DB::statement("ALTER TABLE renewal_campaigns MODIFY COLUMN channel ENUM('email', 'sms', 'both') NOT NULL");
            DB::statement("ALTER TABLE client_notes MODIFY COLUMN note_type ENUM('call', 'email', 'sms', 'internal', 'system', 'support_chat') NOT NULL");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE templates DROP CONSTRAINT IF EXISTS templates_channel_check');
            DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_channel_check CHECK (channel IN ('email', 'sms'))");
            DB::statement('ALTER TABLE templates DROP CONSTRAINT IF EXISTS templates_category_check');
            DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_category_check CHECK (category IN ('payment', 'renewal', 'follow_up', 'welcome', 'win_back'))");
            DB::statement('ALTER TABLE renewal_campaigns DROP CONSTRAINT IF EXISTS renewal_campaigns_channel_check');
            DB::statement("ALTER TABLE renewal_campaigns ADD CONSTRAINT renewal_campaigns_channel_check CHECK (channel IN ('email', 'sms', 'both'))");
            DB::statement('ALTER TABLE client_notes DROP CONSTRAINT IF EXISTS client_notes_note_type_check');
            DB::statement("ALTER TABLE client_notes ADD CONSTRAINT client_notes_note_type_check CHECK (note_type IN ('call', 'email', 'sms', 'internal', 'system', 'support_chat'))");
        }
    }
};
