<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One flat saved bucket per customer. Named lists are deliberately not modelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_saved_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('object_type', 40)->default('profile');
            $table->unsignedBigInteger('object_ref');
            $table->string('source', 40)->nullable();
            $table->timestamp('saved_at');
            $table->timestamps();

            $table->unique(
                ['customer_account_id', 'object_type', 'object_ref'],
                'customer_saved_objects_unique'
            );
            $table->index(['platform_id', 'object_type', 'object_ref'], 'customer_saved_platform_object_idx');
            $table->index(['customer_account_id', 'saved_at'], 'customer_saved_account_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_saved_objects');
    }
};
