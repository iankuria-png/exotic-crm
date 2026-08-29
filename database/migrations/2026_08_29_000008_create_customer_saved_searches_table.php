<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved searches store the discovery route and approved refinements, not an
 * arbitrary query blob. That keeps the account memory aligned with the
 * WordPress discovery system as slugs and labels evolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('route_family', 80);
            $table->string('route_value', 190);
            $table->char('refinement_hash', 64);
            $table->json('refinements_json')->nullable();
            $table->string('label', 190)->nullable();
            $table->timestamp('saved_at');
            $table->timestamps();

            $table->unique(
                ['customer_account_id', 'route_family', 'route_value', 'refinement_hash'],
                'customer_saved_searches_unique'
            );
            $table->index(['customer_account_id', 'saved_at'], 'customer_saved_searches_account_time_idx');
            $table->index(['platform_id', 'route_family'], 'customer_saved_searches_route_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_saved_searches');
    }
};
