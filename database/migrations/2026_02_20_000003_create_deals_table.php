<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->enum('plan_type', ['basic', 'premium', 'vip']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('KES');
            $table->enum('duration', ['weekly', 'biweekly', 'monthly', 'manual']);
            $table->enum('status', ['pending', 'awaiting_payment', 'paid', 'active', 'expired', 'cancelled'])->default('pending');
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('client_id');
            $table->index('platform_id');
            $table->index('expires_at');

            $table->foreign('platform_id')->references('id')->on('platforms');
            $table->foreign('client_id')->references('id')->on('clients');
            $table->foreign('lead_id')->references('id')->on('leads');
            $table->foreign('payment_id')->references('id')->on('payments');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('assigned_to')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
