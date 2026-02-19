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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('platform_id')->constrained();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->nullable();
            $table->string('transaction_uuid')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->string('status')->default('pending');
            $table->string('duration')->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            
            

            
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
