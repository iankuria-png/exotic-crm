<?php

namespace App\Services\Monetization;

use App\Models\Client;
use App\Models\ClientMonetizationPass;
use App\Models\Payment;
use App\Services\MonetizationSettingsService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

class PassService
{
    public function __construct(private MonetizationSettingsService $settings, private ListingEligibility $eligibility, private WalletService $wallet) {}

    public function current(Client $client): ?ClientMonetizationPass
    {
        return ClientMonetizationPass::where('client_id', $client->id)->whereIn('status', ['active', 'queued'])->where('expires_at', '>', now())->orderByDesc('expires_at')->first();
    }

    public function quote(Client $client, string $duration): array
    {
        $s = $this->settings->forPlatform($client->platform);
        $facts = $this->eligibility->facts($client);
        $price = $s->prices()->where('duration_key', $duration)->where('is_active', true)->firstOrFail();
        $list = (int) round((float) $price->price * 100);
        $subsidy = ! $facts['paid_listing'] ? 0 : ($price->subsidy_mode === 'percentage' ? (int) round($list * (float) $price->subsidy_value / 100) : (int) round((float) $price->subsidy_value * 100));

        return $facts + ['duration_key' => $duration, 'duration_days' => $price->duration_days, 'price_id' => $price->id, 'currency' => $s->currency, 'list_amount' => number_format($list / 100, 2, '.', ''), 'subsidy_amount' => number_format(min($list, $subsidy) / 100, 2, '.', ''), 'payable_amount' => number_format(max(0, $list - $subsidy) / 100, 2, '.', ''), 'config_revision' => $s->config_revision];
    }

    public function activate(Client $client, array $input, string $attempt): ClientMonetizationPass
    {
        return DB::transaction(function () use ($client, $input, $attempt) {
            $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $client->platform_id.':'.$client->id.':'.$attempt);
            if ($old = ClientMonetizationPass::where('idempotency_key_hash', $hash)->first()) {
                return $old;
            }
            $s = $this->settings->forPlatform($client->platform);
            $this->settings->assertCommerce($s, 'activation');
            $quote = $this->quote($client, $input['duration_key']);
            abort_unless($quote['listing_active'], 403, 'Selling private content needs an active listing.');
            abort_if($quote['held'], 409, 'Selling is on hold. Contact support.');
            abort_if(ClientMonetizationPass::where('client_id', $client->id)->whereIn('status', ['held', 'revoked'])->where('expires_at', '>', now())->exists(), 409, 'Your pass is on hold. Contact support.');
            $current = $this->current($client);
            abort_unless(($input['intent'] ?? '') === ($current ? 'renew' : 'activate') && (int) ($input['expected_current_pass_id'] ?? 0) === (int) ($current?->id ?? 0) && ($current ? (! empty($input['expected_expires_at']) && \Carbon\Carbon::parse($input['expected_expires_at'])->equalTo($current->expires_at)) : empty($input['expected_expires_at'])), 409, 'Your pass changed. Refresh before continuing.');
            abort_unless((string) ($input['expected_amount'] ?? '') === $quote['payable_amount'] && (int) ($input['config_revision'] ?? 0) === (int) $s->config_revision, 409, 'Your price changed. Review the new quote.');
            $sandbox = $s->rollout_mode === 'sandbox';
            abort_if($sandbox && ! in_array($client->id, $s->test_client_ids ?? [], true), 403, 'Sandbox is limited to designated test creators.');
            ClientMonetizationPass::where('client_id', $client->id)->where('expires_at', '<=', now())->whereIn('status', ['active', 'queued'])->update(['status' => 'expired', 'active_marker' => null]);
            $start = $current ? $current->expires_at->copy() : now();
            $pass = ClientMonetizationPass::create([
                'client_id' => $client->id, 'platform_id' => $client->platform_id, 'price_id' => $quote['price_id'],
                'status' => $current ? 'queued' : 'active', 'active_marker' => $current ? null : 1,
                'starts_at' => $start, 'expires_at' => $start->copy()->addDays($quote['duration_days']),
                'duration_key' => $quote['duration_key'], 'duration_days' => $quote['duration_days'], 'currency' => $quote['currency'],
                'list_amount' => $quote['list_amount'], 'subsidy_amount' => $quote['subsidy_amount'], 'paid_amount' => $quote['payable_amount'],
                'eligibility_snapshot_json' => $quote, 'is_sandbox' => $sandbox, 'idempotency_key_hash' => $hash,
            ]);
            if (! $sandbox) {
                $payment = Payment::create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'purpose' => Payment::PURPOSE_MONETIZE_PASS, 'amount' => $quote['payable_amount'], 'currency' => $quote['currency'], 'status' => 'completed', 'source' => 'wallet', 'completed_at' => now(), 'transaction_reference' => 'MP-'.$pass->id]);
                if ((float) $quote['payable_amount'] > 0) {
                    $this->wallet->debit($client, $quote['currency'], (float) $quote['payable_amount'], ['payment' => $payment, 'idempotency_key' => 'monetize-pass:'.$pass->id, 'reference_type' => 'monetize_pass', 'reference_id' => $pass->id, 'description' => 'Private content pass']);
                }
                $pass->update(['payment_id' => $payment->id]);
            }

            return $pass;
        }, 3);
    }
}
