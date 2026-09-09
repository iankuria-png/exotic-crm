<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Singleton row holding the removal's tuning, mirroring kyc_settings.
     *
     * These were hard-coded constants chosen by measuring a handful of photos.
     * The right values depend on how heavily a market draws its logo and how
     * hard its images are recompressed, both of which vary and change, so they
     * belong somewhere an operator can move them against the evidence the
     * attempts table now collects.
     */
    public function up(): void
    {
        Schema::create('watermark_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(true);
            $table->json('tuning')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_settings');
    }
};
