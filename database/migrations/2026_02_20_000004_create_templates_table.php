<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id')->nullable();
            $table->string('title', 255);
            $table->enum('category', ['payment', 'renewal', 'follow_up', 'welcome', 'win_back']);
            $table->enum('channel', ['email', 'sms']);
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->json('variables')->nullable();
            $table->enum('status', ['active', 'draft'])->default('draft');
            $table->timestamps();

            $table->foreign('platform_id')->references('id')->on('platforms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
