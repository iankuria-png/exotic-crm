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
            DB::statement(
                "ALTER TABLE client_notes MODIFY COLUMN note_type ENUM('call', 'email', 'sms', 'internal', 'system', 'support_chat') NOT NULL"
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE client_notes DROP CONSTRAINT IF EXISTS client_notes_note_type_check');
            DB::statement(
                "ALTER TABLE client_notes ADD CONSTRAINT client_notes_note_type_check CHECK (note_type IN ('call', 'email', 'sms', 'internal', 'system', 'support_chat'))"
            );

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqliteTable(true);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        DB::table('client_notes')
            ->where('note_type', 'support_chat')
            ->update(['note_type' => 'system']);

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE client_notes MODIFY COLUMN note_type ENUM('call', 'email', 'sms', 'internal', 'system') NOT NULL"
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE client_notes DROP CONSTRAINT IF EXISTS client_notes_note_type_check');
            DB::statement(
                "ALTER TABLE client_notes ADD CONSTRAINT client_notes_note_type_check CHECK (note_type IN ('call', 'email', 'sms', 'internal', 'system'))"
            );

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqliteTable(false);
        }
    }

    private function rebuildSqliteTable(bool $includeSupportChat): void
    {
        Schema::disableForeignKeyConstraints();

        $tableName = 'client_notes_tmp';
        $allowedTypes = ['call', 'email', 'sms', 'internal', 'system'];
        if ($includeSupportChat) {
            $allowedTypes[] = 'support_chat';
        }

        Schema::create($tableName, function (Blueprint $table) use ($allowedTypes) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('author_id');
            $table->enum('note_type', $allowedTypes);
            $table->text('content');
            $table->dateTime('follow_up_at')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index('client_id');
            $table->index('follow_up_at');

            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('author_id')->references('id')->on('users');
        });

        $noteTypeSelect = $includeSupportChat
            ? 'note_type'
            : "CASE WHEN note_type = 'support_chat' THEN 'system' ELSE note_type END";

        DB::statement(
            "INSERT INTO {$tableName} (id, client_id, author_id, note_type, content, follow_up_at, created_at)
             SELECT id, client_id, author_id, {$noteTypeSelect}, content, follow_up_at, created_at
             FROM client_notes"
        );

        Schema::drop('client_notes');
        Schema::rename($tableName, 'client_notes');

        Schema::enableForeignKeyConstraints();
    }
};
