<?php

namespace App\Services\Rebates;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\RebateBudgetPeriod;
use App\Models\WalletRebate;

class RebateReportService
{
    public function period(Platform $p): string
    {
        return now()->timezone($p->timezone ?: 'UTC')->format('Y-m');
    }

    public function budget(Platform $p, array $snapshot): array
    {
        $period = $this->period($p);
        $row = RebateBudgetPeriod::where('platform_id', $p->id)->where('period_key', $period)->first();

        return ['period' => $period, 'issued' => (float) ($row?->issued_amount ?? 0), 'budget' => (float) ($row?->budget_amount ?? data_get($snapshot, 'guard.budget', 0))];
    }

    public function summary(Client $c): array
    {
        $c->loadMissing('platform');
        $program = \App\Models\RebateProgram::where('platform_id', $c->platform_id)->first();
        $snapshot = $program ? app(RebateProgramService::class)->snapshot($program) : null;
        $query = WalletRebate::where('client_id', $c->id)->whereIn('status', ['credited', 'capped', 'reversed']);
        $earned = (float) (clone $query)->where('period_key', $this->period($c->platform))->sum('amount');
        $budget = $this->budget($c->platform, $snapshot ?? []);

        return ['earned_this_month' => $earned, 'lifetime' => (float) $query->sum('amount'), 'monthly_cap_remaining' => max(0, (float) data_get($snapshot, 'guard.monthly_cap', 0) - $earned),
            'first_topup_eligible' => app(RebateProgramService::class)->firstTopupEligible($c),
            'eligible' => $program && $snapshot && ! app(RebateProgramService::class)->eligibility($program, $c, $snapshot),
            'budget_remaining' => max(0, $budget['budget'] - $budget['issued']),
            'period_key' => $this->period($c->platform)];
    }

    public function performance(Platform $p, array $rules): array
    {
        $resolver = app(RebateChannelResolver::class);
        $calc = app(RebateCalculator::class);
        $channelRows = [];
        $earnedByClient = [];
        $firstByClient = [];
        $weekly = [];
        $subscriptionVolume = 0;
        $selfVolume = 0;
        $cashVolume = 0;
        $topupVolume = 0;
        $topupCount = 0;
        $paidClients = [];
        $walletClients = [];
        // Stream, rather than loading the full monthly payment history into memory.
        Payment::where('platform_id', $p->id)->where('status', 'completed')->where('currency', $p->currency_code)->where('completed_at', '>=', now()->subDays(30))->whereIn('purpose', ['wallet_topup', 'subscription'])->with(['deal', 'routingDecisions'])->orderBy('id')->chunkById(500, function ($payments) use (&$channelRows, &$earnedByClient, &$firstByClient, &$weekly, &$subscriptionVolume, &$selfVolume, &$cashVolume, &$topupVolume, &$topupCount, &$paidClients, &$walletClients, $resolver, $calc, $rules, $p) {
            foreach ($payments as $payment) {
                if ($resolver->sandbox($payment)) {
                    continue;
                }
                $channel = $resolver->forProjection($payment);
                if ($payment->purpose === 'subscription') {
                    $subscriptionVolume += (float) $payment->amount;
                    $week = $payment->completed_at->timezone($p->timezone ?: 'UTC')->startOfWeek()->format('Y-m-d');
                    $weekly[$week] ??= ['week' => $week, 'total' => 0, 'self_paid' => 0];
                    $weekly[$week]['total'] += (float) $payment->amount;
                    if (in_array($channel, ['wallet', 'auto_renew', 'self_checkout', 'manual_submission'], true)) {
                        $selfVolume += (float) $payment->amount;
                        $weekly[$week]['self_paid'] += (float) $payment->amount;
                    }
                    if ($channel && ! in_array($channel, ['wallet', 'auto_renew'], true)) {
                        $cashVolume += (float) $payment->amount;
                    }
                }
                if (! $channel || ! $payment->client_id) {
                    continue;
                }
                $id = $payment->client_id;
                $paidClients[$id] = true;
                if (in_array($channel, ['topup', 'wallet', 'auto_renew'], true)) {
                    $walletClients[$id] = true;
                }
                if ($channel === 'topup') {
                    $topupVolume += (float) $payment->amount;
                    $topupCount++;
                    $cashVolume += (float) $payment->amount;
                }
                $channelRows[$channel] ??= ['channel' => $channel, 'payments' => 0, 'volume' => 0, 'projected' => 0];
                $channelRows[$channel]['payments']++;
                $channelRows[$channel]['volume'] += (float) $payment->amount;
                if (! array_key_exists($id, $firstByClient)) {
                    $firstByClient[$id] = ! Payment::where('client_id', $id)->where('purpose', 'wallet_topup')->where('status', 'completed')->where('payment_data->initiator', 'companion')->where('id', '<', $payment->id)->exists();
                }
                $quote = $calc->quote($rules, ['channel' => $channel, 'amount' => $payment->amount, 'first_topup' => $firstByClient[$id], 'earned_this_month' => $earnedByClient[$id] ?? 0, 'budget_remaining' => PHP_INT_MAX]);
                $channelRows[$channel]['projected'] += $quote['total'];
                $earnedByClient[$id] = ($earnedByClient[$id] ?? 0) + $quote['total'];
                if ($channel === 'topup') {
                    $firstByClient[$id] = false;
                }
            }
        });
        $issued = WalletRebate::where('platform_id', $p->id)->where('period_key', $this->period($p))->whereIn('status', ['credited', 'capped', 'reversed']);
        $cost = (float) (clone $issued)->sum('amount');
        $byTrigger = (clone $issued)->selectRaw('`trigger`, channel, COUNT(*) as grants, SUM(amount) as issued')->groupBy('trigger', 'channel')->get();

        return ['budget' => $this->budget($p, $rules), 'issued' => $cost, 'by_trigger' => $byTrigger, 'weekly' => array_values($weekly),
            'self_service_share' => $subscriptionVolume ? round(100 * $selfVolume / $subscriptionVolume, 1) : 0,
            'wallet_adoption' => $paidClients ? round(100 * count($walletClients) / count($paidClients), 1) : 0,
            'average_self_paid_topup' => $topupCount ? round($topupVolume / $topupCount, 2) : 0,
            'cost_share' => $cashVolume ? round(100 * $cost / $cashVolume, 2) : 0, 'cash_revenue_30d' => $cashVolume, 'net_after_issuance_cost' => $cashVolume - $cost,
            'projection' => ['total' => array_sum(array_column($channelRows, 'projected')), 'rows' => array_values($channelRows), 'basis' => 'Last 30 days of settled production payments; companion caps applied, market budget excluded.'],
            'window' => '30 days', 'cost_period' => $this->period($p)];
    }
}
