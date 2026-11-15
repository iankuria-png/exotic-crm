<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff lock: the profile is held in the Expired lifecycle state (published,
 * indexed, contacts hidden) by a person, with a reason. profile_locked_at is the
 * live flag; by/reason are kept after unlock as the last lock's record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->timestamp('profile_locked_at')->nullable();
            $table->unsignedBigInteger('profile_locked_by')->nullable();
            $table->string('profile_lock_reason_code', 40)->nullable();
            $table->string('profile_lock_reason_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn([
                'profile_locked_at',
                'profile_locked_by',
                'profile_lock_reason_code',
                'profile_lock_reason_note',
            ]);
        });
    }
};
