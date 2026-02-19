<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->dateTime('run_at');
            $table->integer('total_targeted')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->unsignedBigInteger('run_by');
            $table->enum('status', ['completed', 'partial', 'failed']);
            $table->dateTime('created_at')->nullable();

            $table->foreign('campaign_id')->references('id')->on('renewal_campaigns');
            $table->foreign('run_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_runs');
    }
};
