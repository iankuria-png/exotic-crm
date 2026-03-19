<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('platform_id')->default(0);
            $table->unsignedInteger('active_baseline_count')->default(0);
            $table->unsignedInteger('churned_count')->default(0);
            $table->decimal('logo_churn_30d', 6, 2)->default(0);
            $table->json('meta')->nullable();
            $table->dateTime('computed_at');
            $table->timestamps();

            $table->unique(['snapshot_date', 'platform_id']);
            $table->index(['platform_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_metric_snapshots');
    }
};
