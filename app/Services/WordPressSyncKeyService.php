<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class WordPressSyncKeyService
{
    public const SETTINGS_KEY = 'wp_shared_key';

    public function currentRaw(): ?string
    {
        $stored = IntegrationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->value('value');

        if (!is_array($stored) || empty($stored['cipher'])) {
            return null;
        }

        try {
            $value = (string) Crypt::decryptString($stored['cipher']);
        } catch (DecryptException) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function status(): array
    {
        $envKey = trim((string) config('services.exotic_crm_sync.shared_key'));
        $dbKey = $this->currentRaw();

        $stored = IntegrationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->first();

        $rotatedAt = null;
        $updatedBy = null;
        if ($stored && is_array($stored->value)) {
            $rotatedAt = $stored->value['rotated_at'] ?? null;
            $updatedBy = $stored->updated_by;
        }

        return [
            'db_key_set' => $dbKey !== null,
            'db_key_preview' => $dbKey !== null ? $this->mask($dbKey) : null,
            'env_key_set' => $envKey !== '',
            'env_key_preview' => $envKey !== '' ? $this->mask($envKey) : null,
            'active_source' => $dbKey !== null ? 'database' : ($envKey !== '' ? 'env' : 'none'),
            'rotated_at' => $rotatedAt,
            'updated_by' => $updatedBy,
        ];
    }

    public function rotate(?int $userId = null): array
    {
        $plain = $this->generateKey();

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            [
                'value' => [
                    'cipher' => Crypt::encryptString($plain),
                    'rotated_at' => now()->toIso8601String(),
                ],
                'updated_by' => $userId,
            ]
        );

        return [
            'plain' => $plain,
            'status' => $this->status(),
        ];
    }

    public function clear(?int $userId = null): array
    {
        IntegrationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->delete();

        return $this->status();
    }

    private function generateKey(): string
    {
        return Str::random(64);
    }

    private function mask(string $value): string
    {
        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('•', max(0, $length - 2)) . substr($value, -2);
        }

        return substr($value, 0, 4) . str_repeat('•', max(4, $length - 8)) . substr($value, -4);
    }
}
