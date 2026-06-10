<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expiry_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 10)->default('live'); // dry | live
            $table->foreignId('platform_id')->nullable()->constrained('platforms')->nullOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('candidates')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('breakdown')->nullable(); // per-market counts + sample post ids
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['mode', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expiry_reconciliation_runs');
    }
};
