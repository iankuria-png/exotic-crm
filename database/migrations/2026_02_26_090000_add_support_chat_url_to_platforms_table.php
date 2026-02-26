<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            if (!Schema::hasColumn('platforms', 'support_chat_url')) {
                $table->string('support_chat_url', 500)->nullable()->after('payment_link_providers');
            }
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            if (Schema::hasColumn('platforms', 'support_chat_url')) {
                $table->dropColumn('support_chat_url');
            }
        });
    }
};
