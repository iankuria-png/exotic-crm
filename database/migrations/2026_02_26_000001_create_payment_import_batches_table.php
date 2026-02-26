<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name');
            $table->string('file_mime', 120)->nullable();
            $table->string('status', 32)->default('previewed');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('committed_rows')->default(0);
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status'], 'payment_import_batches_platform_status_idx');
            $table->index(['uploaded_by', 'created_at'], 'payment_import_batches_uploaded_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_import_batches');
    }
};
