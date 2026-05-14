<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('university_daily_quotes', function (Blueprint $table) {
            $table->id();
            $table->date('quote_date')->unique();
            $table->text('quote');
            $table->string('author')->nullable();
            $table->string('source_label')->nullable();
            $table->string('category', 80)->default('training');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['quote_date', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('university_daily_quotes');
    }
};
