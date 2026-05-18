<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_subject_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('kyc_subjects')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->integer('wp_post_id')->nullable();
            $table->integer('wp_user_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status', 50)->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->unique(['subject_id', 'platform_id', 'wp_post_id']);
            $table->index(['platform_id', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_subject_sites');
    }
};
