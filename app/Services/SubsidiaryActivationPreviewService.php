<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

/** Read-only identity resolution. No profile creation or CRM synchronization on preview. */
class SubsidiaryActivationPreviewService
{
    public function same(array $left, array $right): bool
    {
        $normalize = function (array $value) use (&$normalize): array {
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }
            unset($item);
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };

        return json_encode($normalize($left), JSON_THROW_ON_ERROR) === json_encode($normalize($right), JSON_THROW_ON_ERROR);
    }

    public function contact(Client $client): array
    {
        return $client->only(['id', 'platform_id', 'wp_post_id', 'wp_user_id', 'name', 'phone_normalized', 'email', 'city', 'profile_removal_requested_at', 'profile_reverted_at']);
    }

    public function email(?string $email): string
    {
        $email = mb_strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) && ! preg_match('/onboard|placeholder|example\.|@crm\.|@noemail\.|@invalid\./i', $email) ? $email : '';
    }

    public function seed(Deal $main, Platform $target): array
    {
        $main->loadMissing('client.platform');

        return ['name' => $main->client->name, 'phone_normalized' => app(SubsidiaryClientResolver::class)->targetPhone($main->client, $target), 'email' => $this->email($main->client->email), 'city' => $main->client->city];
    }

    public function coverage(Client $client): ?Deal
    {
        return Deal::where('platform_id', $client->platform_id)->where('client_id', $client->id)->where('status', 'active')
            ->where(fn ($q) => $q->where('expires_at', '>', now())->orWhereNull('expires_at'))->orderByDesc('id')->first();
    }

    public function resolve(Deal $main, Platform $target, ?int $selected = null): array
    {
        $seed = $this->seed($main, $target);
        $phone = $seed['phone_normalized'];
        $email = $this->email($seed['email']);
        $candidates = Client::where('platform_id', $target->id)->where(function ($q) use ($phone, $email, $selected) {
            if ($phone) {
                $q->orWhere('phone_normalized', $phone);
            }
            if ($email) {
                $q->orWhereRaw('LOWER(email) = ?', [$email]);
            }
            if ($selected) {
                $q->orWhere('id', $selected);
            }
            if (! $phone && ! $email && ! $selected) {
                $q->whereRaw('1=0');
            }
        })->orderBy('id')->get();
        $client = $selected ? $candidates->firstWhere('id', $selected) : ($candidates->count() === 1 ? $candidates->first() : null);
        abort_if($selected && ! $client, 422, 'The selected profile does not belong to this market.');
        $ambiguous = ! $selected && ($candidates->count() > 1 || ($client && $phone && $client->phone_normalized !== $phone));
        if ($client && ! $selected && $email && $this->email($client->email) && $email !== $this->email($client->email)) {
            $ambiguous = true;
        }
        $removed = $client && ($client->profile_removal_requested_at || $client->profile_reverted_at || ! $client->wp_post_id || ! $client->wp_user_id
            || app(SubsidiaryProfileCopyService::class)->incompleteTarget($client)); // An unfinished copied profile is never a ready existing match.
        $coverage = $client ? $this->coverage($client) : null;
        $kind = $removed ? 'unavailable' : ($ambiguous ? 'ambiguous' : ($client ? ($coverage ? 'already_active' : 'existing') : ($phone ? 'create_if_needed' : 'unavailable')));
        $candidateData = $candidates->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone_normalized' => $c->phone_normalized, 'city' => $c->city, 'profile_status' => $c->profile_status])->all();
        $identity = ['source' => $this->contact($main->client), 'target' => ! $ambiguous && $client ? $this->contact($client) : null,
            'candidate_digest' => hash('sha256', json_encode($candidates->map(fn ($c) => $this->contact($c))->all())), 'seed' => $seed];

        return ['kind' => $kind, 'client_id' => ! $ambiguous ? $client?->id : null, 'name' => $client?->name ?? $seed['name'], 'candidates' => $candidateData,
            'coverage_id' => $coverage?->id, 'expires_at' => $coverage?->expires_at?->toIso8601String(), 'identity' => $identity, 'manual' => (bool) $selected];
    }

    public function issue(User $actor, Deal $main, array $preview, ?int $selected, int $days): ?string
    {
        if (! in_array($preview['resolution']['kind'], ['existing', 'create_if_needed', 'already_active']) || $preview['config_errors']) {
            return null;
        }
        if ($preview['resolution']['kind'] !== 'already_active' && (! empty($preview['duration']['error']) || ! in_array($this->copyStatus($preview), ['off', 'ready'], true))) {
            return null; // The period or the profile copy needs a choice before this can be included.
        }

        return Crypt::encryptString(json_encode(['actor' => $actor->id, 'deal' => $main->id, 'platform' => $preview['target_platform']['id'],
            'selected' => $selected, 'days' => $days, 'product' => $preview['target_platform']['trial_product_id'],
            'identity' => $preview['resolution']['identity'], 'terms' => $this->frozen($preview), 'expires' => now()->addMinutes(config('subsidiary_trials.preview_minutes', 15))->timestamp], JSON_THROW_ON_ERROR));
    }

    /** Period and copy facts the token binds: mode, policy, purchased package, snapshot and chosen location. */
    public function frozen(array $preview): array
    {
        $d = $preview['duration'] ?? [];
        $c = $preview['copy_preview'] ?? [];

        return ['mode' => $d['mode'] ?? null, 'policy' => $d['policy_version'] ?? null, 'purchased' => $d['purchased_days'] ?? null,
            'copy' => $this->copyStatus($preview), 'digest' => $c['snapshot_digest'] ?? null,
            'location' => isset($c['location']) ? [$c['location']['region_id'] ?? null, $c['location']['city_id'] ?? null] : null, 'currency' => $c['currency']['id'] ?? null];
    }

    private function copyStatus(array $preview): string
    {
        return (string) ($preview['copy_preview']['status'] ?? 'off');
    }

    public function validate(User $actor, Deal $main, array $input, array $preview): void
    {
        if (empty($input['preview_token'])) {
            return;
        } // Existing explicit-confirmation API clients remain supported.
        try {
            $token = json_decode(Crypt::decryptString($input['preview_token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            abort(409, 'Refresh the trial preview before activating.');
        }
        abort_unless($token['actor'] === $actor->id && $token['deal'] === $main->id && $token['platform'] === (int) $input['platform_id']
            && $token['days'] === (int) $input['duration_days'] && $token['product'] === $preview['target_platform']['trial_product_id']
            && $token['identity'] === $preview['resolution']['identity'] && ($token['terms'] ?? null) === $this->frozen($preview)
            && $token['expires'] > now()->timestamp, 409, 'Trial details changed. Refresh the preview before activating.');
    }

    public function currentIdentity(array $terms): array
    {
        $main = Deal::with('client.platform')->findOrFail($terms['source_deal_id']);
        $identity = $terms['subsidiary_identity'] ?? null;
        if (! $identity) {
            return [];
        }
        $resolution = $this->resolve($main, Platform::findOrFail($terms['platform_id']), ! empty($terms['subsidiary_manual']) ? ($terms['client_ids'][0] ?? null) : null);

        return $resolution['identity'];
    }
}
