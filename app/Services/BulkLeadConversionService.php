<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BulkLeadConversionService
{
    public const LIMIT = 100;

    public function __construct(
        private readonly MarketAuthorizationService $authorization,
        private readonly LeadConversionService $conversion
    ) {}

    public function preview(Request $request, array $ids, array $placements = []): array
    {
        $leads = Lead::with(['platform', 'assignedAgent'])->findMany($ids)->keyBy('id');
        if ($leads->count() !== count($ids)) {
            throw ValidationException::withMessages(['lead_ids' => 'Some selected leads no longer exist. Refresh the list.']);
        }
        foreach ($leads as $lead) {
            $this->authorize($request, $lead);
        }

        $expires = now()->addMinutes(30);
        $placements = $this->resolvePlacements($leads, $placements);
        $rows = collect($ids)->map(fn ($id) => $this->inspect($leads[$id], $placements[$id] ?? null));
        // Block every repeated contact, rather than choosing a winner by row order.
        $contacts = [];
        foreach ($rows as $row) {
            foreach (['phone_normalized', 'email'] as $key) {
                if ($value = $row['payload'][$key]) {
                    $contacts[$row['platform_id'].':'.$key.':'.$value][] = $row['id'];
                }
            }
        }
        $repeated = collect($contacts)->filter(fn ($group) => count($group) > 1)->flatten()->unique();
        $rows = $rows->map(function ($row) use ($request, $expires, $repeated, $placements) {
            if ($row['action'] === 'create' && $repeated->contains($row['id'])) {
                $row['action'] = 'needs_attention';
                $row['message'] = 'Shares a contact with another selected lead. Select one of these leads and review again.';
            }
            if ($row['action'] === 'create') {
                $row['review_token'] = Crypt::encryptString(json_encode([
                    'actor_id' => (int) $request->user()->id,
                    'lead_id' => $row['id'],
                    'fingerprint' => $row['fingerprint'],
                    'placement' => $placements[$row['id']] ?? null,
                    'expires' => $expires->timestamp,
                ], JSON_THROW_ON_ERROR));
            }
            unset($row['fingerprint'], $row['payload']);

            return $row;
        });

        return [
            'rows' => $rows->values()->all(),
            'expires_at' => $expires->toIso8601String(),
            'limit' => self::LIMIT,
            'summary' => $rows->countBy('action')->all(),
        ];
    }

    public function execute(Request $request, Lead $lead, string $token, ?string $reason): array
    {
        $this->authorize($request, $lead);
        try {
            $review = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['review_token' => 'This review is invalid. Review the selection again.']);
        }
        abort_unless(is_array($review) && ($review['actor_id'] ?? null) === (int) $request->user()->id, 403, 'This review belongs to another staff member.');
        abort_unless(($review['lead_id'] ?? null) === (int) $lead->id, 422, 'This review is for a different lead.');
        abort_if(($review['expires'] ?? 0) <= now()->timestamp, 422, 'This review has expired. Review the selection again.');

        return $this->conversion->withLockedLead($lead, function (Lead $locked) use ($request, $review, $reason) {
            $this->authorize($request, $locked);
            $row = $this->inspect($locked, is_array($review['placement'] ?? null) ? $review['placement'] : null);
            if ($row['action'] === 'already_converted') {
                return ['status' => 'already_converted', 'client_id' => $row['client_id'], 'message' => 'Already converted. No new profile was created.'];
            }
            abort_unless($row['action'] === 'create' && hash_equals($row['fingerprint'], (string) ($review['fingerprint'] ?? '')), 409,
                'This lead or its client match changed. Review the selection again.');

            $result = $this->conversion->convert($request, $locked, [
                ...$row['payload'],
                'reason' => $reason ?: 'Bulk lead conversion from leads page',
            ]);
            abort_if(! empty($result['duplicate']), 409, 'An existing client now matches this lead. Review it before continuing.');

            return ['status' => 'created', 'client_id' => (int) $result['client']->id, 'city' => $row['payload']['city'],
                'message' => 'Client created'.($row['payload']['city'] ? ' in '.$row['payload']['city'] : '').'. Profile is private.'];
        });
    }

    private function authorize(Request $request, Lead $lead): void
    {
        $this->authorization->ensureUserCanAccessPlatform($request->user(), (int) $lead->platform_id, 'You do not have access to a selected lead market.');
    }

    /**
     * Validate requested WordPress placements against each lead market's location catalogue.
     *
     * @param  \Illuminate\Support\Collection<int, Lead>  $leads
     * @return array<int, array{region_id: int, city_id: ?int, city: string}>
     */
    private function resolvePlacements($leads, array $requested): array
    {
        $resolved = [];
        $catalogues = [];
        foreach ($requested as $leadId => $placement) {
            $lead = $leads[(int) $leadId] ?? null;
            if (! $lead || ! is_array($placement)) {
                throw ValidationException::withMessages(['placements' => 'A placement refers to a lead that is not selected.']);
            }
            $platformId = (int) $lead->platform_id;
            try {
                $catalogues[$platformId] ??= \App\Services\WpSyncService::forPlatform($platformId)->getLocations();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['placements' => 'WordPress locations are unavailable for '.($lead->platform?->name ?: 'this market').'. Try again shortly.']);
            }
            $regions = $catalogues[$platformId]['locations'] ?? $catalogues[$platformId];
            $region = collect($regions)->first(fn ($r) => (int) ($r['id'] ?? 0) === (int) $placement['region_id']);
            $cities = (array) ($region['cities'] ?? []);
            $cityId = isset($placement['city_id']) ? (int) $placement['city_id'] : null;
            $city = $cityId ? collect($cities)->first(fn ($c) => (int) ($c['id'] ?? 0) === $cityId) : null;
            if (! $region || ($cityId && ! $city) || (! $cityId && $cities !== [])) {
                throw ValidationException::withMessages(['placements' => 'Choose a valid WordPress city for '.($lead->name ?: 'each lead').'.']);
            }
            $resolved[(int) $leadId] = ['region_id' => (int) $region['id'], 'city_id' => $cityId, 'city' => trim((string) ($city['name'] ?? $region['name']))];
        }

        return $resolved;
    }

    private function inspect(Lead $lead, ?array $placement = null): array
    {
        $platform = $lead->platform;
        $phone = PhoneNormalizer::normalize($lead->phone_normalized, (string) ($platform?->phone_prefix ?: '254'));
        $email = strtolower(trim((string) $lead->email)) ?: null;
        $payload = ['name' => trim((string) $lead->name), 'phone_normalized' => $phone, 'email' => $email, 'city' => $placement['city'] ?? null, 'profile_status' => 'private', 'assigned_to' => $lead->assigned_to];
        if ($placement) {
            $payload['region_id'] = (int) $placement['region_id'];
            $payload['city_id'] = $placement['city_id'] !== null ? (int) $placement['city_id'] : null;
        }
        $row = [
            'id' => (int) $lead->id,
            'name' => $lead->name ?: 'Unnamed lead',
            'platform_id' => (int) $lead->platform_id,
            'market' => $platform?->name ?: 'Unavailable market',
            'owner' => $lead->assignedAgent?->name ?: 'Unassigned',
            'contact' => $phone ?: ($email ?: 'No contact details'),
            'place' => $placement['city'] ?? null,
            'action' => 'create',
            'message' => 'Create private profile',
            'payload' => $payload,
            'fingerprint' => hash('sha256', json_encode([
                $lead->only(['id', 'platform_id', 'name', 'phone_normalized', 'email', 'assigned_to', 'status', 'archived_at', 'converted_client_id', 'wp_post_id', 'wp_user_id', 'updated_at']),
                $platform?->only(['domain', 'db_host', 'db_name', 'db_user', 'db_prefix', 'wp_api_url']),
                $payload,
            ], JSON_THROW_ON_ERROR)),
        ];
        if ($lead->archived_at) {
            return [...$row, 'action' => 'skipped', 'message' => 'Archived lead. Restore or review it individually.'];
        }
        if ($lead->converted_client_id) {
            $client = Client::find($lead->converted_client_id);
            if (! $client || (int) $client->platform_id !== (int) $lead->platform_id) {
                return [...$row, 'action' => 'needs_attention', 'message' => 'The linked client is missing or belongs to a different market.'];
            }

            return [...$row, 'action' => $lead->status === 'converted' ? 'already_converted' : 'existing_match', 'client_id' => (int) $client->id, 'message' => $lead->status === 'converted' ? 'Already converted' : 'Linked client exists. Use Reconcile selected.'];
        }
        if ($lead->status === 'converted' || $lead->wp_post_id || $lead->wp_user_id) {
            return [...$row, 'action' => 'needs_attention', 'message' => 'WordPress or conversion history exists. Review this lead individually.'];
        }
        if (! $phone && ! $email) {
            return [...$row, 'action' => 'needs_attention', 'message' => 'A valid phone number or email is needed.'];
        }
        if (($lead->phone_normalized && ! $phone) || Validator::make($payload, ['name' => 'required|string|max:255', 'email' => 'nullable|email|max:255'])->fails()) {
            return [...$row, 'action' => 'needs_attention', 'message' => 'Check the lead name, phone number and email before converting.'];
        }
        $matches = Client::where('platform_id', $lead->platform_id)->where(function ($query) use ($phone, $email) {
            if ($phone) {
                $query->where('phone_normalized', $phone);
            }
            if ($email) {
                $query->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
            }
        })->limit(2)->get(['id']);
        if ($matches->isNotEmpty()) {
            return [...$row, 'action' => 'existing_match', 'client_id' => $matches->count() === 1 ? (int) $matches->first()->id : null,
                'message' => $matches->count() === 1 ? 'Existing client matches. Use Reconcile selected.' : 'More than one client matches. Review the identity individually.'];
        }
        if (! $platform || ! $platform->db_host || ! $platform->db_name || ! $platform->db_user || ! $platform->db_pass) {
            return [...$row, 'action' => 'needs_attention', 'message' => 'This market is not configured for profile creation.'];
        }

        return $row;
    }
}
