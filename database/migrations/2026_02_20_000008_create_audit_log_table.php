<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
