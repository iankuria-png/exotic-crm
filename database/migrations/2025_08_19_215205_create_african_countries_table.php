<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('african_countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('currency_name')->nullable();
            $table->string('currency_code', 3);
            $table->string('currency_symbol', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('currency_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('african_countries');
    }
};