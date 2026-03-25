<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('profiles_created')->default(0);
            $table->unsignedSmallInteger('subs_activated')->default(0);
            $table->unsignedSmallInteger('subs_renewed')->default(0);
            $table->unsignedSmallInteger('payments_matched')->default(0);
            $table->unsignedSmallInteger('subscriptions_created')->default(0);
            $table->unsignedSmallInteger('leads_contacted')->default(0);
            $table->unsignedSmallInteger('leads_converted')->default(0);
            $table->unsignedSmallInteger('chats_replied')->default(0);
            $table->unsignedSmallInteger('sms_sent')->default(0);
            $table->unsignedSmallInteger('credentials_sent')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->string('revenue_currency', 3);
            $table->unsignedSmallInteger('free_trials_given')->default(0);
            $table->unsignedInteger('avg_lead_response_secs')->nullable();
            $table->unsignedSmallInteger('total_actions')->default(0);

            $table->unique(['user_id', 'platform_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_daily_stats');
    }
};
