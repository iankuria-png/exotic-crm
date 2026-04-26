<?php

namespace Tests\Concerns;

use App\Models\AgentDailyStat;
use App\Models\AgentSession;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;

trait InteractsWithTeamActivityFixtures
{
    private function createTeamPlatform(array $overrides = []): Platform
    {
        return Platform::factory()->create(array_merge([
            'name' => 'Team Market ' . fake()->unique()->city(),
            'currency_code' => 'KES',
        ], $overrides));
    }

    private function createTeamUser(string $role = 'sales', array $assignedMarketIds = [], array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ], $overrides));

        if (!empty($assignedMarketIds)) {
            $user->platforms()->syncWithoutDetaching($assignedMarketIds);
        }

        return $user;
    }

    private function createTeamClient(Platform $platform, array $overrides = []): Client
    {
        return Client::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'wallet_currency' => $platform->currency_code,
        ], $overrides));
    }

    private function createTeamLead(Platform $platform, ?User $agent = null, array $overrides = []): Lead
    {
        return Lead::query()->create(array_merge([
            'platform_id' => $platform->id,
            'name' => 'Lead ' . fake()->unique()->name(),
            'phone_normalized' => '2547' . fake()->unique()->numerify('#######'),
            'email' => fake()->safeEmail(),
            'source' => 'registration',
            'status' => 'new',
            'assigned_to' => $agent?->id,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ], $overrides));
    }

    private function createTeamDeal(Platform $platform, User $agent, array $overrides = []): Deal
    {
        $client = $overrides['client'] ?? $this->createTeamClient($platform, [
            'assigned_to' => $agent->id,
        ]);

        $product = $overrides['product'] ?? Product::factory()->create([
            'platform_id' => $platform->id,
            'currency' => $platform->currency_code,
        ]);

        unset($overrides['client'], $overrides['product']);

        return Deal::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'amount' => 5000,
            'currency' => $platform->currency_code,
            'status' => 'active',
            'activated_at' => now()->subHour(),
            'expires_at' => now()->addMonth(),
            'assigned_to' => $agent->id,
            'is_free_trial' => false,
        ], $overrides));
    }

    private function createTeamPayment(Platform $platform, ?Deal $deal = null, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'product_id' => $deal?->product_id,
            'deal_id' => $deal?->id,
            'client_id' => $deal?->client_id,
            'amount' => $deal ? (float) $deal->amount : 5000,
            'currency' => $deal?->currency ?: $platform->currency_code,
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
            'reconciliation_state' => 'resolved',
            'record_classification' => Payment::RECORD_CLASSIFICATION_LIVE,
        ], $overrides));
    }

    private function createTeamAudit(array $attributes): AuditLog
    {
        return AuditLog::query()->create(array_merge([
            'platform_id' => null,
            'actor_id' => null,
            'action' => 'client_create',
            'entity_type' => 'client',
            'entity_id' => 1,
            'before_state' => null,
            'after_state' => null,
            'reason' => null,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ], $attributes));
    }

    private function createTeamSession(User $user, string $sessionToken, array $overrides = []): AgentSession
    {
        return AgentSession::query()->create(array_merge([
            'user_id' => $user->id,
            'session_token' => $sessionToken,
            'started_at' => now()->subMinutes(5),
            'last_heartbeat_at' => now()->subSeconds(15),
            'ended_at' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit Browser',
        ], $overrides));
    }

    private function createTeamDailyStat(User $user, Platform $platform, CarbonInterface|string $date, array $overrides = []): AgentDailyStat
    {
        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;

        return AgentDailyStat::query()->create(array_merge([
            'user_id' => $user->id,
            'platform_id' => $platform->id,
            'date' => $dateString,
            'profiles_created' => 0,
            'subs_activated' => 0,
            'subs_renewed' => 0,
            'payments_matched' => 0,
            'subscriptions_created' => 0,
            'leads_contacted' => 0,
            'leads_converted' => 0,
            'chats_replied' => 0,
            'sms_sent' => 0,
            'credentials_sent' => 0,
            'revenue' => '0.00',
            'revenue_currency' => $platform->currency_code,
            'free_trials_given' => 0,
            'discounts_given' => 0,
            'avg_lead_response_secs' => null,
            'total_actions' => 0,
        ], $overrides));
    }
}
