<?php

namespace App\Services\SendLove;

use App\Models\Client;
use App\Models\LoveGift;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class OwnerService
{
    public function summary(Client $c): array
    {
        $s = app(SettingsService::class)->forPlatform($c->platform);
        $q = LoveGift::where('client_id', $c->id)->where('currency', $s->currency)->where('status', 'sent')->where('is_sandbox', $s->rollout_mode === 'sandbox');
        $month = (clone $q)->where('sent_at', '>=', now()->startOfMonth());
        // Live totals are ledger credit; sandbox presents the simulated gift amount, with no spendable credit.
        $ledger = WalletTransaction::where('client_id', $c->id)->where('currency_code', $s->currency)->where('type', 'credit')->where('reference_type', 'love_received')->whereIn('id', (clone $q)->select('wallet_transaction_id'));
        $money = fn ($n) => number_format((float) $n, 2, '.', '');
        $sandbox = $s->rollout_mode === 'sandbox';

        return ['currency' => $s->currency, 'is_sandbox' => $sandbox, 'month_total' => $money($sandbox ? (clone $month)->sum('amount') : (clone $ledger)->where('created_at', '>=', now()->startOfMonth())->sum('amount')),
            'all_time' => $money($sandbox ? (clone $q)->sum('amount') : (clone $ledger)->sum('amount')),
            'today' => $money($sandbox ? (clone $q)->whereDate('sent_at', today())->sum('amount') : (clone $ledger)->whereDate('created_at', today())->sum('amount')),
            'total_gifts' => (clone $q)->count(), 'gifts_count' => (clone $month)->count(), 'senders' => (clone $month)->distinct()->count('visitor_phone_hash'),
            'unseen' => (clone $q)->whereNull('seen_at')->count(), 'unseen_total' => $money((clone $q)->whereNull('seen_at')->sum($sandbox ? 'amount' : 'creator_credit_amount')),
            'visible' => DB::table('send_love_visibility')->where('client_id', $c->id)->where('visible', false)->doesntExist(),
            'gifts' => (clone $q)->latest('sent_at')->limit(50)->get()->map(function ($g) {
                $visible = $g->message_state === 'visible';

                return ['public_id' => $g->public_id, 'amount' => $g->amount, 'tier' => $g->tier, 'card_line' => $g->card_line, 'message' => $visible ? $g->message : null, 'sender_name' => $visible ? $g->sender_name : null,
                    'contact_phone' => $visible && $g->contact_shared && $g->contact_consent_at ? $g->contact_phone_encrypted : null, 'message_state' => $g->message_state, 'sent_at' => $g->sent_at?->toIso8601String(), 'seen' => (bool) $g->seen_at];
            })->values()->all()];
    }
}
