<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_document_blobs', function (Blueprint $table) {
            $table->unsignedBigInteger('document_id')->primary();
            $table->longText('body');
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('kyc_documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_document_blobs');
    }
};
