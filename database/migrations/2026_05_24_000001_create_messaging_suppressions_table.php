<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_suppressions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id')->nullable();
            $table->string('phone_e164', 32);
            $table->string('email')->nullable();
            $table->enum('channel', ['whatsapp', 'sms', 'email', 'all']);
            $table->string('reason', 64);
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->timestamp('opted_out_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('platform_id')->references('id')->on('platforms')->nullOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['phone_e164', 'channel']);
            $table->index(['platform_id', 'revoked_at']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE messaging_suppressions
                 ADD COLUMN platform_scope BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(platform_id, 0)) STORED,
                 ADD COLUMN active_marker TINYINT GENERATED ALWAYS AS (CASE WHEN revoked_at IS NULL THEN 1 ELSE NULL END) STORED'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'ALTER TABLE messaging_suppressions
                 ADD COLUMN platform_scope INTEGER GENERATED ALWAYS AS (COALESCE(platform_id, 0)) VIRTUAL'
            );
            DB::statement(
                'ALTER TABLE messaging_suppressions
                 ADD COLUMN active_marker INTEGER GENERATED ALWAYS AS (CASE WHEN revoked_at IS NULL THEN 1 ELSE NULL END) VIRTUAL'
            );
        } else {
            DB::statement(
                'ALTER TABLE messaging_suppressions
                 ADD COLUMN platform_scope BIGINT GENERATED ALWAYS AS (COALESCE(platform_id, 0)) STORED'
            );
            DB::statement(
                'ALTER TABLE messaging_suppressions
                 ADD COLUMN active_marker SMALLINT GENERATED ALWAYS AS (CASE WHEN revoked_at IS NULL THEN 1 ELSE NULL END) STORED'
            );
        }

        Schema::table('messaging_suppressions', function (Blueprint $table) {
            $table->unique(['platform_scope', 'phone_e164', 'channel', 'active_marker'], 'uniq_active_suppression');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_suppressions');
    }
};
