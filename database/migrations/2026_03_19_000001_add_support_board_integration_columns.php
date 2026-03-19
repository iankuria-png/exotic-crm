<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addPlatformColumns();
        $this->addClientColumns();
        $this->addUserColumns();
    }

    public function down(): void
    {
        $this->dropUserColumns();
        $this->dropClientColumns();
        $this->dropPlatformColumns();
    }

    private function addPlatformColumns(): void
    {
        $needsApiUrl = !Schema::hasColumn('platforms', 'support_board_api_url');
        $needsToken = !Schema::hasColumn('platforms', 'support_board_token');
        $needsSenderId = !Schema::hasColumn('platforms', 'support_board_sender_id');

        if (!$needsApiUrl && !$needsToken && !$needsSenderId) {
            return;
        }

        Schema::table('platforms', function (Blueprint $table) use ($needsApiUrl, $needsToken, $needsSenderId) {
            if ($needsApiUrl) {
                $table->string('support_board_api_url', 500)
                    ->nullable()
                    ->default('https://cloud.board.support/script/include/api.php')
                    ->after('support_chat_url');
            }

            if ($needsToken) {
                $table->text('support_board_token')
                    ->nullable()
                    ->after('support_board_api_url');
            }

            if ($needsSenderId) {
                $table->unsignedInteger('support_board_sender_id')
                    ->nullable()
                    ->after('support_board_token');
            }
        });
    }

    private function addClientColumns(): void
    {
        $needsSbUserId = !Schema::hasColumn('clients', 'sb_user_id');
        $needsMatchedBy = !Schema::hasColumn('clients', 'sb_matched_by');

        if (!$needsSbUserId && !$needsMatchedBy) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) use ($needsSbUserId, $needsMatchedBy) {
            if ($needsSbUserId) {
                $table->unsignedInteger('sb_user_id')->nullable()->after('email');
                $table->index('sb_user_id');
            }

            if ($needsMatchedBy) {
                $table->string('sb_matched_by', 20)->nullable()->after('sb_user_id');
            }
        });
    }

    private function addUserColumns(): void
    {
        if (Schema::hasColumn('users', 'sb_agent_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('sb_agent_id')->nullable()->after('status');
        });
    }

    private function dropPlatformColumns(): void
    {
        $hasApiUrl = Schema::hasColumn('platforms', 'support_board_api_url');
        $hasToken = Schema::hasColumn('platforms', 'support_board_token');
        $hasSenderId = Schema::hasColumn('platforms', 'support_board_sender_id');

        if (!$hasApiUrl && !$hasToken && !$hasSenderId) {
            return;
        }

        Schema::table('platforms', function (Blueprint $table) use ($hasApiUrl, $hasToken, $hasSenderId) {
            $dropColumns = [];

            if ($hasApiUrl) {
                $dropColumns[] = 'support_board_api_url';
            }

            if ($hasToken) {
                $dropColumns[] = 'support_board_token';
            }

            if ($hasSenderId) {
                $dropColumns[] = 'support_board_sender_id';
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }

    private function dropClientColumns(): void
    {
        $hasSbUserId = Schema::hasColumn('clients', 'sb_user_id');
        $hasMatchedBy = Schema::hasColumn('clients', 'sb_matched_by');

        if (!$hasSbUserId && !$hasMatchedBy) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) use ($hasSbUserId, $hasMatchedBy) {
            $dropColumns = [];

            if ($hasSbUserId) {
                $table->dropIndex(['sb_user_id']);
                $dropColumns[] = 'sb_user_id';
            }

            if ($hasMatchedBy) {
                $dropColumns[] = 'sb_matched_by';
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }

    private function dropUserColumns(): void
    {
        if (!Schema::hasColumn('users', 'sb_agent_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sb_agent_id');
        });
    }
};
