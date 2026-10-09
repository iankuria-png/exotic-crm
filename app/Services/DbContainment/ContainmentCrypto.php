<?php

namespace App\Services\DbContainment;

use Illuminate\Encryption\Encrypter;

class ContainmentCrypto
{
    private function key(?string $version = null): string
    {
        $version ??= (string) config('db_containment.key_version');
        $raw = $version === (string) config('db_containment.key_version') ? config('db_containment.key') : config('db_containment.retained_keys.'.$version);
        $key = is_string($raw) && str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : $raw;
        if (! is_string($key) || strlen($key) !== 32) {
            throw new ContainmentException('independent_vault_key_not_provisioned', 503);
        }

        return $key;
    }

    public function digest(mixed $data, string $purpose = 'preview'): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), hash_hmac('sha256', $purpose, $this->key(), true));
    }

    public function seal(array $data): string
    {
        $version = (string) config('db_containment.key_version');

        return json_encode(['version' => $version, 'payload' => (new Encrypter($this->key(), 'AES-256-CBC'))->encryptString(json_encode($data, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
    }

    public function open(string $sealed): array
    {
        try {
            $envelope = json_decode($sealed, true, 512, JSON_THROW_ON_ERROR);

            return json_decode((new Encrypter($this->key($envelope['version']), 'AES-256-CBC'))->decryptString($envelope['payload']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ContainmentException('private_manifest_authentication_failed');
        }
    }

    public function keyProvenance(array $identity, int $user, int $meta, string $name, string $uuid): string
    {
        return $this->digest([$identity, $user, $meta, $name, $uuid], 'application-password-provenance');
    }
}
