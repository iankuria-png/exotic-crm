<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionDevice;
use App\Models\Collections\CollectionEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CollectionDeviceHealthService
{
    public function forActor(User $actor, ?int $platformId = null): array
    {
        $accountIds = app(CollectionAccessService::class)->accountIds($actor);
        $allAllowed = $accountIds;
        if ($platformId !== null) {
            app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $platformId);
            $accountIds = array_values(array_intersect($accountIds, DB::table('collection_account_platforms')
                ->where('platform_id', $platformId)->pluck('account_id')->all()));
        }
        $bindings = DB::table('collection_sim_bindings')->whereNull('valid_until')->whereIn('account_id', $accountIds)->get();
        // A phone shared with an inaccessible account must not disclose its identity or health.
        $devices = CollectionDevice::whereIn('id', $bindings->pluck('device_id'))->whereNotExists(fn ($q) => $q->selectRaw('1')
            ->from('collection_sim_bindings as b')->whereColumn('b.device_id', 'collection_devices.id')
            ->whereNull('b.valid_until')->whereNotIn('b.account_id', $allAllowed))->orderBy('label')->get();
        $accounts = CollectionAccount::whereIn('id', $accountIds)->get()->keyBy('id');
        $rows = $devices->map(function ($device) use ($bindings, $accounts) {
            $linked = $bindings->where('device_id', $device->id)->pluck('account_id')->unique();
            $health = $this->inspect($device);
            if ($linked->contains(fn ($id) => $accounts->get($id)?->suspended_at)) {
                $health['state'] = 'account_review';
                $health['capture_ready'] = false;
            }

            return ['id' => $device->id, 'label' => $device->label, 'account_labels' => $linked->map(fn ($id) => $accounts->get($id)?->label)->filter()->values()->all(),
                ...$health];
        })->all();
        foreach ($accounts as $account) {
            if (! $bindings->contains('account_id', $account->id)) {
                $rows[] = ['id' => null, 'label' => $account->label, 'account_labels' => [$account->label],
                    'state' => 'not_enrolled', 'capture_ready' => false, 'last_contact_at' => null, 'last_check_in_at' => null];
            }
        }

        return ['devices' => $rows, 'needs_attention' => count(array_filter($rows, fn ($row) => ! $row['capture_ready'])),
            'checked_at' => now()->toIso8601String(), 'stale_after_seconds' => $this->freshnessSeconds()];
    }

    public function accountReady(string $accountId): bool
    {
        $devices = CollectionDevice::whereIn('id', DB::table('collection_sim_bindings')->where('account_id', $accountId)
            ->whereNull('valid_until')->where('valid_from', '<=', now())->select('device_id'))->get();

        return $devices->contains(fn ($device) => $this->inspect($device)['capture_ready']);
    }

    public function inspect(CollectionDevice $device): array
    {
        $ping = CollectionEvent::where('device_id', $device->id)->where('event_kind', 'system:ping')
            ->where('source_kind', 'live')->orderByDesc('id')->first();
        $result = ['capture_ready' => false, 'last_contact_at' => $device->last_seen_at?->toIso8601String(),
            'last_check_in_at' => $ping?->ingested_at?->toIso8601String()];
        $state = 'awaiting_check_in';
        if ($device->revoked_at) {
            $state = 'revoked';
        } elseif (! $device->last_seen_at || $device->last_seen_at->lt(now()->subSeconds($this->freshnessSeconds()))) {
            $state = $device->last_seen_at ? 'no_recent_contact' : 'awaiting_check_in';
        } elseif (! $device->cutover_at || ! $device->inbox_cursor) {
            $state = 'not_enrolled';
        } elseif ($ping && $ping->state === 'heartbeat' && $ping->ingested_at->lte(now()->addSeconds(30)) && $ping->ingested_at->gte(now()->subSeconds($this->freshnessSeconds()))
            && $ping->ingested_at->gte($device->cutover_at)) {
            $capture = data_get($ping->normalized, 'capture');
            $pinnedFilter = data_get($device->acceptance, 'filter_version');
            $state = match (true) {
                ! $this->freshCaptureObservation($device, $ping->normalized) => 'capture_unverified',
                ($capture['running'] ?? null) !== true => 'app_stopped',
                ($capture['smsPermission'] ?? null) !== true => 'sms_permission_needed',
                ! is_string($pinnedFilter) || $pinnedFilter === '' || ($capture['filterVersion'] ?? null) !== $pinnedFilter => 'filter_review',
                ($capture['simVerified'] ?? null) !== true || ! $this->measuredBindingsMatch($device, $capture) => 'sim_review',
                ($capture['formatDrift'] ?? false) !== false => 'format_review',
                default => 'ready',
            };
        } else {
            $state = 'capture_unverified';
        }

        return [...$result, 'state' => $state, 'capture_ready' => $state === 'ready'];
    }

    public function freshnessSeconds(): int
    {
        return max(60, min(900, (int) config('collection.device_stale_seconds', 300)));
    }

    public function freshCaptureObservation(CollectionDevice $device, array $payload): bool
    {
        $observed = $this->observationTime(data_get($payload, 'capture.observedAt'));

        return $observed && $device->cutover_at && $observed->gte($device->cutover_at)
            && $observed->gte(now()->subSeconds($this->freshnessSeconds())) && $observed->lte(now()->addSeconds(30));
    }

    private function measuredBindingsMatch(CollectionDevice $device, array $capture): bool
    {
        $identity = app(CollectionSimIdentityService::class);
        if ($identity->mode($device) === 'iccid_v1') {
            return true;
        }
        if ($identity->mode($device) !== 'android_subscription_v1' || ! is_array($capture['simBindings'] ?? null)) {
            return false;
        }
        $bindings = DB::table('collection_sim_bindings')->where('device_id', $device->id)->whereNull('valid_until')->get();
        if ($bindings->isEmpty()) {
            return false;
        }
        foreach ($bindings as $binding) {
            $cards = collect($capture['simBindings'])->where('simNumber', $binding->sim_number);
            if ($cards->count() !== 1 || ! is_array($cards->first()) || ! $identity->inventoryMatches($device, $binding, $cards->first())) {
                return false;
            }
        }

        return true;
    }

    private function observationTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
