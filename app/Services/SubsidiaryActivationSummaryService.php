<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\TrialActivationOperation;
use App\Models\User;

/** GET projection: no approval, provisioning, synchronization or entitlement mutations. */
class SubsidiaryActivationSummaryService
{
    private float $deadline = 0;

    public function publicLink(Client $client): ?string
    {
        try {
            if (! $client->wp_post_id || ! $client->wp_user_id) {
                return null;
            }
            if (! $this->deadline) {
                $this->deadline = microtime(true) + 4;
            }
            $response = WpSyncService::forPlatform($client->platform_id)->withReadBudget(fn () => max(0, $this->deadline - microtime(true)))->getClient((int) $client->wp_post_id);
            $profile = $response['data'] ?? $response;
            if ((int) ($profile['wp_post_id'] ?? 0) !== (int) $client->wp_post_id || (int) ($profile['wp_user_id'] ?? 0) !== (int) $client->wp_user_id || ($profile['post_status'] ?? '') !== 'publish') {
                return null;
            }
            $helper = app(SubsidiaryActivationPreviewService::class);
            $phone = \App\Support\PhoneNormalizer::normalize($profile['phone'] ?? null, $client->platform?->phone_prefix);
            if ($phone !== $client->phone_normalized || $helper->email($profile['email'] ?? '') !== $helper->email($client->email)) {
                return null;
            }
            $url = $profile['wp_profile_permalink'] ?? null;
            $parts = $url ? parse_url($url) : false;
            $base = parse_url((string) $client->platform?->wp_api_url);
            if (! $parts || ! $base || ! in_array($parts['scheme'] ?? '', ['https', 'http']) || ! empty($parts['user']) || ! empty($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                return null;
            }
            if (preg_replace('/^www\./', '', strtolower($parts['host'] ?? '')) !== preg_replace('/^www\./', '', strtolower($base['host'] ?? '')) || ($parts['port'] ?? null) !== ($base['port'] ?? null)) {
                return null;
            }
            if (preg_match('#/(wp-admin|wp-login\.php|login|checkout|payment)(/|$)#i', $parts['path'] ?? '')) {
                return null;
            }

            return $url;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function row(?Client $client, string $status, ?Deal $coverage = null): array
    {
        return ['status' => $status, 'kind' => $coverage?->is_free_trial ? 'trial' : 'subscription', 'client_id' => $client?->id, 'name' => $client?->name, 'market' => $client?->platform?->name,
            'public_url' => $client && in_array($status, ['active', 'already_active']) ? $this->publicLink($client) : null,
            'expires_at' => $coverage?->expires_at?->toIso8601String(), 'timezone' => $client?->platform?->timezone ?: 'Africa/Nairobi'];
    }

    public function read(Deal $main, User $actor): array
    {
        $this->deadline = microtime(true) + 4;
        $main->loadMissing('client.platform', 'linkedDeal.client.platform');
        $primaryStatus = $main->status === 'active' ? ($main->expires_at && $main->expires_at->isPast() ? 'expired' : 'active') : (($main->status === 'paid' || $main->activation_deferred_at) ? 'activation_pending' : (in_array($main->status, ['failed', 'cancelled']) ? 'failed' : 'waiting_payment'));
        $out = ['deal_id' => $main->id, 'primary' => $this->row($main->client, $primaryStatus, $main), 'subsidiary' => ['status' => 'not_included']];
        $intent = $main->pending_subsidiary_trial ?? [];
        $trial = ! empty($intent['trial_request_id']) ? FreeTrialRequest::find($intent['trial_request_id']) : null;
        $linked = $main->linkedDeal;
        $targetId = (int) ($intent['platform_id'] ?? $linked?->platform_id ?? 0);
        if (! $targetId) {
            return $out;
        }
        if (! app(MarketAuthorizationService::class)->userCanAccessPlatform($actor, $targetId)) {
            $out['subsidiary'] = ['status' => 'restricted'];

            return $out;
        }
        if ($trial && ((int) $trial->platform_id !== $targetId || (int) ($trial->terms_json['source_deal_id'] ?? 0) !== (int) $main->id || ($actor->role !== 'admin' && (int) $trial->requester_id !== (int) $actor->id && empty($trial->terms_json['collection_obligation_id'])))) {
            $out['subsidiary'] = ['status' => 'restricted'];

            return $out;
        }
        if ($trial && ! empty($trial->terms_json['collection_obligation_id'])) {
            $owner = app(\App\Services\Collections\CollectionSubsidiaryFulfillmentService::class)->forRequest($trial);
            app(\App\Services\Collections\CollectionAccessService::class)->receipt($actor, $owner->allocation->receipt_id);
        }
        if (! empty($intent['collection_obligation_id'])) {
            $o = \App\Models\Collections\CollectionSubsidiaryObligation::findOrFail($intent['collection_obligation_id']);
            abort_unless((int) $o->source_deal_id === (int) $main->id && (int) $o->target_platform_id === $targetId, 409);
            app(\App\Services\Collections\CollectionAccessService::class)->receipt($actor, $o->allocation->receipt_id);
            $effect = $o->dependent_effect_id ? \App\Models\Collections\CollectionBenefitEffect::find($o->dependent_effect_id) : null;
            $client = ! empty($o->target_identity['client_id']) ? Client::where('platform_id', $targetId)->find($o->target_identity['client_id']) : null;
            $coverage = $effect?->state === 'succeeded' ? Deal::find($effect->deal_id) : null;
            $state = match ($o->state) {
                'succeeded' => $coverage && $coverage->expires_at?->isFuture() ? 'active' : 'expired',
                'already_active' => $o->interval['end'] > now()->timestamp ? 'already_active' : 'expired',
                'needs_approval' => 'pending_approval', 'needs_profile' => 'setup_paused', 'copying' => 'setting_up', 'canceled' => 'cancelled',
                'uncertain' => 'uncertain', default => 'activating'
            };
            $copy = $trial ? app(SubsidiaryProfileCopyService::class)->projection(app(SubsidiaryProfileCopyService::class)->run($trial), $actor) : null;
            $out['subsidiary'] = $this->row($client, $state, $coverage) + ['request_id' => $trial?->id, 'request_version' => $trial?->version,
                'can_review' => $actor->role === 'admin' && $trial?->status === 'pending', 'can_recover' => $actor->role === 'admin' && $o->state === 'uncertain',
                'copy' => $copy, 'copied_from' => $copy ? $main->client?->platform?->name : null];
            $out['subsidiary']['market'] = \App\Models\Platform::find($targetId)?->name;
            if ($o->state === 'already_active') {
                $out['subsidiary']['expires_at'] = \Illuminate\Support\Carbon::createFromTimestamp($o->interval['end'])->toIso8601String();
            }
            return $out;
        }
        $status = 'unknown';
        $coverage = null;
        $client = null;
        if (($intent['status'] ?? '') === 'already_active' || ($trial?->activation_result_json['outcome'] ?? '') === 'existing_coverage') {
            $coverage = Deal::where('platform_id', $targetId)->whereKey($intent['coverage_id'] ?? $trial?->activation_result_json['coverage_id'] ?? 0)->first();
            $client = $coverage?->client;
            $status = $coverage && $coverage->status === 'active' && (! $coverage->expires_at || $coverage->expires_at->isFuture()) ? 'already_active' : 'expired';
        } elseif ($trial) {
            $client = $trial->client_id ? Client::where('platform_id', $targetId)->find($trial->client_id) : null;
            $status = match ($trial->status) {
                'pending' => 'pending_approval', 'canceled' => 'cancelled', default => $trial->status
            };
            if ($trial->status === 'active') {
                $op = TrialActivationOperation::where('request_id', $trial->id)->where('client_id', $trial->client_id)->where('state', 'succeeded')->whereNotNull('verified_at')->first();
                $coverage = $op ? Deal::where('platform_id', $targetId)->where('client_id', $trial->client_id)->find($op->deal_id) : null;
                $status = $coverage ? ((! $coverage->expires_at || $coverage->expires_at->isFuture()) && $coverage->status === 'active' ? 'active' : 'expired') : 'uncertain';
            } elseif (in_array($trial->status, ['approved', 'activating']) && ($main->status !== 'active' || ($main->expires_at && $main->expires_at->isPast()))) {
                $status = 'waiting_primary';
            }
            $copy = app(SubsidiaryProfileCopyService::class)->projection(app(SubsidiaryProfileCopyService::class)->run($trial), $actor);
            if ($copy && in_array($trial->status, ['approved', 'activating'], true) && $status !== 'waiting_primary') {
                $status = match ($copy['state']) { 'needs_review' => 'setup_paused', 'ready' => 'activating', 'cancelled' => 'cancelled', default => 'setting_up' };
            }
        } elseif ($linked && (int) $linked->platform_id === $targetId) {
            $client = $linked->client;
            $coverage = $linked;
            $status = $linked->status === 'active' && (! $linked->expires_at || $linked->expires_at->isFuture()) ? 'active' : 'expired';
        }
        $out['subsidiary'] = array_merge($this->row($client, $status, $coverage), [
            'market' => $client?->platform?->name ?? \App\Models\Platform::find($targetId)?->name,
            'request_id' => $trial?->id, 'request_version' => $trial?->version,
            'can_review' => $actor->role === 'admin' && $trial?->status === 'pending',
            'can_recover' => $actor->role === 'admin' && $trial && in_array($trial->status, ['activating', 'uncertain']) && ! in_array($status, ['setting_up', 'setup_paused'], true),
            'copy' => $copy ?? null,
            'copied_from' => ($copy ?? null) ? $main->client?->platform?->name : null,
        ]);

        return $out;
    }
}
