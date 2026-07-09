<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CommissionService
{
    public function recordActivationCommission(Deal $firstPaidDeal): ?Commission
    {
        $deal = $firstPaidDeal->fresh(['client.platform', 'platform']) ?? $firstPaidDeal;
        if (!$deal || (bool) $deal->is_free_trial || (string) $deal->status !== 'active') {
            return null;
        }

        if (!$this->isFirstPaidActivation($deal)) {
            return null;
        }

        $agentId = (int) (
            $deal->activated_by_field_agent
            ?: $this->seedAgentFromPriorTrial($deal)
            ?: $this->seedAgentFromClient($deal)
        );
        if ($agentId <= 0) {
            return null;
        }

        if ((int) ($deal->activated_by_field_agent ?? 0) !== $agentId) {
            $deal->forceFill(['activated_by_field_agent' => $agentId])->save();
        }

        $platform = $deal->platform ?: $deal->client?->platform ?: Platform::query()->find($deal->platform_id);
        $rate = (float) ($platform?->field_activation_commission_rate ?? 0.15);

        return $this->createEarnedCommission($deal, $agentId, 'activation', $rate, [
            'source' => 'first_paid_activation',
        ]);
    }

    public function recordRenewalCommission(Deal $renewalDeal): ?Commission
    {
        $deal = $renewalDeal->fresh(['client.platform', 'platform']) ?? $renewalDeal;
        if (!$deal || (bool) $deal->is_free_trial || (string) $deal->status !== 'active') {
            return null;
        }

        $agentId = (int) (
            $deal->activated_by_field_agent
            ?: $this->seedAgentFromPriorPaidDeal($deal)
            ?: $this->seedAgentFromClient($deal)
        );
        if ($agentId <= 0) {
            return null;
        }

        if ((int) ($deal->activated_by_field_agent ?? 0) !== $agentId) {
            $deal->forceFill(['activated_by_field_agent' => $agentId])->save();
        }

        if ($this->isFirstPaidActivation($deal)) {
            return null;
        }

        $platform = $deal->platform ?: $deal->client?->platform ?: Platform::query()->find($deal->platform_id);
        $windowMonths = max(0, (int) ($platform?->field_renewal_commission_months ?? 4));
        $firstPaid = $this->firstPaidFieldDeal($deal, $agentId);
        if (!$firstPaid?->activated_at) {
            return null;
        }

        $activatedAt = $deal->activated_at ? Carbon::parse($deal->activated_at) : now();
        if ($windowMonths > 0 && $activatedAt->greaterThan(Carbon::parse($firstPaid->activated_at)->copy()->addMonthsNoOverflow($windowMonths))) {
            return null;
        }

        $rate = (float) ($platform?->field_renewal_commission_rate ?? 0.05);

        return $this->createEarnedCommission($deal, $agentId, 'renewal', $rate, [
            'source' => 'renewal_activation',
            'first_paid_deal_id' => (int) $firstPaid->id,
            'window_months' => $windowMonths,
        ]);
    }

    public function markPaid(array $commissionIds, array $payoutData): CommissionPayout
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $commissionIds))));
        if ($ids === []) {
            throw new InvalidArgumentException('Select at least one commission to pay.');
        }

        return DB::transaction(function () use ($ids, $payoutData) {
            $commissions = Commission::query()
                ->whereIn('id', $ids)
                ->where('status', 'earned')
                ->lockForUpdate()
                ->get();

            if ($commissions->isEmpty()) {
                throw new InvalidArgumentException('No earned commissions were selected.');
            }

            $agentIds = $commissions->pluck('agent_user_id')->unique()->values();
            $currencies = $commissions->pluck('currency')->unique()->values();
            if ($agentIds->count() !== 1 || $currencies->count() !== 1) {
                throw new InvalidArgumentException('Pay one agent and currency at a time.');
            }

            $payout = CommissionPayout::query()->create([
                'agent_user_id' => (int) $agentIds->first(),
                'period_start' => $payoutData['period_start'] ?? null,
                'period_end' => $payoutData['period_end'] ?? null,
                'total_amount' => number_format((float) $commissions->sum('amount'), 2, '.', ''),
                'currency' => (string) $currencies->first(),
                'paid_by' => isset($payoutData['paid_by']) ? (int) $payoutData['paid_by'] : null,
                'paid_at' => $payoutData['paid_at'] ?? now(),
                'external_reference' => $payoutData['external_reference'] ?? null,
                'notes' => $payoutData['notes'] ?? null,
            ]);

            Commission::query()
                ->whereIn('id', $commissions->pluck('id')->all())
                ->update([
                    'status' => 'paid',
                    'paid_at' => $payout->paid_at,
                    'payout_id' => (int) $payout->id,
                    'updated_at' => now(),
                ]);

            return $payout->fresh(['commissions']);
        });
    }

    private function createEarnedCommission(Deal $deal, int $agentId, string $type, float $rate, array $meta = []): Commission
    {
        $basis = round((float) $deal->amount, 2);
        $currency = strtoupper((string) ($deal->currency ?: $deal->client?->platform?->currency_code ?: 'KES'));

        try {
            return Commission::query()->create([
                'agent_user_id' => $agentId,
                'client_id' => (int) $deal->client_id,
                'deal_id' => (int) $deal->id,
                'type' => $type,
                'basis_amount' => number_format($basis, 2, '.', ''),
                'rate' => number_format($rate, 4, '.', ''),
                'amount' => number_format(round($basis * $rate, 2), 2, '.', ''),
                'currency' => $currency,
                'status' => 'earned',
                'earned_at' => now(),
                'meta' => $meta,
            ]);
        } catch (QueryException $exception) {
            $existing = Commission::query()
                ->where('deal_id', (int) $deal->id)
                ->where('type', $type)
                ->first();

            if ($existing) {
                Log::info('Commission idempotency collision returned existing row.', [
                    'deal_id' => (int) $deal->id,
                    'type' => $type,
                    'commission_id' => (int) $existing->id,
                ]);

                return $existing;
            }

            throw $exception;
        }
    }

    private function isFirstPaidActivation(Deal $deal): bool
    {
        return !Deal::query()
            ->where('client_id', (int) $deal->client_id)
            ->where('id', '!=', (int) $deal->id)
            ->where('is_free_trial', false)
            ->whereIn('status', ['active', 'expired', 'renewed'])
            ->whereNotNull('activated_at')
            ->where('activated_at', '<=', $deal->activated_at ?? now())
            ->exists();
    }

    private function seedAgentFromPriorTrial(Deal $deal): ?int
    {
        return Deal::query()
            ->where('client_id', (int) $deal->client_id)
            ->where('is_free_trial', true)
            ->whereNotNull('activated_by_field_agent')
            ->latest('activated_at')
            ->value('activated_by_field_agent');
    }

    private function seedAgentFromPriorPaidDeal(Deal $deal): ?int
    {
        return Deal::query()
            ->where('client_id', (int) $deal->client_id)
            ->where('id', '!=', (int) $deal->id)
            ->where('is_free_trial', false)
            ->whereNotNull('activated_by_field_agent')
            ->latest('activated_at')
            ->value('activated_by_field_agent');
    }

    private function seedAgentFromClient(Deal $deal): ?int
    {
        $client = $deal->client ?: $deal->client()->first();
        if (!$client) {
            return null;
        }

        return $this->resolveFieldAgentForClient($client);
    }

    public function resolveFieldAgentForClient(?Client $client): ?int
    {
        if (!$client) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            (int) ($client->created_by ?? 0),
            (int) ($client->assigned_to ?? 0),
        ])));

        if ($candidates === []) {
            return null;
        }

        $fieldSalesIds = User::query()
            ->whereIn('id', $candidates)
            ->where('role', MarketAuthorizationService::ROLE_FIELD_SALES)
            ->pluck('id')
            ->all();

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $fieldSalesIds, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function firstPaidFieldDeal(Deal $deal, int $agentId): ?Deal
    {
        return Deal::query()
            ->where('client_id', (int) $deal->client_id)
            ->where('is_free_trial', false)
            ->where('activated_by_field_agent', $agentId)
            ->whereNotNull('activated_at')
            ->orderBy('activated_at')
            ->first();
    }
}
