<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->enum('entity_type', ['client', 'deal', 'lead', 'payment']);
            $table->unsignedBigInteger('entity_id');
            $table->string('event_type', 50);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('content')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index('event_type');
            $table->index('created_at');

            $table->foreign('platform_id')->references('id')->on('platforms');
            $table->foreign('actor_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_events');
    }
};
