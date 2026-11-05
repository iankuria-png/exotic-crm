<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_audit_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_ref')->unique();
            $table->string('status', 20);
            $table->dateTime('from_at');
            $table->dateTime('to_at');
            $table->dateTime('as_of');
            $table->string('rule_version', 30);
            $table->json('coverage');
            $table->timestamps();
            $table->index(['status', 'id']);
        });
        Schema::create('subscription_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('subscription_audit_runs')->cascadeOnDelete();
            $table->string('event_ref', 68);
            $table->unsignedBigInteger('platform_id');
            $table->string('country', 100)->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->string('event_type', 24);
            $table->string('staff_handle', 38)->nullable();
            $table->boolean('exception');
            $table->string('population', 12);
            // Internal source mapping is never serialised through MCP.
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            foreach (['client_id', 'deal_id', 'payment_id', 'actor_id', 'approver_id'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->json('payload');
            $table->unique(['run_id', 'event_ref']);
            $table->index(['run_id', 'platform_id', 'occurred_at'], 'sub_audit_run_site_time');
            $table->index(['run_id', 'staff_handle'], 'sub_audit_run_staff');
            $table->index(['run_id', 'country'], 'sub_audit_run_country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_audit_events');
        Schema::dropIfExists('subscription_audit_runs');
    }
};
