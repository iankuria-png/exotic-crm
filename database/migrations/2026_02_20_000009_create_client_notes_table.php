<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('author_id');
            $table->enum('note_type', ['call', 'email', 'sms', 'internal', 'system']);
            $table->text('content');
            $table->dateTime('follow_up_at')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index('client_id');
            $table->index('follow_up_at');

            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('author_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');
    }
};
