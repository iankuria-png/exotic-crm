<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('trigger_days');
            $table->enum('channel', ['email', 'sms', 'both']);
            $table->unsignedBigInteger('template_id');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('template_id')->references('id')->on('templates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_campaigns');
    }
};
