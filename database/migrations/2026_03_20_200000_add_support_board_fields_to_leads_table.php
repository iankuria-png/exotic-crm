<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add support_chat to leads source enum (MySQL only; SQLite uses strings)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE leads MODIFY COLUMN source ENUM('registration','referral','outbound','import','support_chat') DEFAULT 'registration'");
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedInteger('sb_user_id')->nullable()->after('converted_client_id');
            $table->unsignedInteger('sb_conversation_id')->nullable()->after('sb_user_id');
            $table->string('sb_user_type', 30)->nullable()->after('sb_conversation_id');
            $table->dateTime('sb_last_activity_at')->nullable()->after('sb_user_type');
            $table->json('sb_metadata_snapshot')->nullable()->after('sb_last_activity_at');

            $table->index('sb_user_id');
            $table->index('sb_conversation_id');
            $table->index(['platform_id', 'sb_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['platform_id', 'sb_user_id']);
            $table->dropIndex(['sb_conversation_id']);
            $table->dropIndex(['sb_user_id']);
            $table->dropColumn([
                'sb_user_id',
                'sb_conversation_id',
                'sb_user_type',
                'sb_last_activity_at',
                'sb_metadata_snapshot',
            ]);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE leads MODIFY COLUMN source ENUM('registration','referral','outbound','import') DEFAULT 'registration'");
        }
    }
};
