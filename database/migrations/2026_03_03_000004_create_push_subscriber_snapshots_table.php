<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriber_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->string('provider', 50);
            $table->unsignedInteger('total_subscribers')->default(0);
            $table->unsignedInteger('active_subscribers')->default(0);
            $table->date('snapshot_date');
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'provider', 'snapshot_date'], 'push_subscriber_snapshots_platform_provider_date_unique');
            $table->index(['platform_id', 'snapshot_date']);

            $table->foreign('platform_id')->references('id')->on('platforms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriber_snapshots');
    }
};
