<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->index(['platform_id', 'created_at', 'signup_source'], 'clients_forecast_signup_idx');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->index(['platform_id', 'expires_at', 'status'], 'deals_forecast_expiry_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['client_id', 'subscription_lifecycle'], 'payments_forecast_client_lifecycle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_forecast_client_lifecycle_idx');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex('deals_forecast_expiry_idx');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_forecast_signup_idx');
        });
    }
};
