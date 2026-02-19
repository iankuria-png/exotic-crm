<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('deal_id')->nullable()->after('escort_post_id');
            $table->unsignedBigInteger('client_id')->nullable()->after('deal_id');
            $table->enum('match_confidence', ['auto_high', 'auto_low', 'manual', 'unmatched'])->nullable()->after('client_id');
            $table->unsignedBigInteger('confirmed_by')->nullable()->after('match_confidence');
            $table->dateTime('confirmed_at')->nullable()->after('confirmed_by');

            $table->foreign('deal_id')->references('id')->on('deals');
            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('confirmed_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['deal_id']);
            $table->dropForeign(['client_id']);
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn(['deal_id', 'client_id', 'match_confidence', 'confirmed_by', 'confirmed_at']);
        });
    }
};
