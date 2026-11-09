<?php

namespace App\Services;

use App\Exceptions\SubsidiaryCopyHeldException as CopyHeld;
use App\Jobs\ProcessSubsidiaryProfileCopy;
use App\Models\Client;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\Platform;
use App\Models\SubsidiaryProfileCopyItem;
use App\Models\SubsidiaryProfileCopyRun;
use App\Models\User;
use App\Services\Trials\TrialActivationService;
use App\Services\Trials\TrialApprovalService;
use App\Support\CrossPlatformPhoneResolver;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One-time copy of a primary-market public profile into a newly created private
 * paired-market profile. The snapshot is frozen at authorization; every target
 * effect goes through the destination's per-item ledger, and the paired period
 * is granted only after the completed target is verified.
 */
class SubsidiaryProfileCopyService
{
    public const CONTRACT = 1;

    private const HELD = ['approval_expired', 'authorization_changed', 'cancelled', 'source_removed', 'primary_inactive', 'target_changed', 'target_not_owned', 'item_needs_review', 'item_unconfirmed', 'verification_failed', 'engine_unavailable'];

    /** Snapshots computed during this request, keyed by digest, until a run is registered. */
    private static array $snapshots = [];

    public function enabled(): bool
    {
        return (bool) config('subsidiary_trials.profile_copy_enabled') && Schema::hasTable('subsidiary_profile_copy_runs');
    }

    public function tagged(FreeTrialRequest $r): bool
    {
        return (int) ($r->terms_json['copy_contract_version'] ?? 0) > 0;
    }

    public function run(FreeTrialRequest $r): ?SubsidiaryProfileCopyRun
    {
        if (! Schema::hasTable('subsidiary_profile_copy_runs')) {
            return null;
        }

        return SubsidiaryProfileCopyRun::where('request_id', $r->id)->first();
    }

    // ---------------------------------------------------------------- preview

    /**
     * Read-only. Loads the source snapshot and target capabilities; returns the
     * categories, counts, mapped location and digest shown before authorization.
     */
    public function preview(Deal $main, Platform $target, array $options = []): array
    {
        $policy = app(SubsidiaryPromotionPolicyService::class)->policy((int) $main->platform_id, (int) $target->id);
        if (! $policy['paired'] || ! $policy['copy_new_profile']) {
            return ['status' => 'off'];
        }
        if (! $this->enabled()) {
            return ['status' => 'off'];
        }
        $source = $main->client;
        $sourcePlatform = $source?->platform;
        if (! $source || ! $sourcePlatform || ! $source->wp_post_id) {
            return ['status' => 'unavailable', 'reason' => 'source_unlinked', 'message' => 'The primary profile is not linked to its website.'];
        }
        if ($source->profile_removal_requested_at || $source->profile_reverted_at) {
            return ['status' => 'unavailable', 'reason' => 'source_removed', 'message' => 'The primary profile was removed and cannot be copied.'];
        }
        try {
            $caps = $this->capabilities($target);
            $sourceCaps = $this->capabilities($sourcePlatform);
            if ((int) ($caps['contract'] ?? 0) !== self::CONTRACT || empty($caps['import']) || (int) ($sourceCaps['contract'] ?? 0) !== self::CONTRACT) {
                return ['status' => 'unavailable', 'reason' => 'engine_unavailable', 'message' => 'Profile copying is not available on both websites yet.'];
            }
            $export = WpSyncService::forPlatform($sourcePlatform->id)->profileCopyExport((int) $source->wp_post_id);
            $locations = WpSyncService::forPlatform($target->id)->getLocations()['locations'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Subsidiary profile copy preview unavailable.', ['deal_id' => $main->id, 'target' => $target->id, 'error' => $e->getMessage()]);

            return ['status' => 'unavailable', 'reason' => 'site_unavailable', 'message' => 'The profile details could not be loaded. Try again, or continue with the primary subscription only.'];
        }
        if ((int) ($export['post']['id'] ?? 0) !== (int) $source->wp_post_id || (int) ($export['post']['author_id'] ?? 0) !== (int) $source->wp_user_id) {
            return ['status' => 'unavailable', 'reason' => 'source_identity', 'message' => 'The primary website profile does not match this customer.'];
        }

        $blockers = [];
        $media = array_values($export['media'] ?? []);
        $photos = array_values(array_filter($media, fn ($m) => ($m['kind'] ?? '') === 'photo'));
        $videos = array_values(array_filter($media, fn ($m) => ($m['kind'] ?? '') === 'video'));
        $limits = $caps['limits'] ?? [];
        if (count($photos) > (int) ($limits['max_images'] ?? 0) || count($videos) > (int) ($limits['max_videos'] ?? 0)) {
            $blockers[] = 'media_limit';
        }
        foreach ($media as $item) {
            $max = ($item['kind'] ?? '') === 'video' ? (int) ($limits['max_video_bytes'] ?? 0) : (int) ($limits['max_image_bytes'] ?? 0);
            if ((int) ($item['size'] ?? 0) > $max || ! in_array($item['mime_type'] ?? '', (array) ($limits['mime_types'] ?? []), true)) {
                $blockers[] = 'media_format';
                break;
            }
        }
        if (! empty($export['unsupported_bio_keys'])) {
            $blockers[] = 'unsupported_bio';
        }
        $location = $this->mapLocation($export['location'] ?? [], $locations, $options);
        $currency = $this->mapCurrency($export['currency'] ?? null, $caps['currencies'] ?? [], $options);
        $snapshot = $this->snapshot($main, $target, $export, $location, $currency);
        $digest = $this->digest($snapshot);
        self::$snapshots[$digest] = $snapshot;
        $status = $blockers ? 'blocked' : (in_array('needs_selection', [$location['status'], $currency['status']], true) ? 'needs_selection' : 'ready');

        return ['status' => $status, 'blockers' => $blockers, 'message' => $this->blockerMessage($blockers),
            'source' => ['name' => $source->name, 'market' => $sourcePlatform->name, 'status' => (string) ($export['post']['status'] ?? '')],
            'categories' => array_values(array_filter(['details' => trim((string) ($export['post']['title'] ?? '')) !== '' ? 'Profile details' : null,
                'bio' => trim(strip_tags((string) ($export['post']['content'] ?? ''))) !== '' ? 'Bio' : null,
                'contacts' => array_intersect(array_keys($export['fields'] ?? []), ['whatsapp', 'instagram', 'twitter', 'telegram', 'website', 'facebook', 'snapchat']) ? 'Contacts and social links' : null,
                'rates' => preg_grep('/^rate/', array_keys($export['fields'] ?? [])) ? 'Rates' : null])),
            'photos' => count($photos), 'videos' => count($videos), 'main_included' => (bool) ($export['main_image_id'] ?? null),
            'location' => $location, 'currency' => $currency, 'snapshot_digest' => $digest];
    }

    public function capabilities(Platform $platform): array
    {
        return Cache::remember('subsidiary-copy-caps:'.$platform->id.':'.md5((string) $platform->wp_api_url), now()->addMinutes(5),
            fn () => WpSyncService::forPlatform($platform->id)->profileCopyCapabilities());
    }

    private function mapLocation(array $source, array $tree, array $options): array
    {
        $label = fn (?array $t) => $t ? $t['name'] : null;
        $sourceLabel = implode(' → ', array_filter([$label($source['region'] ?? null), $label($source['city'] ?? null)]));
        $out = ['source' => $sourceLabel ?: null, 'region_id' => null, 'city_id' => null, 'label' => null];
        if (! empty($options['copy_region_id'])) {
            $region = collect($tree)->firstWhere('id', (int) $options['copy_region_id']);
            $city = $region && ! empty($options['copy_city_id']) ? collect($region['cities'] ?? [])->firstWhere('id', (int) $options['copy_city_id']) : null;
            abort_unless($region && (empty($options['copy_city_id']) || $city), 422, 'Choose a location from the paired market.');

            return array_merge($out, ['status' => 'selected', 'region_id' => (int) $region['id'], 'city_id' => $city ? (int) $city['id'] : null,
                'label' => implode(' → ', array_filter([$region['name'], $city['name'] ?? null]))]);
        }
        if (empty($source['region'])) {
            return array_merge($out, ['status' => 'none']);
        }
        $find = function (array $items, array $wanted) {
            $bySlug = array_values(array_filter($items, fn ($i) => strtolower((string) $i['slug']) === strtolower((string) $wanted['slug'])));
            if (count($bySlug) === 1) {
                return $bySlug[0];
            }
            $byName = array_values(array_filter($items, fn ($i) => mb_strtolower(trim((string) $i['name'])) === mb_strtolower(trim((string) $wanted['name']))));

            return count($byName) === 1 ? $byName[0] : null;
        };
        $region = $find($tree, $source['region']);
        $city = $region && ! empty($source['city']) ? $find($region['cities'] ?? [], $source['city']) : null;
        if ($region && (empty($source['city']) || $city)) {
            return array_merge($out, ['status' => 'mapped', 'region_id' => (int) $region['id'], 'city_id' => $city ? (int) $city['id'] : null,
                'label' => implode(' → ', array_filter([$region['name'], $city['name'] ?? null]))]);
        }

        return array_merge($out, ['status' => 'needs_selection', 'suggested_region_id' => $region['id'] ?? null,
            'options' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'cities' => array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $r['cities'] ?? [])], $tree)]);
    }

    private function mapCurrency(?array $source, array $targets, array $options): array
    {
        if (! $source) {
            return ['status' => 'none', 'id' => null, 'code' => null];
        }
        if (! empty($options['copy_currency_id'])) {
            $row = collect($targets)->firstWhere('id', (int) $options['copy_currency_id']);
            abort_unless($row, 422, 'Choose a currency the paired market supports.');

            return ['status' => 'selected', 'id' => (int) $row['id'], 'code' => $row['code'], 'source' => $source['code']];
        }
        $row = collect($targets)->first(fn ($c) => strcasecmp((string) $c['code'], (string) $source['code']) === 0);

        return $row ? ['status' => 'mapped', 'id' => (int) $row['id'], 'code' => $row['code'], 'source' => $source['code']]
            : ['status' => 'needs_selection', 'id' => null, 'code' => null, 'source' => $source['code'], 'options' => $targets];
    }

    private function snapshot(Deal $main, Platform $target, array $export, array $location, array $currency): array
    {
        $source = $main->client;
        $fields = (array) ($export['fields'] ?? []);
        if (! empty($fields['whatsapp'])) {
            $fields['whatsapp'] = $this->targetPhone((string) $fields['whatsapp'], $source, $target);
        }
        $import = array_filter(['name' => (string) ($export['post']['title'] ?? ''), 'content' => (string) ($export['post']['content'] ?? ''),
            'excerpt' => (string) ($export['post']['excerpt'] ?? '')], fn ($v) => $v !== '') + $fields;
        if ($location['region_id']) {
            $import['region_id'] = $location['region_id'];
            if ($location['city_id']) {
                $import['city_id'] = $location['city_id'];
            }
        }
        if ($currency['id']) {
            $import['currency'] = (string) $currency['id'];
        }
        $media = [];
        foreach (array_values($export['media'] ?? []) as $index => $item) {
            $media[] = ['source_attachment_id' => (int) $item['id'], 'sha256' => strtolower((string) $item['sha256']), 'mime_type' => (string) $item['mime_type'],
                'kind' => (string) $item['kind'], 'size' => (int) $item['size'], 'menu_order' => $index + 1,
                'is_main' => (int) ($export['main_image_id'] ?? 0) === (int) $item['id'] && $item['kind'] === 'photo', 'alt_text' => (string) ($item['alt_text'] ?? '')];
        }

        return ['contract' => self::CONTRACT, 'source' => ['platform_id' => (int) $source->platform_id, 'client_id' => (int) $source->id, 'wp_post_id' => (int) $source->wp_post_id, 'wp_user_id' => (int) $source->wp_user_id],
            'target_platform_id' => (int) $target->id, 'fields' => $import, 'tags' => array_values($export['tags'] ?? []), 'location' => $location['status'] === 'none' ? null : ['region_id' => $location['region_id'], 'city_id' => $location['city_id']],
            'media' => $media];
    }

    private function targetPhone(string $value, Client $source, Platform $target): string
    {
        $prefix = (string) ($source->platform?->phone_prefix ?: '');
        $normalized = PhoneNormalizer::normalize($value, $prefix);
        if (! $normalized) {
            return $value;
        }

        return (string) (app(CrossPlatformPhoneResolver::class)->resolve($normalized, $prefix, (string) ($target->phone_prefix ?: $prefix)) ?: $value);
    }

    private function blockerMessage(array $blockers): ?string
    {
        return match ($blockers[0] ?? null) {
            null => null,
            'media_limit' => 'This profile has more photos or videos than the paired website allows.',
            'media_format' => 'A photo or video is too large or in a format the paired website does not accept.',
            'unsupported_bio' => 'This profile has bio text the copy cannot carry yet. Review it before including the paired market.',
            default => 'This profile needs review before copying.',
        };
    }

    public function digest(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonical($snapshot), JSON_THROW_ON_ERROR));
    }

    /** Mirrors the destination's canonical JSON so command hashes agree. */
    public function canonical($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->canonical($item);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }

    // ------------------------------------------------------- authorization

    /** Frozen copy terms; the snapshot itself is kept on the run registered with the request. */
    public function terms(array $copyPreview, array $seed, string $requestKey): array
    {
        abort_unless(($copyPreview['status'] ?? '') === 'ready', 422, $copyPreview['message'] ?? 'Choose the paired-market location before activating.');

        return ['copy_contract_version' => self::CONTRACT, 'copy_run_id' => $this->runId($requestKey), 'copy_snapshot_digest' => $copyPreview['snapshot_digest'],
            'copy_expected_target_identity' => ['phone_normalized' => $seed['phone_normalized'] ?? null, 'email' => $seed['email'] ?? null],
            'copy_location' => array_intersect_key($copyPreview['location'], array_flip(['region_id', 'city_id', 'label'])),
            'copy_currency_id' => $copyPreview['currency']['id'] ?? null];
    }

    public function runId(string $requestKey): string
    {
        return \Ramsey\Uuid\Uuid::uuid5('6f1f8e2a-6f0b-4d5b-9a7e-2f1d3a9c0b11', 'subsidiary-copy:'.$requestKey)->toString();
    }

    /** Called inside the request-creation transaction. */
    public function register(FreeTrialRequest $r): SubsidiaryProfileCopyRun
    {
        $terms = $r->terms_json;
        $snapshot = self::$snapshots[$terms['copy_snapshot_digest']] ?? null;
        abort_unless($snapshot && $this->digest($snapshot) === $terms['copy_snapshot_digest'], 409, 'The profile changed. Refresh the paired-market preview.');
        $existing = SubsidiaryProfileCopyRun::find($terms['copy_run_id']);
        if ($existing) {
            abort_unless((int) $existing->request_id === (int) $r->id, 409, 'This copy run belongs to another request.');

            return $existing;
        }
        $run = SubsidiaryProfileCopyRun::create(['id' => $terms['copy_run_id'], 'request_id' => $r->id, 'source_platform_id' => $snapshot['source']['platform_id'],
            'source_client_id' => $snapshot['source']['client_id'], 'source_wp_post_id' => $snapshot['source']['wp_post_id'], 'target_platform_id' => $r->platform_id,
            'snapshot_json' => $snapshot, 'snapshot_digest' => $terms['copy_snapshot_digest'], 'media_manifest_json' => $snapshot['media'],
            'duration_mode' => $terms['promotion_duration_mode'] ?? 'legacy', 'duration_days' => (int) $terms['duration_days'], 'policy_version' => (int) ($terms['promotion_policy_version'] ?? 0),
            'state' => 'queued', 'progress_json' => null]);
        $rows = [['item_key' => 'fields', 'kind' => 'fields', 'position' => 0]];
        foreach ($snapshot['media'] as $m) {
            $rows[] = ['item_key' => 'media-'.$m['source_attachment_id'], 'kind' => $m['kind'] === 'video' ? 'video' : 'photo', 'position' => (int) $m['menu_order'],
                'source_attachment_id' => $m['source_attachment_id'], 'source_sha256' => $m['sha256']];
        }
        foreach ($rows as $row) {
            SubsidiaryProfileCopyItem::create($row + ['run_id' => $run->id, 'state' => 'prepared', 'destination_operation_key' => (string) Str::uuid()]);
        }
        app(TrialApprovalService::class)->event($r, User::find($r->requester_id), 'profile_copy_registered', ['run_id' => $run->id, 'photos' => count(array_filter($snapshot['media'], fn ($m) => $m['kind'] === 'photo')), 'videos' => count(array_filter($snapshot['media'], fn ($m) => $m['kind'] === 'video'))]);

        return $run;
    }

    // ---------------------------------------------------------- activation

    /**
     * Gate before the paired grant. 'skip' for authoritative existing matches,
     * 'ready' once the verified copy is complete, otherwise schedules work and
     * returns 'pending'. Tagged requests never fall through to a grant early.
     */
    public function gate(FreeTrialRequest $r): string
    {
        $run = $this->run($r);
        abort_unless($run, 409, 'This paired-market setup has no saved copy run. Review the request.');
        if (in_array($run->state, ['ready', 'skipped'], true)) {
            return $run->state === 'ready' ? 'ready' : 'skip';
        }
        abort_if($run->state === 'cancelled', 409, 'This paired-market setup was cancelled.');
        $origin = $r->activation_result_json['profile_origin'] ?? null;
        if ($origin === 'existing') {
            $run->update(['state' => 'skipped', 'version' => $run->version + 1, 'error_code' => null]);
            app(TrialApprovalService::class)->event($r, null, 'profile_copy_skipped_existing_profile', ['run_id' => $run->id, 'client_id' => $r->client_id]);

            return 'skip';
        }
        abort_unless($origin === 'created', 409, 'The paired profile origin is not proven. Review the request.');
        abort_unless($this->enabled(), 409, 'Profile copying is turned off. The paired profile stays private until it is resumed.');
        if (! $run->target_wp_post_id) {
            $this->bindTarget($run, $r);
        }
        $run->refresh();
        if ($run->state === 'queued') {
            $run->update(['state' => 'copying', 'version' => $run->version + 1]);
        }
        if (in_array($run->state, SubsidiaryProfileCopyRun::ACTIVE, true)) {
            ProcessSubsidiaryProfileCopy::dispatch($run->id);
        }

        return 'pending';
    }

    private function bindTarget(SubsidiaryProfileCopyRun $run, FreeTrialRequest $r): void
    {
        $client = Client::findOrFail($r->client_id);
        $meta = $r->activation_result_json ?? [];
        abort_unless(! empty($meta['provision_request_id']) && ! empty($meta['payload_hash']) && (int) $client->platform_id === (int) $run->target_platform_id, 409, 'The paired profile was not created by this request.');
        try {
            $bound = WpSyncService::forPlatform($run->target_platform_id)->bindProfileCopy((int) $client->wp_post_id, $run->id,
                ['provision_request_id' => $meta['provision_request_id'], 'payload_hash' => $meta['payload_hash'], 'wp_user_id' => (int) $client->wp_user_id]);
        } catch (\Throwable $e) {
            $this->hold($run, $this->httpStatus($e) === 403 ? 'target_not_owned' : 'engine_unavailable');
            throw new HttpException(409, 'The paired profile could not be prepared for copying. Check the saved setup.');
        }
        abort_unless((int) ($bound['post_id'] ?? 0) === (int) $client->wp_post_id && (int) ($bound['user_id'] ?? 0) === (int) $client->wp_user_id && ($bound['post_status'] ?? '') === 'private', 409, 'The paired profile is not the expected private profile.');
        $run->update(['target_client_id' => $client->id, 'target_wp_post_id' => $client->wp_post_id, 'target_wp_user_id' => $client->wp_user_id,
            'provision_request_id' => $meta['provision_request_id'], 'provision_payload_hash' => $meta['payload_hash'], 'target_baseline_digest' => $bound['baseline_digest'], 'version' => $run->version + 1]);
        app(TrialApprovalService::class)->event($r, null, 'profile_copy_target_bound', ['run_id' => $run->id, 'client_id' => $client->id]);
    }

    /** One bounded worker slice. Safe to run concurrently: a database lease fences writers. */
    public function process(string $runId): void
    {
        $owner = (string) Str::uuid();
        $claimed = SubsidiaryProfileCopyRun::whereKey($runId)->whereIn('state', ['copying', 'verifying'])
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->update(['lease_owner' => $owner, 'lease_until' => now()->addSeconds(150), 'fence' => DB::raw('fence + 1')]);
        if (! $claimed) {
            return;
        }
        $run = SubsidiaryProfileCopyRun::findOrFail($runId);
        $more = false;
        $delay = false;
        $grant = false;
        try {
            $this->assertLive($run);
            $deadline = microtime(true) + (int) config('subsidiary_trials.profile_copy_slice_seconds', 30);
            foreach ($run->items()->where('state', '!=', 'completed')->get() as $item) {
                if (microtime(true) > $deadline) {
                    $more = true;
                    break;
                }
                $this->assertLease($run, $owner);
                $this->processItem($run->fresh(), $item);
                $this->progress($run);
                if ($run->fresh()->state === 'needs_review') {
                    return;
                }
            }
            if (! $more && $run->items()->where('state', '!=', 'completed')->doesntExist()) {
                $this->assertLease($run, $owner);
                try {
                    $grant = $this->verify($run->fresh());
                } catch (CopyHeld $held) {
                    throw $held;
                } catch (\Throwable $e) {
                    throw new CopyHeld('engine_unavailable');
                }
            } elseif (! $more) {
                $more = $run->fresh()->state === 'copying';
                $delay = true; // An unconfirmed step is retried after a pause, never in a hot loop.
            }
        } catch (CopyHeld $held) {
            $this->hold($run, $held->getMessage());
        } finally {
            SubsidiaryProfileCopyRun::whereKey($runId)->where('lease_owner', $owner)->update(['lease_owner' => null, 'lease_until' => null]);
        }
        if ($grant) {
            $this->grant($run->fresh());
        } elseif ($more && $run->fresh()->state === 'copying') {
            $delay ? ProcessSubsidiaryProfileCopy::dispatch($runId)->delay(now()->addSeconds(30)) : ProcessSubsidiaryProfileCopy::dispatch($runId);
        }
    }

    private function assertLease(SubsidiaryProfileCopyRun $run, string $owner): void
    {
        if (! SubsidiaryProfileCopyRun::whereKey($run->id)->where('lease_owner', $owner)->where('lease_until', '>', now())->exists()) {
            throw new CopyHeld('item_unconfirmed');
        }
    }

    /** Reasserts authorization, access, primary coverage and pinned identity before writes. */
    private function assertLive(SubsidiaryProfileCopyRun $run): void
    {
        $code = DB::transaction(function () use ($run) {
            $r = FreeTrialRequest::lockForUpdate()->findOrFail($run->request_id);
            $fresh = SubsidiaryProfileCopyRun::lockForUpdate()->findOrFail($run->id);
            if ($fresh->cancellation_requested_at || in_array($r->status, ['canceled', 'denied', 'expired'], true)) {
                return 'cancelled';
            }
            $source = Client::find($fresh->source_client_id);
            if (! $source || $source->profile_removal_requested_at || $source->profile_reverted_at) {
                return 'source_removed';
            }
            $target = Client::find($fresh->target_client_id);
            if (! $target || (int) $target->wp_post_id !== (int) $fresh->target_wp_post_id || (int) $target->wp_user_id !== (int) $fresh->target_wp_user_id) {
                return 'target_not_owned';
            }
            try {
                app(TrialApprovalService::class)->assertDispatch($r);
            } catch (HttpException $e) {
                return str_contains(strtolower($e->getMessage()), 'expired') ? 'approval_expired' : (str_contains(strtolower($e->getMessage()), 'primary') ? 'primary_inactive' : 'authorization_changed');
            }

            return null;
        });
        if ($code) {
            throw new CopyHeld($code);
        }
    }

    private function processItem(SubsidiaryProfileCopyRun $run, SubsidiaryProfileCopyItem $item): void
    {
        $site = WpSyncService::forPlatform($run->target_platform_id);
        $post = (int) $run->target_wp_post_id;
        [$command, $hash] = $this->command($run, $item);
        $sendFile = true;
        if ($item->attempts > 0 || $item->state !== 'prepared') {
            $remote = $site->profileCopyItemStatus($post, $run->id, $item->item_key);
            if (($remote['state'] ?? '') === 'completed') {
                $this->complete($item, $remote);

                return;
            }
            if (($remote['state'] ?? '') === 'uncertain') {
                $item->update(['state' => 'uncertain', 'error_code' => 'destination_needs_review']);
                throw new CopyHeld('item_needs_review');
            }
            if (! empty($remote['command_hash']) && ! hash_equals((string) $remote['command_hash'], $hash)) {
                $item->update(['state' => 'uncertain', 'error_code' => 'command_conflict']);
                throw new CopyHeld('item_needs_review');
            }
            $sendFile = in_array($remote['state'] ?? 'absent', ['absent', 'claimed'], true);
        }
        $item->update(['state' => 'dispatching', 'payload_hash' => $hash, 'attempts' => $item->attempts + 1, 'error_code' => null]);
        $temp = null;
        try {
            if ($item->kind !== 'fields' && $sendFile) {
                $temp = $this->download($run, $item);
            }
            $response = $site->profileCopyItem($post, $run->id, $item->item_key, $command, $hash, (int) $run->fresh()->fence, $temp, $item->kind === 'fields' ? null : $this->manifest($run, $item)['mime_type']);
            if (($response['state'] ?? '') !== 'completed') {
                $item->update(['state' => 'uncertain', 'error_code' => 'destination_'.($response['state'] ?? 'unknown')]);
                throw new CopyHeld('item_needs_review');
            }
            $this->complete($item, $response);
        } catch (CopyHeld $held) {
            throw $held;
        } catch (\Throwable $e) {
            $status = $this->httpStatus($e);
            if ($status === 409 && str_contains($e->getMessage(), 'target_changed')) {
                $item->update(['state' => 'uncertain', 'error_code' => 'target_changed']);
                throw new CopyHeld('target_changed');
            }
            if (in_array($status, [403, 409, 413, 422], true) && ! str_contains($e->getMessage(), 'item_in_flight') && ! str_contains($e->getMessage(), 'stale_fence')) {
                $item->update(['state' => 'uncertain', 'error_code' => 'destination_rejected_'.$status]);
                throw new CopyHeld('item_needs_review');
            }
            $item->update(['error_code' => 'unconfirmed']);
            if ($item->fresh()->attempts >= 3) {
                $item->update(['state' => 'uncertain']);
                throw new CopyHeld('item_unconfirmed');
            }
        } finally {
            if ($temp) {
                @unlink($temp);
            }
        }
    }

    /** @return array{0:array,1:string} */
    public function command(SubsidiaryProfileCopyRun $run, SubsidiaryProfileCopyItem $item): array
    {
        $snapshot = $run->snapshot_json;
        if ($item->kind === 'fields') {
            $command = ['expected_before' => (string) $run->target_baseline_digest, 'fields' => $snapshot['fields'], 'tags' => $snapshot['tags'] ?? []];
        } else {
            $m = $this->manifest($run, $item);
            $command = ['source_attachment_id' => $m['source_attachment_id'], 'sha256' => $m['sha256'], 'mime_type' => $m['mime_type'], 'menu_order' => $m['menu_order'], 'is_main' => (bool) $m['is_main'], 'alt_text' => $m['alt_text']];
        }

        return [$command, hash('sha256', json_encode($this->canonical($command), JSON_THROW_ON_ERROR))];
    }

    private function manifest(SubsidiaryProfileCopyRun $run, SubsidiaryProfileCopyItem $item): array
    {
        foreach ($run->media_manifest_json as $m) {
            if ((int) $m['source_attachment_id'] === (int) $item->source_attachment_id) {
                return $m;
            }
        }
        throw new CopyHeld('item_needs_review');
    }

    /** Downloads only the frozen manifest's file through the authenticated source export, and checks its bytes. */
    private function download(SubsidiaryProfileCopyRun $run, SubsidiaryProfileCopyItem $item): string
    {
        $dir = storage_path('app/subsidiary-copy');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $path = $dir.'/'.$run->id.'-'.$item->item_key.'-'.Str::random(8);
        WpSyncService::forPlatform($run->source_platform_id)->downloadProfileCopyMedia((int) $run->source_wp_post_id, (int) $item->source_attachment_id, $path, (int) config('subsidiary_trials.profile_copy_max_download_bytes'));
        if (! is_file($path) || ! hash_equals((string) $item->source_sha256, (string) hash_file('sha256', $path))) {
            @unlink($path);
            $item->update(['state' => 'uncertain', 'error_code' => 'source_changed']);
            throw new CopyHeld('item_needs_review');
        }

        return $path;
    }

    private function complete(SubsidiaryProfileCopyItem $item, array $remote): void
    {
        $item->update(['state' => 'completed', 'target_attachment_id' => $remote['attachment_id'] ?? null, 'result_json' => $remote['result'] ?? null, 'completed_at' => now(), 'error_code' => null]);
    }

    /** Field-by-field and file-by-file comparison of the target before any grant. */
    private function verify(SubsidiaryProfileCopyRun $run): bool
    {
        $run->update(['state' => 'verifying']);
        $site = WpSyncService::forPlatform($run->target_platform_id);
        $status = $site->profileCopyStatus((int) $run->target_wp_post_id, $run->id);
        $items = $run->items()->get()->keyBy('item_key');
        $fields = $items['fields'] ?? null;
        $ok = ($status['state'] ?? '') === 'bound' && ($status['post_status'] ?? '') === 'private' && (int) ($status['post_id'] ?? 0) === (int) $run->target_wp_post_id
            && $fields && hash_equals((string) ($fields->result_json['fields_digest'] ?? ''), (string) ($status['fields_digest'] ?? ''));
        $location = $run->snapshot_json['location'] ?? null;
        if ($ok && $location) {
            $ok = in_array((int) ($location['city_id'] ?: $location['region_id']), array_map('intval', $status['location_term_ids'] ?? []), true);
        }
        $attachments = collect($status['attachments'] ?? [])->keyBy('id');
        $expectedMain = 0;
        $order = [];
        foreach ($run->media_manifest_json as $m) {
            $item = $items['media-'.$m['source_attachment_id']] ?? null;
            $target = $item ? $attachments->get((int) $item->target_attachment_id) : null;
            if (! $target || ! hash_equals($m['sha256'], (string) ($target['sha256'] ?? '')) || ($target['copy_item'] ?? '') !== $run->id.':'.$item->item_key) {
                $ok = false;
                break;
            }
            $order[] = [(int) $target['menu_order'], (int) $target['id']];
            if ($m['is_main']) {
                $expectedMain = (int) $target['id'];
            }
        }
        $sorted = $order;
        sort($sorted);
        $ok = $ok && $sorted === $order && (! $expectedMain || (int) ($status['main_image_id'] ?? 0) === $expectedMain);
        if (! $ok) {
            $this->hold($run, 'verification_failed');

            return false;
        }
        $site->setProfileCopyState((int) $run->target_wp_post_id, $run->id, 'complete');
        $run->update(['state' => 'ready', 'verified_at' => now(), 'error_code' => null, 'version' => $run->version + 1]);
        $this->progress($run);
        app(TrialApprovalService::class)->event(FreeTrialRequest::findOrFail($run->request_id), null, 'profile_copy_verified', ['run_id' => $run->id]);

        return true;
    }

    private function grant(SubsidiaryProfileCopyRun $run): void
    {
        $request = FreeTrialRequest::find($run->request_id);
        if (! $request || ! in_array($request->status, ['approved', 'activating'], true)) {
            return;
        }
        try {
            app(TrialActivationService::class)->execute($request);
        } catch (\Throwable $e) {
            Log::warning('Verified profile copy is waiting for its paired activation.', ['run_id' => $run->id, 'request_id' => $request->id, 'error' => $e->getMessage()]);
        }
    }

    private function hold(SubsidiaryProfileCopyRun $run, string $code): void
    {
        $code = in_array($code, self::HELD, true) ? $code : 'item_needs_review';
        $state = $code === 'cancelled' ? 'cancelled' : 'needs_review';
        SubsidiaryProfileCopyRun::whereKey($run->id)->whereNotIn('state', ['ready', 'skipped', 'cancelled'])->update(['state' => $state, 'error_code' => $code, 'version' => DB::raw('version + 1')]);
        $this->progress($run);
    }

    public function progress(SubsidiaryProfileCopyRun $run): array
    {
        $items = $run->items()->get();
        $count = fn (string $kind, ?string $state = null) => $items->where('kind', $kind)->when($state, fn ($c) => $c->where('state', $state))->count();
        $progress = ['fields' => $items->firstWhere('kind', 'fields')?->state === 'completed' ? 'completed' : 'pending',
            'photos_total' => $count('photo'), 'photos_done' => $count('photo', 'completed'), 'videos_total' => $count('video'), 'videos_done' => $count('video', 'completed'),
            'location' => ! empty($run->snapshot_json['location'])];
        SubsidiaryProfileCopyRun::whereKey($run->id)->update(['progress_json' => json_encode($progress)]);

        return $progress;
    }

    // ------------------------------------------------- cancel, expiry, recovery

    public function assertCancelable(FreeTrialRequest $r): void
    {
        $run = $this->run($r);
        if (! $run || in_array($run->state, ['skipped', 'cancelled'], true)) {
            return;
        }
        abort_if($run->state === 'ready', 409, 'This profile setup is complete. Review the paired activation instead of cancelling it.');
        abort_if($run->lease_until && $run->lease_until->isFuture(), 409, 'Profile setup is running. Try again in a minute.');
        abort_if($run->items()->whereIn('state', ['dispatching', 'uncertain'])->exists(), 409, 'Profile setup has an unconfirmed step. Check and resume it before cancelling.');
    }

    /** Inside the cancelling transaction; the destination marker follows after commit. */
    public function cancelled(FreeTrialRequest $r): void
    {
        $run = $this->run($r);
        if (! $run || in_array($run->state, ['skipped', 'cancelled', 'ready'], true)) {
            return;
        }
        $run->update(['state' => 'cancelled', 'cancellation_requested_at' => now(), 'fence' => $run->fence + 1, 'version' => $run->version + 1, 'error_code' => 'cancelled']);
        if ($run->target_wp_post_id) {
            DB::afterCommit(function () use ($run) {
                try {
                    WpSyncService::forPlatform($run->target_platform_id)->setProfileCopyState((int) $run->target_wp_post_id, $run->id, 'abandoned');
                } catch (\Throwable $e) {
                    Log::warning('Abandoned copy marker could not be written; the CRM marker still blocks activation.', ['run_id' => $run->id]);
                }
            });
        }
    }

    /** Legacy approval sweep: a bound copy keeps its reservation and moves to review instead of expiring. */
    public function holdsReservation(FreeTrialRequest $r): bool
    {
        $run = $this->run($r);
        if (! $run || ! $run->target_wp_post_id || in_array($run->state, ['skipped', 'cancelled'], true)) {
            return false;
        }
        if ($run->state !== 'ready') {
            $this->hold($run, 'approval_expired');
        }

        return true;
    }

    /** Any entitlement path refuses a target whose one-time copy is unfinished. */
    public static function assertTargetReady(int $platformId, int $postId): void
    {
        if ($postId <= 0 || ! Schema::hasTable('subsidiary_profile_copy_runs')) {
            return;
        }
        $blocked = SubsidiaryProfileCopyRun::where('target_platform_id', $platformId)->where('target_wp_post_id', $postId)
            ->whereNotIn('state', ['ready', 'skipped'])->exists();
        abort_if($blocked, 409, 'This profile’s setup copy is incomplete. Review its saved setup before activating it.');
    }

    /** Resolver/preview check: an unfinished copied target is not a ready existing profile. */
    public function incompleteTarget(Client $client): bool
    {
        return Schema::hasTable('subsidiary_profile_copy_runs') && $client->wp_post_id
            && SubsidiaryProfileCopyRun::where('target_platform_id', $client->platform_id)->where('target_wp_post_id', $client->wp_post_id)->whereNotIn('state', ['ready', 'skipped'])->exists();
    }

    /**
     * Explicit resume. GET classifies unconfirmed items first; an admin may finish
     * already-allocated items even after approval expiry. New work needs live approval.
     */
    public function resume(SubsidiaryProfileCopyRun $run, User $actor, int $version, string $reason): array
    {
        $r = FreeTrialRequest::findOrFail($run->request_id);
        $approvals = app(TrialApprovalService::class);
        $approvals->access($actor, (int) $run->source_platform_id);
        $approvals->access($actor, (int) $run->target_platform_id);
        abort_unless($actor->role === 'admin' || (int) $r->requester_id === (int) $actor->id, 403);
        abort_unless($run->version === $version, 409, 'Profile setup changed. Reload it before resuming.');
        abort_unless(in_array($run->state, ['needs_review', 'copying', 'verifying'], true), 409, 'This profile setup cannot be resumed.');
        abort_if($run->lease_until && $run->lease_until->isFuture(), 409, 'Profile setup is running. Try again in a minute.');
        abort_unless((int) $r->terms_json['source_deal_id'] && Deal::find($r->terms_json['source_deal_id'])?->status === 'active', 422, 'The primary subscription must be active before setup can continue.');
        $unconfirmed = $run->items()->whereIn('state', ['dispatching', 'uncertain'])->get();
        abort_if($unconfirmed->isNotEmpty() && $actor->role !== 'admin', 403, 'An admin must check the unconfirmed setup step.');
        $live = true;
        try {
            DB::transaction(fn () => $approvals->assertDispatch(FreeTrialRequest::lockForUpdate()->findOrFail($r->id)));
        } catch (HttpException $e) {
            $live = false;
        }
        $site = WpSyncService::forPlatform($run->target_platform_id);
        foreach ($unconfirmed as $item) {
            $remote = $site->profileCopyItemStatus((int) $run->target_wp_post_id, $run->id, $item->item_key);
            [$command, $hash] = $this->command($run, $item);
            $state = (string) ($remote['state'] ?? 'absent');
            if ($state === 'completed') {
                $this->complete($item, $remote);
            } elseif ($state === 'absent' || $state === 'claimed') {
                $item->update(['state' => 'prepared', 'attempts' => 0, 'error_code' => null]); // Proven not applied.
            } elseif (in_array($state, ['staged', 'attachment_reserved', 'file_finalized'], true) && hash_equals((string) ($remote['command_hash'] ?? ''), $hash)) {
                // Bounded recovery: finish only this already-allocated item, without new bytes.
                $response = $site->profileCopyItem((int) $run->target_wp_post_id, $run->id, $item->item_key, $command, $hash, (int) $run->fence + 1);
                abort_unless(($response['state'] ?? '') === 'completed', 409, 'A setup step still needs review.');
                $this->complete($item, $response);
            } else {
                abort(409, 'A setup step needs manual review on the website before it can continue.');
            }
        }
        $approvals->event($r, $actor, 'profile_copy_resume_requested', ['run_id' => $run->id, 'reconciled' => $unconfirmed->count(), 'authorization_live' => $live], $reason);
        $this->progress($run);
        if (! $live) {
            $run->update(['state' => 'needs_review', 'error_code' => 'approval_expired', 'version' => $run->version + 1]);

            return ['run' => $this->projection($run->fresh(), $actor), 'needs_reauthorization' => true];
        }
        $run->update(['state' => 'copying', 'error_code' => null, 'version' => $run->version + 1]);
        ProcessSubsidiaryProfileCopy::dispatch($run->id);

        return ['run' => $this->projection($run->fresh(), $actor), 'needs_reauthorization' => false];
    }

    /** Read-only summary projection. */
    public function projection(?SubsidiaryProfileCopyRun $run, User $actor): ?array
    {
        if (! $run || $run->state === 'skipped') {
            return null;
        }
        $r = FreeTrialRequest::find($run->request_id);
        $progress = $run->progress_json ?: ['fields' => 'pending', 'photos_total' => 0, 'photos_done' => 0, 'videos_total' => 0, 'videos_done' => 0, 'location' => false];
        $canAct = $actor->role === 'admin' || (int) $r?->requester_id === (int) $actor->id;

        return ['run_id' => $run->id, 'run_version' => $run->version, 'state' => $run->state, 'held_reason' => $run->error_code, 'progress' => $progress,
            'location' => $run->snapshot_json['location'] ? ($r?->terms_json['copy_location']['label'] ?? null) : null,
            'duration_days' => $run->duration_days, 'duration_mode' => $run->duration_mode,
            'can_resume' => $canAct && $run->state === 'needs_review' && $run->error_code !== 'approval_expired' && ! in_array($run->error_code, ['source_removed', 'cancelled'], true),
            'needs_reauthorization' => $canAct && $run->state === 'needs_review' && $run->error_code === 'approval_expired',
            'request_version' => $r?->version];
    }

    private function httpStatus(\Throwable $e): ?int
    {
        return $e instanceof RequestException ? $e->response->status() : ($e instanceof HttpException ? $e->getStatusCode() : null);
    }
}
