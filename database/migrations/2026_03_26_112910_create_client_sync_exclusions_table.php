<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_sync_exclusions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->unsignedBigInteger('wp_post_id');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['platform_id', 'wp_post_id']);
            $table->foreign('platform_id')->references('id')->on('platforms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_sync_exclusions');
    }
};
