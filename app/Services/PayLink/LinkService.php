<?php

namespace App\Services\PayLink;

use App\Models\{Client, ClientPayLink};
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class LinkService
{
    public static function canonical(string $code): string
    {
        return strtr(strtoupper(trim($code)), ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    public function find(int $platform, string $code, bool $active = true): ?ClientPayLink
    {
        $code = self::canonical($code);
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{8}$/D', $code)) return null;
        return ClientPayLink::where('platform_id', $platform)->where('code_hash', hash('sha256', $code))
            ->when($active, fn ($q) => $q->where('status', 'active'))->first();
    }

    public function mint(Client $client, ?int $actor = null, string $reason = 'Created on first use'): ClientPayLink
    {
        abort_if(app(SettingsService::class)->forPlatform($client->platform)->rollout_mode === 'off', 409, 'Pay links are off in this market.');
        return DB::transaction(function () use ($client, $actor, $reason) {
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            if ($link = ClientPayLink::where('active_client_id', $client->id)->first()) return $link;
            $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
            do {
                $code = ''; foreach (unpack('C*', random_bytes(8)) as $b) $code .= $alphabet[$b & 31];
            } while (ClientPayLink::where('code_hash', hash('sha256', $code))->exists());
            $link = ClientPayLink::create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'active_client_id' => $client->id,
                'code_hash' => hash('sha256', $code), 'code_encrypted' => $code, 'status' => 'active', 'created_by_user_id' => $actor]);
            $this->audit($link, $actor, 'created', $reason);
            return $link;
        });
    }

    public function revoke(Client $client, int $actor, string $reason, bool $rotate = false): ?ClientPayLink
    {
        return DB::transaction(function () use ($client, $actor, $reason, $rotate) {
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            if ($link = ClientPayLink::where('active_client_id', $client->id)->first()) {
                $link->update(['status' => 'revoked', 'active_client_id' => null, 'revoked_at' => now(), 'revoked_by_user_id' => $actor, 'revoke_reason' => $reason]);
                $this->audit($link, $actor, $rotate ? 'rotated' : 'revoked', $reason);
            }
            return $rotate ? $this->mint($client, $actor, $reason) : null;
        });
    }

    private function audit(ClientPayLink $link, ?int $actor, string $action, string $reason): void
    {
        abort_unless(app(AuditService::class)->record(['platform_id' => $link->platform_id, 'entity_type' => 'client_pay_link', 'entity_id' => $link->id,
            'actor_id' => $actor, 'action' => $action, 'reason' => $reason, 'after_state' => ['client_id' => $link->client_id, 'status' => $link->status]]), 503, 'Could not record the link audit.');
    }
}
