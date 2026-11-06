<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionDevice;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionEnrollmentService
{
    /** Staff-supplied verified mapping. New enrollments can only capture in shadow. */
    public function enroll(User $actor, array $input): array
    {
        app(MarketAuthorizationService::class)->ensureManager($actor);
        foreach ($input['platform_bindings'] as $binding) {
            app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, (int) $binding['platform_id']);
        }
        $identityMode = $input['sim_identity_mode'] ?? 'iccid_v1';
        $subscriptionId = filter_var($input['android_subscription_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if (empty($input['ownership_evidence_reference']) || empty($input['inbox_cursor']) || ! preg_match('/^[a-f0-9]{64}$/Di', $input['apk_sha256'] ?? '')
            || empty($input['apk_version']) || strlen($input['signing_secret'] ?? '') < 32
            || ! in_array($identityMode, ['iccid_v1', 'android_subscription_v1'], true)
            || $identityMode === 'iccid_v1' && empty($input['iccid'])
            || $identityMode === 'android_subscription_v1' && (! $subscriptionId || ($input['country'] ?? '') !== 'TZ'
                || ($input['filter_version'] ?? '') !== 'exotic-tz-v1' || ! app(CollectionPrivacy::class)->tzPhone($input['recipient'] ?? null))) {
            throw new CollectionException('verified_enrollment_evidence_required', 422);
        }

        return DB::transaction(function () use ($input, $actor, $identityMode, $subscriptionId) {
            $privacy = app(CollectionPrivacy::class);
            $account = CollectionAccount::create(['id' => (string) Str::uuid(), 'label' => $input['account_label'], 'country' => $input['country'], 'provider_key' => $input['provider_key'],
                'account_kind' => $input['account_kind'], 'account_identifier' => $input['account_identifier'], 'account_fingerprint' => $privacy->fingerprint($input['account_identifier']),
                'currency' => $input['currency'], 'timezone' => $input['timezone'], 'ownership' => $input['ownership'], 'custodian_id' => $actor->id,
                'mode' => 'shadow', 'verified_at' => now(), 'policy' => []]);
            foreach ($input['platform_bindings'] as $binding) {
                DB::table('collection_account_platforms')->insert(['account_id' => $account->id, 'platform_id' => $binding['platform_id'], 'manual_method_id' => $binding['manual_method_id'] ?? null,
                    'destination_snapshot' => json_encode($binding['destination_snapshot'], JSON_THROW_ON_ERROR), 'valid_from' => now()]);
            }
            $device = CollectionDevice::create(['id' => (string) Str::uuid(), 'label' => $input['device_label'], 'external_device_id' => $input['external_device_id'],
                'key_id' => (string) Str::uuid(), 'signing_secret' => $input['signing_secret'], 'cutover_at' => now(), 'inbox_cursor' => $input['inbox_cursor'],
                'acceptance' => ['apk_sha256' => $input['apk_sha256'], 'apk_version' => $input['apk_version'], 'enrolled_by' => $actor->id,
                    'filter_version' => $input['filter_version'] ?? null, 'sim_identity_mode' => $identityMode,
                    'android_subscription_ids' => $identityMode === 'android_subscription_v1' ? [(string) $input['sim_number'] => (int) $subscriptionId] : []]]);
            $recipient = $identityMode === 'android_subscription_v1' ? $privacy->tzPhone($input['recipient']) : preg_replace('/\D/', '', $input['recipient']);
            DB::table('collection_sim_bindings')->insert(['device_id' => $device->id, 'account_id' => $account->id, 'sim_number' => $input['sim_number'],
                'recipient_fingerprint' => $privacy->fingerprint($recipient), 'iccid_fingerprint' => $identityMode === 'iccid_v1' ? $privacy->fingerprint($input['iccid']) : null,
                'carrier_name' => $input['carrier_name'], 'valid_from' => now()]);
            DB::table('collection_audit')->insert(['account_id' => $account->id, 'actor_id' => $actor->id, 'action' => 'shadow_device_enrolled',
                'context' => json_encode(['device_id' => $device->id, 'ownership_evidence_reference' => $input['ownership_evidence_reference'], 'cutover_at' => $device->cutover_at->toIso8601String()]), 'created_at' => now()]);

            return ['account_id' => $account->id, 'device_id' => $device->id, 'mode' => 'shadow', 'cutover_at' => $device->cutover_at];
        });
    }
}
