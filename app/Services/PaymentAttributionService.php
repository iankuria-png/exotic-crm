<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentAttribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentAttributionService
{
    public function capture(Payment $payment, ?Client $client = null, bool $archived = false): ?PaymentAttribution
    {
        if (! Schema::hasTable('payment_attributions') || ! $payment->platform_id || $payment->isVisitorPayment()) {
            return null;
        }
        $client ??= $payment->client_id ? Client::find($payment->client_id) : ($payment->deal_id ? Deal::find($payment->deal_id)?->client : null);
        if (! $client || (int) $client->platform_id !== (int) $payment->platform_id) {
            return null;
        }

        return DB::transaction(function () use ($payment, $client, $archived) {
            $row = PaymentAttribution::query()->firstOrCreate(['payment_id' => $payment->id], [
                'platform_id' => $payment->platform_id, 'original_client_id' => $client->id,
                'wp_user_id' => $client->wp_user_id, 'wp_post_id' => $client->wp_post_id,
                'client_first_seen_at' => $client->created_at, 'attribution_state' => $archived ? 'archived' : 'current',
                'evidence_json' => ['method' => 'verified_client_link', 'deal_id' => $payment->deal_id],
            ]);
            $row = PaymentAttribution::query()->lockForUpdate()->findOrFail($payment->id);
            $state = (int) $row->original_client_id !== (int) $client->id ? 'disputed' : ($archived ? 'archived' : $row->attribution_state);
            if ($state !== $row->attribution_state || $archived) {
                $before = $row->only(['attribution_state', 'version']);
                $evidence = $row->evidence_json;
                if ($archived) {
                    $deal = $payment->deal_id ? Deal::find($payment->deal_id) : Deal::where('payment_id', $payment->id)->where('platform_id', $payment->platform_id)->where('client_id', $client->id)->first();
                    if ($deal && (int) $deal->client_id === (int) $client->id) {
                        $evidence['benefit'] = array_replace($deal->only(['id', 'payment_id', 'product_id', 'status', 'is_free_trial', 'activated_at', 'expires_at']), ['payment_id' => $payment->id]);
                    }
                }
                $row->update(['attribution_state' => $state, 'evidence_json' => $evidence, 'version' => $row->version + 1]);
                $this->audit($payment, 'payment_attribution_state', $before, $row->only(['attribution_state', 'version']), 'Original payment subject retained independently of profile availability.');
            }

            return $row;
        });
    }

    public function archiveClient(Client $client): void
    {
        if (! Schema::hasTable('payment_attributions')) {
            throw new \RuntimeException('Payment attribution migration is required before profile deletion.');
        }
        Payment::query()->where('platform_id', $client->platform_id)->where(function ($q) use ($client) {
            $q->where('client_id', $client->id)->orWhereIn('deal_id', $client->deals()->select('id'))->orWhereIn('id', $client->deals()->whereNotNull('payment_id')->select('payment_id'));
        })->orderBy('id')->chunkById(100, function ($rows) use ($client) {
            foreach ($rows as $p) {
                $this->capture($p, $client, true);
            }
        });
    }

    public function audit(Payment $payment, string $action, ?array $before, array $after, string $reason, ?int $actor = null): void
    {
        if (! app(AuditService::class)->record(['platform_id' => $payment->platform_id, 'actor_id' => $actor,
            'entity_type' => 'payment', 'entity_id' => $payment->id, 'action' => $action,
            'before_state' => $before, 'after_state' => $after, 'reason' => $reason])) {
            throw new \RuntimeException('Payment recovery audit could not be saved.');
        }
    }
}
