<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a paid subscription wait for its market instead of failing the sale.
 *
 * When WordPress is unreachable the advertiser has still paid, so the deal is
 * committed as `paid` and marked deferred here. crm:retry-deferred-activations
 * picks it up once the market answers again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->timestamp('activation_deferred_at')->nullable()->after('activated_at');
            $table->unsignedSmallInteger('activation_attempts')->default(0)->after('activation_deferred_at');
            $table->string('activation_deferred_reason', 255)->nullable()->after('activation_attempts');

            // The retry sweep asks one question: which deals are still waiting?
            $table->index(['activation_deferred_at', 'status'], 'deals_activation_deferred_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex('deals_activation_deferred_idx');
            $table->dropColumn(['activation_deferred_at', 'activation_attempts', 'activation_deferred_reason']);
        });
    }
};
