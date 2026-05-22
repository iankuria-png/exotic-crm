<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('duplicate_of');
            $table->string('close_reason_code', 64)->nullable()->after('closed_at');
            $table->text('close_reason_note')->nullable()->after('close_reason_code');
            $table->unsignedBigInteger('closed_by')->nullable()->after('close_reason_note');
            $table->timestamp('purge_after')->nullable()->after('closed_by');
            $table->timestamp('first_contact_at')->nullable()->after('purge_after');
            $table->timestamp('last_contact_at')->nullable()->after('first_contact_at');

            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->index('closed_at');
            $table->index('purge_after');
            $table->index('first_contact_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropIndex(['closed_at']);
            $table->dropIndex(['purge_after']);
            $table->dropIndex(['first_contact_at']);
            $table->dropColumn([
                'closed_at',
                'close_reason_code',
                'close_reason_note',
                'closed_by',
                'purge_after',
                'first_contact_at',
                'last_contact_at',
            ]);
        });
    }
};
