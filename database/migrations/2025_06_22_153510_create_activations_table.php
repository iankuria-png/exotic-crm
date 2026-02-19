<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up()
    {
        Schema::create('activations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('platform_id');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('deactivated_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->foreign('platform_id')->references('id')->on('platforms');
            $table->foreignId('product_id')->nullable();
            $table->boolean('is_free_trial')->default(false);
            $table->timestamps();
        });

    }
    
    public function down(): void
    {
        Schema::dropIfExists('activations');
    }
};
