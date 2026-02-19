<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->integer('wp_user_id')->nullable();
            $table->integer('wp_post_id')->nullable();
            $table->string('name', 255)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->enum('source', ['registration', 'referral', 'outbound', 'import'])->default('registration');
            $table->enum('status', ['new', 'contacted', 'qualified', 'converted', 'lost'])->default('new');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->dateTime('first_contact_at')->nullable();
            $table->dateTime('last_contact_at')->nullable();
            $table->integer('response_time_seconds')->nullable();
            $table->unsignedBigInteger('converted_client_id')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('assigned_to');
            $table->index('platform_id');
            $table->index('phone_normalized');

            $table->foreign('platform_id')->references('id')->on('platforms');
            $table->foreign('assigned_to')->references('id')->on('users');
            $table->foreign('converted_client_id')->references('id')->on('clients');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
