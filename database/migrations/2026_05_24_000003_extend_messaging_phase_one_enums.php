<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE templates MODIFY COLUMN channel ENUM('email', 'sms', 'whatsapp') NOT NULL");
            DB::statement("ALTER TABLE templates MODIFY COLUMN category ENUM('payment', 'renewal', 'follow_up', 'welcome', 'win_back', 'credential_setup_link', 'credential_temp_password', 'activation_offer', 'new_signup', 'high_value_offer', 'inactivity_nudge') NOT NULL");
            DB::statement("ALTER TABLE renewal_campaigns MODIFY COLUMN channel ENUM('email', 'sms', 'both', 'whatsapp') NOT NULL");
            DB::statement("ALTER TABLE client_notes MODIFY COLUMN note_type ENUM('call', 'email', 'sms', 'internal', 'system', 'support_chat', 'whatsapp') NOT NULL");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE templates DROP CONSTRAINT IF EXISTS templates_channel_check');
            DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_channel_check CHECK (channel IN ('email', 'sms', 'whatsapp'))");
            DB::statement('ALTER TABLE templates DROP CONSTRAINT IF EXISTS templates_category_check');
            DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_category_check CHECK (category IN ('payment', 'renewal', 'follow_up', 'welcome', 'win_back', 'credential_setup_link', 'credential_temp_password', 'activation_offer', 'new_signup', 'high_value_offer', 'inactivity_nudge'))");
            DB::statement('ALTER TABLE renewal_campaigns DROP CONSTRAINT IF EXISTS renewal_campaigns_channel_check');
            DB::statement("ALTER TABLE renewal_campaigns ADD CONSTRAINT renewal_campaigns_channel_check CHECK (channel IN ('email', 'sms', 'both', 'whatsapp'))");
            DB::statement('ALTER TABLE client_notes DROP CONSTRAINT IF EXISTS client_notes_note_type_check');
            DB::statement("ALTER TABLE client_notes ADD CONSTRAINT client_notes_note_type_check CHECK (note_type IN ('call', 'email', 'sms', 'internal', 'system', 'support_chat', 'whatsapp'))");

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildTemplatesTable(
                ['payment', 'renewal', 'follow_up', 'welcome', 'win_back', 'credential_setup_link', 'credential_temp_password', 'activation_offer', 'new_signup', 'high_value_offer', 'inactivity_nudge'],
                ['email', 'sms', 'whatsapp']
            );
            $this->rebuildRenewalCampaignsTable(['email', 'sms', 'both', 'whatsapp']);
            $this->rebuildClientNotesTable(['call', 'email', 'sms', 'internal', 'system', 'support_chat', 'whatsapp']);
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
            DB::statement("ALTER TABLE templates MODIFY COLUMN category ENUM('payment', 'renewal', 'follow_up', 'welcome', 'win_back', 'credential_setup_link', 'credential_temp_password') NOT NULL");
            DB::statement("ALTER TABLE renewal_campaigns MODIFY COLUMN channel ENUM('email', 'sms', 'both') NOT NULL");
            DB::statement("ALTER TABLE client_notes MODIFY COLUMN note_type ENUM('call', 'email', 'sms', 'internal', 'system', 'support_chat') NOT NULL");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE templates DROP CONSTRAINT IF EXISTS templates_channel_check');
            DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_channel_check CHECK (channel IN ('email', 'sms'))");
            DB::statement('ALTER TABLE templates DROP CONSTRAINT IF EXISTS templates_category_check');
            DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_category_check CHECK (category IN ('payment', 'renewal', 'follow_up', 'welcome', 'win_back', 'credential_setup_link', 'credential_temp_password'))");
            DB::statement('ALTER TABLE renewal_campaigns DROP CONSTRAINT IF EXISTS renewal_campaigns_channel_check');
            DB::statement("ALTER TABLE renewal_campaigns ADD CONSTRAINT renewal_campaigns_channel_check CHECK (channel IN ('email', 'sms', 'both'))");
            DB::statement('ALTER TABLE client_notes DROP CONSTRAINT IF EXISTS client_notes_note_type_check');
            DB::statement("ALTER TABLE client_notes ADD CONSTRAINT client_notes_note_type_check CHECK (note_type IN ('call', 'email', 'sms', 'internal', 'system', 'support_chat'))");

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildTemplatesTable(
                ['payment', 'renewal', 'follow_up', 'welcome', 'win_back', 'credential_setup_link', 'credential_temp_password'],
                ['email', 'sms']
            );
            $this->rebuildRenewalCampaignsTable(['email', 'sms', 'both']);
            $this->rebuildClientNotesTable(['call', 'email', 'sms', 'internal', 'system', 'support_chat']);
        }
    }

    private function rebuildTemplatesTable(array $categories, array $channels): void
    {
        Schema::disableForeignKeyConstraints();

        $tableName = 'templates_messaging_enum_tmp';

        Schema::create($tableName, function (Blueprint $table) use ($categories, $channels) {
            $table->id();
            $table->unsignedBigInteger('platform_id')->nullable();
            $table->string('title', 255);
            $table->enum('category', $categories);
            $table->enum('channel', $channels);
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->json('variables')->nullable();
            $table->enum('status', ['active', 'draft'])->default('draft');
            $table->timestamps();

            $table->foreign('platform_id')->references('id')->on('platforms');
        });

        DB::statement(
            "INSERT INTO {$tableName} (id, platform_id, title, category, channel, subject, body, variables, status, created_at, updated_at)
             SELECT id, platform_id, title, category, channel, subject, body, variables, status, created_at, updated_at
             FROM templates"
        );

        Schema::drop('templates');
        Schema::rename($tableName, 'templates');

        Schema::enableForeignKeyConstraints();
    }

    private function rebuildRenewalCampaignsTable(array $channels): void
    {
        Schema::disableForeignKeyConstraints();

        $tableName = 'renewal_campaigns_messaging_enum_tmp';

        Schema::create($tableName, function (Blueprint $table) use ($channels) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('trigger_days');
            $table->enum('channel', $channels);
            $table->unsignedBigInteger('template_id');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('template_id')->references('id')->on('templates');
        });

        DB::statement(
            "INSERT INTO {$tableName} (id, product_id, trigger_days, channel, template_id, enabled, created_at, updated_at)
             SELECT id, product_id, trigger_days, channel, template_id, enabled, created_at, updated_at
             FROM renewal_campaigns"
        );

        Schema::drop('renewal_campaigns');
        Schema::rename($tableName, 'renewal_campaigns');

        Schema::enableForeignKeyConstraints();
    }

    private function rebuildClientNotesTable(array $noteTypes): void
    {
        Schema::disableForeignKeyConstraints();

        $tableName = 'client_notes_messaging_enum_tmp';

        Schema::create($tableName, function (Blueprint $table) use ($noteTypes) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('author_id');
            $table->enum('note_type', $noteTypes);
            $table->text('content');
            $table->dateTime('follow_up_at')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index('client_id');
            $table->index('follow_up_at');

            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('author_id')->references('id')->on('users');
        });

        DB::statement(
            "INSERT INTO {$tableName} (id, client_id, author_id, note_type, content, follow_up_at, created_at)
             SELECT id, client_id, author_id, note_type, content, follow_up_at, created_at
             FROM client_notes"
        );

        Schema::drop('client_notes');
        Schema::rename($tableName, 'client_notes');

        Schema::enableForeignKeyConstraints();
    }
};
