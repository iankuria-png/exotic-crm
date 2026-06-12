<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_geocodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->string('canonical_key', 120);
            $table->string('display_city', 120);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('status', ['pending', 'resolved', 'unresolved', 'failed'])->default('pending');
            $table->decimal('importance', 6, 5)->nullable();
            $table->string('match_type', 40)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('source', 30)->default('nominatim');
            $table->timestamps();

            $table->index('platform_id');
            $table->unique(['platform_id', 'canonical_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_geocodes');
    }
};
