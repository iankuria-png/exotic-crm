<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::drop('audit_log');

            Schema::create('audit_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_id')->nullable();
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 100);
                $table->string('entity_type', 50);
                $table->unsignedBigInteger('entity_id');
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->text('reason')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->dateTime('created_at')->nullable();

                $table->index(['entity_type', 'entity_id']);
                $table->index('actor_id');
                $table->index('action');
                $table->index('created_at');

                $table->foreign('platform_id')->references('id')->on('platforms')->nullOnDelete();
                $table->foreign('actor_id')->references('id')->on('users');
            });

            return;
        }

        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropForeign(['platform_id']);
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_id')->nullable()->change();
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreign('platform_id')->references('id')->on('platforms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('audit_log')->whereNull('platform_id')->exists()) {
            throw new RuntimeException('Cannot restore audit_log.platform_id to non-null while system FAQ audit rows exist.');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::drop('audit_log');

            Schema::create('audit_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 100);
                $table->string('entity_type', 50);
                $table->unsignedBigInteger('entity_id');
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->text('reason')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->dateTime('created_at')->nullable();

                $table->index(['entity_type', 'entity_id']);
                $table->index('actor_id');
                $table->index('action');
                $table->index('created_at');

                $table->foreign('platform_id')->references('id')->on('platforms');
                $table->foreign('actor_id')->references('id')->on('users');
            });

            return;
        }

        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropForeign(['platform_id']);
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_id')->nullable(false)->change();
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreign('platform_id')->references('id')->on('platforms');
        });
    }
};
