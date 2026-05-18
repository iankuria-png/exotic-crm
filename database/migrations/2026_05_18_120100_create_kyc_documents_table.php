<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('kyc_subjects')->cascadeOnDelete();
            $table->enum('kind', ['id_front', 'id_back', 'selfie']);
            $table->enum('storage_driver', ['db', 's3']);
            $table->string('mime', 150);
            $table->unsignedBigInteger('byte_size');
            $table->string('sha256', 64);
            $table->string('original_filename')->nullable();
            $table->string('s3_disk', 100)->nullable();
            $table->string('s3_key', 500)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['subject_id', 'kind']);
            $table->index(['storage_driver']);
            $table->index(['sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};
