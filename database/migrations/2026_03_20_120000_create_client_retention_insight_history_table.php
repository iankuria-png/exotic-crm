<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_retention_insight_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedTinyInteger('score');
            $table->string('band', 20);
            $table->date('recorded_date');
            $table->timestamp('created_at')->nullable();

            $table->unique(['client_id', 'recorded_date']);
            $table->index(['client_id', 'recorded_date']);

            $table->foreign('client_id')
                ->references('id')
                ->on('clients')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_retention_insight_history');
    }
};
