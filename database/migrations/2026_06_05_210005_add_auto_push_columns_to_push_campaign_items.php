<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_campaign_items', function (Blueprint $table) {
            $table->json('provider_meta')->nullable()->after('delivery_stats');
            $table->unsignedBigInteger('replaces_item_id')->nullable()->after('provider_meta');
            $table->unsignedInteger('replacement_round')->default(0)->after('replaces_item_id');
            $table->index('replaces_item_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('push_campaign_items', function (Blueprint $table) {
                $table->foreign('replaces_item_id')
                    ->references('id')
                    ->on('push_campaign_items')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('push_campaign_items', function (Blueprint $table) {
                $table->dropForeign(['replaces_item_id']);
            });
        }

        Schema::table('push_campaign_items', function (Blueprint $table) {
            $table->dropIndex(['replaces_item_id']);
            $table->dropColumn(['provider_meta', 'replaces_item_id', 'replacement_round']);
        });
    }
};
