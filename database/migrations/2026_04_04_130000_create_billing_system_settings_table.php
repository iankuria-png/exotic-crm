<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('billing_system_settings')) {
            Schema::create('billing_system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('scope', 32)->default('global')->unique();
                $table->json('mode_json')->nullable();
                $table->json('domain_json')->nullable();
                $table->json('branding_json')->nullable();
                $table->json('timing_json')->nullable();
                $table->json('smtp_json')->nullable();
                $table->json('pin_policy_json')->nullable();
                $table->json('discount_policy_json')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_system_settings');
    }
};
