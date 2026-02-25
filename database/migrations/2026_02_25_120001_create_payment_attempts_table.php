<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('attempt_type', 50);
            $table->string('provider', 50)->nullable();
            $table->string('status', 30);
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('request_meta')->nullable();
            $table->json('response_meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payment_id', 'created_at'], 'payment_attempts_payment_created_idx');
            $table->index(['attempt_type', 'status'], 'payment_attempts_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
