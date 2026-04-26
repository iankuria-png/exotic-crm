<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_fx_rates', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->default('manual');
            $table->string('source_currency', 8);
            $table->string('target_currency', 8)->default('USD');
            $table->date('rate_date');
            $table->decimal('rate', 20, 10);
            $table->timestamp('fetched_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'source_currency', 'target_currency', 'rate_date'],
                'reporting_fx_provider_pair_date_uq'
            );
            $table->index(['source_currency', 'target_currency', 'rate_date'], 'reporting_fx_pair_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_fx_rates');
    }
};
