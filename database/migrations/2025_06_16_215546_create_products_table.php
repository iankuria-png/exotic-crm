<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();           // e.g. VIP, Premium
            $table->decimal('monthly_price', 10, 2);    // 1 month price
            $table->decimal('biweekly_price', 10, 2);   // 2 weeks price
            $table->string('currency')->default('KES'); // e.g. KES, USD, NGN
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};