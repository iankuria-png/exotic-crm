<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Briefing SMS sends reuse NotificationService::sendSms() but must persist their
 * own sms_logs rows. The original sms_logs.payment_id is NOT NULL with a FK, so:
 *  - make payment_id nullable, and
 *  - add a nullable briefing_recipient_id FK for linkage.
 *
 * SQLite (tests) cannot easily alter a column to nullable, so it is rebuilt;
 * MySQL (prod, has real data) is altered in place without dropping the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $rows = DB::table('sms_logs')->get();

            Schema::drop('sms_logs');
            Schema::create('sms_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payment_id')->nullable();
                $table->unsignedBigInteger('briefing_recipient_id')->nullable();
                $table->string('phone', 32);
                $table->text('message');
                $table->string('status', 20);
                $table->timestamp('sent_at')->nullable();
                $table->string('response', 255)->nullable();
                $table->string('result_code', 64)->nullable();
                $table->timestamps();

                $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
                $table->index('briefing_recipient_id');
            });

            foreach ($rows as $row) {
                DB::table('sms_logs')->insert((array) $row);
            }

            return;
        }

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->change();
            if (!Schema::hasColumn('sms_logs', 'briefing_recipient_id')) {
                $table->unsignedBigInteger('briefing_recipient_id')->nullable()->after('payment_id');
            }
            if (!Schema::hasColumn('sms_logs', 'result_code')) {
                $table->string('result_code', 64)->nullable();
            }
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            $table->index('briefing_recipient_id');
        });
    }

    public function down(): void
    {
        // payment_id cannot safely return to NOT NULL once briefing rows exist; leave nullable.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('sms_logs', function (Blueprint $table) {
            if (Schema::hasColumn('sms_logs', 'briefing_recipient_id')) {
                $table->dropIndex(['briefing_recipient_id']);
                $table->dropColumn('briefing_recipient_id');
            }
        });
    }
};
