<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_unlock_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->foreignId('visitor_contact_unlock_id')->nullable()->constrained('visitor_contact_unlocks')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('scope', 40)->nullable();
            $table->string('session_hash', 64);
            $table->string('pageview_id', 64)->nullable();
            $table->string('visitor_phone_hash', 64)->nullable();
            $table->string('event_id_hash', 64)->unique();
            $table->string('referrer_host', 120)->nullable();
            $table->string('traffic_source', 80)->nullable();
            $table->unsignedTinyInteger('local_hour')->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'event_type', 'occurred_at'], 'contact_unlock_events_platform_type_time_idx');
            $table->index(['client_id', 'event_type', 'occurred_at'], 'contact_unlock_events_client_type_time_idx');
            $table->index(['traffic_source', 'occurred_at'], 'contact_unlock_events_source_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_unlock_events');
    }
};
