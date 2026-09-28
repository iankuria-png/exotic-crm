<?php

namespace App\Services\Monetization;

use App\Models\Client;
use App\Models\VisitorContentPurchase;
use App\Models\WalletTransaction;

class StatsService
{
    public function ledger(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $positions = [];
        $refundIds = VisitorContentPurchase::where('status', 'refunded')->whereIn('client_id', (clone $query)->select('client_id')->distinct())->get(['metadata_json'])->map(fn ($p) => (int) data_get($p->metadata_json, 'refund.wallet_adjustment_id'))->filter()->all();
        foreach ($query->orderBy('id')->cursor() as $t) {
            $key = $t->client_id.':'.$t->currency_code;
            if (! isset($positions[$key])) {
                $positions[$key] = ['currency' => $t->currency_code, 'earned_outstanding' => 0, 'earned_spent' => 0, 'earned_reversed' => 0];
            }
            $amount = (int) round((float) $t->amount * 100);
            if ($t->type === 'credit' && $t->reference_type === 'premium_content_sale') {
                $positions[$key]['earned_outstanding'] += $amount;
            }
            if ($t->type === 'debit') {
                $used = min($positions[$key]['earned_outstanding'], $amount);
                $positions[$key]['earned_outstanding'] -= $used;
                $positions[$key][in_array($t->id, $refundIds, true) ? 'earned_reversed' : 'earned_spent'] += $used;
            }
        }
        $totals = [];
        foreach ($positions as $row) {
            $currency = $row['currency'];
            if (! isset($totals[$currency])) {
                $totals[$currency] = ['currency' => $currency, 'earned_outstanding' => 0, 'earned_spent' => 0, 'earned_reversed' => 0];
            }foreach (['earned_outstanding', 'earned_spent', 'earned_reversed'] as $key) {
                $totals[$currency][$key] += $row[$key];
            }
        }

        return array_values(array_map(function ($row) {
            foreach (['earned_outstanding', 'earned_spent', 'earned_reversed'] as $key) {
                $row[$key] = number_format($row[$key] / 100, 2, '.', '');
            }

            return $row;
        }, $totals));
    }

    public function owner(Client $client): array
    {
        $currency = app(\App\Services\WalletSettingsService::class)->runtimeWalletCurrencyCode($client->platform);
        $earned = 0;
        $spent = 0;
        $sales = 0;
        $reversed = 0;
        $refundDebits = VisitorContentPurchase::where('client_id', $client->id)->where('status', 'refunded')->get()->map(fn ($p) => data_get($p->metadata_json, 'refund.wallet_adjustment_id'))->filter()->map(fn ($id) => (int) $id)->all();
        foreach (WalletTransaction::where('client_id', $client->id)->where('currency_code', $currency)->orderBy('id')->cursor() as $t) {
            $amount = (int) round((float) $t->amount * 100);
            if ($t->type === 'credit' && $t->reference_type === 'premium_content_sale') {
                $earned += $amount;
                $sales += $amount;
            }
            if ($t->type === 'debit') {
                $used = min($earned, $amount);
                $earned -= $used;
                if (in_array($t->id, $refundDebits, true)) {
                    $reversed += $used;
                } else {
                    $spent += $used;
                }
            }
        }
        $q = VisitorContentPurchase::where('client_id', $client->id)->where('is_sandbox', false)->where('status', 'active');

        return ['currency' => $currency, 'gross_sales' => number_format($sales / 100, 2, '.', ''), 'earned_outstanding' => number_format($earned / 100, 2, '.', ''), 'earned_reversed' => number_format($reversed / 100, 2, '.', ''), 'earned_spent' => number_format($spent / 100, 2, '.', ''), 'month_sales' => number_format((float) (clone $q)->where('purchased_at', '>=', now()->startOfMonth())->sum('gross_amount'), 2, '.', ''), 'sales_count' => $q->count()];
    }
}
