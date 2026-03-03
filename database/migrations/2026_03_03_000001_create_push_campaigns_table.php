<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->unsignedBigInteger('platform_id');
            $table->string('provider', 50)->nullable();
            $table->enum('status', ['processing', 'draft', 'scheduled', 'running', 'completed', 'partial', 'failed'])
                ->default('processing');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('upload_batch_id', 36)->nullable();
            $table->string('source_filename', 255)->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
            $table->index('created_by');
            $table->index('scheduled_at');
            $table->index('upload_batch_id');

            $table->foreign('platform_id')->references('id')->on('platforms');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_campaigns');
    }
};
