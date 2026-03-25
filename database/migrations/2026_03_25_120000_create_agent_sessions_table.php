<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_token', 36);
            $table->dateTime('started_at');
            $table->dateTime('last_heartbeat_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->index(['user_id', 'ended_at', 'last_heartbeat_at']);
            $table->index(['session_token', 'user_id', 'ended_at']);
            $table->index('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sessions');
    }
};
