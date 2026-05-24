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
            DB::statement("ALTER TABLE client_credential_dispatches MODIFY COLUMN channel ENUM('email', 'sms', 'whatsapp', 'both', 'sms_whatsapp') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE client_credential_dispatches DROP CONSTRAINT IF EXISTS client_credential_dispatches_channel_check');
            DB::statement("ALTER TABLE client_credential_dispatches ADD CONSTRAINT client_credential_dispatches_channel_check CHECK (channel IN ('email', 'sms', 'whatsapp', 'both', 'sms_whatsapp'))");
        } elseif ($driver === 'sqlite') {
            $this->rebuildCredentialDispatchesTable(['email', 'sms', 'whatsapp', 'both', 'sms_whatsapp']);
        }
    }

    public function down(): void
    {
        DB::table('client_credential_dispatches')
            ->whereIn('channel', ['whatsapp', 'sms_whatsapp'])
            ->update(['channel' => 'sms']);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE client_credential_dispatches MODIFY COLUMN channel ENUM('email', 'sms', 'both') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE client_credential_dispatches DROP CONSTRAINT IF EXISTS client_credential_dispatches_channel_check');
            DB::statement("ALTER TABLE client_credential_dispatches ADD CONSTRAINT client_credential_dispatches_channel_check CHECK (channel IN ('email', 'sms', 'both'))");
        } elseif ($driver === 'sqlite') {
            $this->rebuildCredentialDispatchesTable(['email', 'sms', 'both']);
        }
    }

    private function rebuildCredentialDispatchesTable(array $channels): void
    {
        $tableName = 'client_credential_dispatches_channel_tmp';

        Schema::create($tableName, function (Blueprint $table) use ($channels) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('platform_id');
            $table->enum('method', ['setup_link', 'temporary_password']);
            $table->enum('channel', $channels);
            $table->enum('timing', ['send_now', 'manual_send_later']);
            $table->enum('status', ['deferred', 'sent', 'partial', 'failed'])->default('deferred');
            $table->string('recipient_email', 255)->nullable();
            $table->string('recipient_phone', 30)->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->json('provider_results')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
            $table->index(['client_id', 'created_at']);
            $table->index(['timing', 'status']);

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('platform_id')->references('id')->on('platforms');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement(
            "INSERT INTO {$tableName} (id, client_id, platform_id, method, channel, timing, status, recipient_email, recipient_phone, error_message, payload, provider_results, created_by, sent_at, created_at, updated_at)
             SELECT id, client_id, platform_id, method, channel, timing, status, recipient_email, recipient_phone, error_message, payload, provider_results, created_by, sent_at, created_at, updated_at
             FROM client_credential_dispatches"
        );

        Schema::drop('client_credential_dispatches');
        Schema::rename($tableName, 'client_credential_dispatches');
    }
};
