<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many times an item has been handed to the queue.
     *
     * The scheduled dispatcher salvages items stuck in 'scheduled' back to
     * 'pending' every 15 minutes and re-dispatches them with a fresh attempt
     * counter, so SendPushNotificationJob's $tries = 3 bounds one dispatch but
     * nothing bounds the cycle. Counting dispatches on the item gives the loop
     * an end, and leaves a number an operator can see.
     */
    public function up(): void
    {
        Schema::table('push_campaign_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('dispatch_attempts')->default(0)->after('replacement_round');
        });
    }

    public function down(): void
    {
        Schema::table('push_campaign_items', function (Blueprint $table) {
            $table->dropColumn('dispatch_attempts');
        });
    }
};
