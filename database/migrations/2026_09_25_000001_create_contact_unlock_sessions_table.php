<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra browser sessions linked to a paid unlock. The purchasing browser keeps
     * the original token on visitor_contact_unlocks; a visitor who restores access
     * with their paying number gets an additional session here, so the original
     * device keeps working.
     */
    public function up(): void
    {
        Schema::create('contact_unlock_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visitor_contact_unlock_id')->constrained('visitor_contact_unlocks')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->string('session_token_hash', 64);
            $table->string('public_token_hash', 64)->unique();
            $table->string('source', 40);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['visitor_contact_unlock_id', 'session_token_hash'], 'contact_unlock_sessions_unlock_session_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_unlock_sessions');
    }
};
