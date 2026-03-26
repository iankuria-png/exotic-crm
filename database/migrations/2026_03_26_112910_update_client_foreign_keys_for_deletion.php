<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropForeign(['deal_id']);

            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['converted_client_id']);
            $table->foreign('converted_client_id')->references('id')->on('clients')->nullOnDelete();
        });

        Schema::table('client_notes', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of']);
            $table->foreign('duplicate_of')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropForeign(['deal_id']);

            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('deal_id')->references('id')->on('deals');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['converted_client_id']);
            $table->foreign('converted_client_id')->references('id')->on('clients');
        });

        Schema::table('client_notes', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->foreign('client_id')->references('id')->on('clients');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of']);
            $table->foreign('duplicate_of')->references('id')->on('clients');
        });
    }
};
