<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->enum('engine', ['meta_cloud_api', 'baileys']);
            $table->string('external_event_id', 120);
            $table->timestamp('received_at');
            $table->string('payload_hash', 64);
            $table->timestamps();

            $table->unique(['engine', 'external_event_id'], 'uniq_messaging_webhook_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_webhook_events');
    }
};
