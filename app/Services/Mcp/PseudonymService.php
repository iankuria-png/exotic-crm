<?php

namespace App\Services\Mcp;

use App\Models\Client;
use Illuminate\Support\Facades\Config;

class PseudonymService
{
    private const PREFIXES = ['client' => 'cli', 'user' => 'usr', 'lead' => 'lead', 'deal' => 'deal'];

    private function secret(): string
    {
        return hash_hmac('sha256', 'mcp-pseudonym-v1', (string) Config::get('app.key'));
    }

    public function handle(string $type, int $id): string
    {
        $prefix = self::PREFIXES[$type] ?? throw new \InvalidArgumentException($type);

        return $prefix.'_'.substr(hash_hmac('sha256', $type.':'.$id, $this->secret()), 0, 16);
    }

    public function resolveClient(string $handle): ?Client
    {
        if (! preg_match('/^cli_[a-f0-9]{16}$/', $handle)) {
            return null;
        }

        foreach (Client::query()->select(['id', 'platform_id', 'city', 'region', 'lifecycle_state', 'profile_status', 'created_at'])->cursor() as $client) {
            if (hash_equals($this->handle('client', (int) $client->id), $handle)) {
                return $client;
            }
        }

        return null;
    }
}
