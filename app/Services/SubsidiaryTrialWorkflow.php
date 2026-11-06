<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\Platform;
use App\Models\TrialActivationOperation;
use App\Models\User;
use App\Services\Trials\TrialApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Subsidiary-only claims, attachment and recovery; approval remains in TrialApprovalService. */
class SubsidiaryTrialWorkflow
{
    public function basis(array $terms): array
    {
        $main = Deal::findOrFail($terms['source_deal_id']);
        $product = \App\Models\Product::findOrFail($terms['product_id']);

        return ['main' => $main->only(['id', 'platform_id', 'client_id', 'product_id']),
            'product' => $product->only(['id', 'platform_id', 'tier', 'is_active', 'is_archived'])];
    }

    public function identityKey(array $terms): string
    {
        $phone = $terms['subsidiary_seed']['phone_normalized'] ?? '';

        return hash('sha256', json_encode(['subsidiary', (int) $terms['platform_id'], $phone ?: 'client:'.($terms['client_ids'][0] ?? 0)]));
    }

    public function assertAttached(FreeTrialRequest $r): Deal
    {
        $main = Deal::lockForUpdate()->findOrFail($r->terms_json['source_deal_id']);
        foreach (array_unique(array_filter([$r->requester_id, $r->authorized_actor_id])) as $actorId) {
            app(TrialApprovalService::class)->access(User::findOrFail($actorId), (int) $main->platform_id);
            app(TrialApprovalService::class)->access(User::findOrFail($actorId), (int) $r->platform_id);
        }
        $intent = $main->pending_subsidiary_trial ?? [];
        abort_unless((int) ($intent['trial_request_id'] ?? 0) === (int) $r->id && (int) ($intent['platform_id'] ?? 0) === (int) $r->platform_id,
            409, 'This trial is no longer attached to the current sale. Review the saved request.');

        return $main;
    }

    public function assertCancelable(FreeTrialRequest $r): void
    {
        abort_if(in_array($r->activation_result_json['profile_resolution'] ?? '', ['resolving', 'unknown'])
            || TrialActivationOperation::where('request_id', $r->id)->whereNotNull('dispatched_at')->exists(), 409,
            'This trial is being checked. Recover its existing outcome before changing it.');
    }

    public function supersede(Deal $main, User $actor, ?string $key): void
    {
        $intent = $main->pending_subsidiary_trial ?? [];
        if (empty($intent['trial_request_id'])) {
            return;
        }
        $old = FreeTrialRequest::lockForUpdate()->find($intent['trial_request_id']);
        if (! $old) {
            return;
        }
        if ($key && $old->request_key === $key) {
            return;
        }
        $this->assertCancelable($old);
        if (in_array($old->status, ['pending', 'approved'])) {
            $old->update(['status' => 'canceled', 'active_identity' => null, 'version' => $old->version + 1]);
            app(TrialApprovalService::class)->event($old, $actor, 'canceled', [], 'Superseded by the current sale selection.');
        }
    }

    public function reserve(array $terms): void
    {
        Platform::whereKey($terms['platform_id'])->lockForUpdate()->firstOrFail();
        $phone = $terms['subsidiary_seed']['phone_normalized'] ?? null;
        $others = FreeTrialRequest::where('platform_id', $terms['platform_id'])->where('action', 'subsidiary')
            ->whereNotNull('active_identity')->get();
        foreach ($others as $other) {
            $otherPhone = $other->terms_json['subsidiary_seed']['phone_normalized'] ?? null;
            abort_if(($phone && $phone === $otherPhone) || array_intersect($terms['client_ids'], $other->terms_json['client_ids'] ?? []),
                409, 'An unresolved trial already reserves this customer in the target market. Check its saved request.');
        }
    }

    public function assertSiteIdentity(FreeTrialRequest $request, Client $client, array $profile): void
    {
        $profile = $profile['data'] ?? $profile;
        $expected = $request->terms_json['subsidiary_identity']['target'] ?? null;
        $helper = app(SubsidiaryActivationPreviewService::class);
        $phone = \App\Support\PhoneNormalizer::normalize($profile['phone'] ?? null, $client->platform?->phone_prefix);
        abort_unless($expected && (int) ($profile['wp_post_id'] ?? 0) === (int) $client->wp_post_id
            && (int) ($profile['wp_user_id'] ?? 0) === (int) $client->wp_user_id
            && $phone === $expected['phone_normalized']
            && $helper->email($profile['email'] ?? '') === $helper->email($expected['email'] ?? ''),
            409, 'The site profile contact identity changed. Refresh and review the target profile.');
    }

    public function noGrant(FreeTrialRequest $r, Client $client): bool
    {
        $coverage = app(SubsidiaryActivationPreviewService::class)->coverage($client);
        if (! $coverage) {
            return false;
        }
        $this->assertCancelable($r);
        $main = $this->assertAttached($r);
        $result = array_merge($r->activation_result_json ?? [], ['outcome' => 'existing_coverage', 'coverage_id' => $coverage->id, 'client_id' => $client->id, 'expires_at' => $coverage->expires_at?->toIso8601String()]);
        $r->update(['status' => 'canceled', 'active_identity' => null, 'activation_result_json' => $result, 'version' => $r->version + 1]);
        $main->update(['pending_subsidiary_trial' => array_merge($main->pending_subsidiary_trial, ['status' => 'already_active', 'subsidiary_client_id' => $client->id, 'coverage_id' => $coverage->id])]);
        app(TrialApprovalService::class)->event($r, User::find($r->authorized_actor_id), 'no_grant_existing_coverage', ['coverage_id' => $coverage->id]);

        return true;
    }

    public function prepare(FreeTrialRequest $request): FreeTrialRequest
    {
        $claim = DB::transaction(function () use ($request) {
            $main = Deal::lockForUpdate()->findOrFail($request->terms_json['source_deal_id']);
            Platform::whereKey($request->platform_id)->lockForUpdate()->firstOrFail();
            $r = FreeTrialRequest::lockForUpdate()->findOrFail($request->id);
            $this->assertAttached($r);
            if ($main->status !== 'active' || ($main->expires_at && $main->expires_at->isPast())) {
                return ['request' => $r];
            }
            abort_unless(! empty($r->terms_json['subsidiary_identity']), 409, 'Historical trial identity needs a fresh review.');
            app(TrialApprovalService::class)->assertDispatch($r);
            if ($r->client_id) {
                return ['request' => $r];
            }
            abort_if(in_array($r->activation_result_json['profile_resolution'] ?? '', ['resolving', 'unknown']), 409, 'Profile creation is being checked. Recover this request.');
            $readiness = app(SubsidiaryTrialService::class)->previewForDeal($main, Platform::findOrFail($r->platform_id));
            abort_if((bool) $readiness['config_errors'], 409, 'Target market trial configuration changed.');
            $seed = $r->terms_json['subsidiary_seed'];
            $id = (string) Str::uuid();
            $target = Platform::findOrFail($r->platform_id);
            $payload = app(SubsidiaryClientResolver::class)->frozenPayload($seed, $id);
            $hash = (new WpDirectProvisioningService($target))->payloadFingerprint($payload);
            $r->update(['status' => 'activating', 'version' => $r->version + 1, 'activation_result_json' => array_merge($r->activation_result_json ?? [],
                ['profile_resolution' => 'resolving', 'provision_request_id' => $id, 'payload_hash' => $hash])]);

            return ['request' => $r, 'seed' => $seed, 'id' => $id, 'target' => $target];
        });
        $r = $claim['request'];
        if (empty($claim['id'])) {
            return $r;
        }
        try {
            $resolved = app(SubsidiaryClientResolver::class)->resolveFrozen($claim['target'], $claim['seed'], $claim['id']);

            return $this->bind($r, $resolved);
        } catch (\Throwable $e) {
            $r->refresh();
            $r->update(['status' => 'uncertain', 'activation_result_json' => array_merge($r->activation_result_json ?? [], ['profile_resolution' => 'unknown']), 'version' => $r->version + 1]);

            return $r->fresh();
        }
    }

    public function bind(FreeTrialRequest $request, Client $resolved): FreeTrialRequest
    {
        return DB::transaction(function () use ($request, $resolved) {
            $main = Deal::with('client.platform')->lockForUpdate()->findOrFail($request->terms_json['source_deal_id']);
            $r = FreeTrialRequest::lockForUpdate()->findOrFail($request->id);
            $this->assertAttached($r);
            $helper = app(SubsidiaryActivationPreviewService::class);
            $identity = $r->terms_json['subsidiary_identity'];
            abort_unless($helper->same($r->terms_json['subsidiary_basis'] ?? [], $this->basis($r->terms_json)), 409, 'Approved package terms changed during profile resolution.');
            abort_unless($helper->same($identity['source'], $helper->contact($main->client)) && (int) $resolved->platform_id === (int) $r->platform_id
                && $resolved->wp_post_id > 0 && $resolved->wp_user_id > 0 && $resolved->phone_normalized === $identity['seed']['phone_normalized'], 409, 'Resolved identity conflicts with the approved customer.');
            $email = $helper->email($identity['seed']['email']);
            abort_if($email && $helper->email($resolved->email) && $email !== $helper->email($resolved->email), 409, 'Resolved email conflicts with the approved customer.');
            $resolution = $helper->resolve($main, Platform::findOrFail($r->platform_id));
            abort_unless($resolution['client_id'] === $resolved->id && ! in_array($resolution['kind'], ['ambiguous', 'unavailable']), 409, 'Profile resolution needs review.');
            $terms = $r->terms_json;
            $terms['client_ids'] = [$resolved->id];
            $terms['subsidiary_identity'] = $resolution['identity'];
            $r->update(['client_id' => $resolved->id, 'terms_json' => $terms, 'source_version' => app(TrialApprovalService::class)->source($terms),
                'activation_result_json' => array_merge($r->activation_result_json ?? [], ['profile_resolution' => 'resolved']), 'version' => $r->version + 1]);
            app(TrialApprovalService::class)->event($r, User::find($r->authorized_actor_id), 'subsidiary_identity_resolved', ['client_id' => $resolved->id]);

            return $r->fresh();
        });
    }

    public function recoverProfile(FreeTrialRequest $r): FreeTrialRequest
    {
        if (! in_array($r->activation_result_json['profile_resolution'] ?? '', ['resolving', 'unknown'])) {
            return $r;
        }
        try {
            DB::transaction(function () use ($r) {
                $this->assertAttached($r);
            });
            $target = Platform::findOrFail($r->platform_id);
            $meta = $r->activation_result_json;
            $record = app(SubsidiaryWpIdentityLookup::class)->provisionRecord($target, $meta['provision_request_id']);
            if (! $record || $record['status'] !== 'completed' || ! hash_equals($meta['payload_hash'], $record['payload_hash'])) {
                return $r;
            }
            $profile = WpSyncService::forPlatform($r->platform_id)->getClient((int) $record['wp_post_id']);
            $data = $profile['data'] ?? $profile;
            if ((int) ($data['wp_post_id'] ?? 0) !== (int) $record['wp_post_id'] || (int) ($data['wp_user_id'] ?? 0) !== (int) $record['wp_user_id']) {
                return $r;
            }
            $phone = \App\Support\PhoneNormalizer::normalize($data['phone'] ?? null, $target->phone_prefix);
            if ($phone !== $r->terms_json['subsidiary_seed']['phone_normalized'] || ! in_array($data['post_status'] ?? '', ['publish', 'private', 'draft', 'pending'])) {
                return $r;
            }
            $helper = app(SubsidiaryActivationPreviewService::class);
            $email = $helper->email($r->terms_json['subsidiary_seed']['email'] ?? '');
            if ($email && $helper->email($data['email'] ?? '') && $email !== $helper->email($data['email'])) {
                return $r;
            }
            DB::transaction(function () use ($r) {
                $this->assertAttached($r);
            });
            $resolved = (new ClientSyncService($target))->syncOne((int) $record['wp_post_id']);
            $r = $this->bind($r, $resolved);

            return DB::transaction(function () use ($r) {
                $this->assertAttached($r);
                $r = FreeTrialRequest::lockForUpdate()->findOrFail($r->id);
                try {
                    $r->status = 'activating'; // Validate the original authorization without dispatching from recovery.
                    app(TrialApprovalService::class)->assertDispatch($r);
                    $status = 'approved';
                } catch (\Throwable $e) {
                    $status = 'pending';
                }
                $r->fill(['status' => $status, 'version' => $r->version + 1]);
                if ($status === 'pending') {
                    $r->fill(['authorized_actor_id' => null, 'approved_via' => null, 'approver_id' => null, 'authorized_at' => null, 'expires_at' => null, 'pin_generation' => null]);
                }
                $r->save();
                app(TrialApprovalService::class)->event($r, null, 'profile_resolution_recovered', ['client_id' => $r->client_id], 'Identity recovered without granting a trial; fresh authorization may be required.');

                return $r->fresh();
            });
        } catch (\Throwable $e) {
            return $r->fresh();
        }
    }
}
