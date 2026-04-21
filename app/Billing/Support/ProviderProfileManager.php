<?php

namespace App\Billing\Support;

use App\Billing\Contracts\BillingProviderRegistry as BillingProviderRegistryContract;
use App\Billing\Contracts\ProviderCredentialSchemaRegistry as ProviderCredentialSchemaRegistryContract;
use App\Models\BillingProviderProfile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ProviderProfileManager
{
    public function __construct(
        private readonly BillingProviderRegistryContract $providerRegistry,
        private readonly ProviderCredentialSchemaRegistryContract $schemaRegistry
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function maskedProfile(BillingProviderProfile $profile): array
    {
        $definition = $this->providerRegistry->find($profile->provider_type_key)?->definition();
        $schema = $this->schemaRegistry->find($profile->provider_type_key);

        $secrets = (array) ($profile->secrets_json ?? []);
        $secretState = [];

        foreach ($schema?->fields() ?? [] as $field) {
            if (!($field['sensitive'] ?? false)) {
                continue;
            }

            $key = (string) $field['key'];
            $secretState[$key] = !empty($secrets[$key]);
        }

        return [
            'id' => (int) $profile->id,
            'provider_type_key' => $profile->provider_type_key,
            'provider_label' => $definition?->label ?? $profile->provider_type_key,
            'provider_family' => $definition?->family->value,
            'provider_status' => $definition?->meta('status', 'active'),
            'profile_name' => $profile->profile_name,
            'country_code' => $profile->country_code,
            'market_id' => $profile->market_id,
            'merchant_scope_json' => $profile->merchant_scope_json ?? [],
            'environment' => $profile->environment,
            'config_json' => $profile->config_json ?? [],
            'secrets_json' => array_map(static fn () => '••••••••', $secrets),
            'secret_state' => $secretState,
            'active' => (bool) $profile->active,
            'tested_at' => optional($profile->tested_at)?->toISOString(),
            'created_at' => optional($profile->created_at)?->toISOString(),
            'updated_at' => optional($profile->updated_at)?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): BillingProviderProfile
    {
        [$attributes, $fieldsChanged] = $this->normalizePayload($payload, null);

        if ($fieldsChanged) {
            $attributes['tested_at'] = null;
        }

        return BillingProviderProfile::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(BillingProviderProfile $profile, array $payload): BillingProviderProfile
    {
        [$attributes, $fieldsChanged] = $this->normalizePayload($payload, $profile);

        if ($fieldsChanged) {
            $attributes['tested_at'] = null;
        }

        $profile->fill($attributes);
        $profile->save();

        return $profile->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function normalizePayload(array $payload, ?BillingProviderProfile $existing): array
    {
        $providerKey = strtolower(trim((string) ($payload['provider_type_key'] ?? $existing?->provider_type_key ?? '')));
        $schema = $this->schemaRegistry->find($providerKey);

        if (!$providerKey || !$schema || !$this->providerRegistry->has($providerKey)) {
            throw ValidationException::withMessages([
                'provider_type_key' => ['Select a supported billing provider.'],
            ]);
        }

        $environment = strtolower(trim((string) ($payload['environment'] ?? $existing?->environment ?? 'production')));

        if (!in_array($environment, $schema->supportedEnvironments(), true)) {
            throw ValidationException::withMessages([
                'environment' => ['The selected environment is not supported for this provider.'],
            ]);
        }

        $fieldPayload = array_merge(
            Arr::wrap($payload['fields'] ?? []),
            Arr::wrap($payload['config_json'] ?? []),
            Arr::wrap($payload['secrets_json'] ?? [])
        );

        $existingConfig = (array) ($existing?->config_json ?? []);
        $existingSecrets = (array) ($existing?->secrets_json ?? []);
        $config = $existingConfig;
        $secrets = $existingSecrets;
        $fieldErrors = [];
        $fieldsChanged = false;

        foreach ($schema->fields() as $field) {
            $fieldKey = (string) $field['key'];
            $sensitive = (bool) ($field['sensitive'] ?? false);
            $provided = array_key_exists($fieldKey, $fieldPayload);

            if (!$provided) {
                continue;
            }

            $normalized = $this->normalizeFieldValue($field, $fieldPayload[$fieldKey]);
            $target = $sensitive ? $secrets : $config;
            $previous = $target[$fieldKey] ?? null;

            if ($normalized === null || $normalized === '') {
                if ($sensitive) {
                    // Blank secret fields preserve existing values during edits.
                    if ($existing === null) {
                        unset($secrets[$fieldKey]);
                    }
                } else {
                    unset($config[$fieldKey]);
                }
            } else {
                if ($sensitive) {
                    $secrets[$fieldKey] = $normalized;
                } else {
                    $config[$fieldKey] = $normalized;
                }
            }

            $current = $sensitive ? ($secrets[$fieldKey] ?? null) : ($config[$fieldKey] ?? null);
            if ($current !== $previous) {
                $fieldsChanged = true;
            }
        }

        foreach ($schema->fields() as $field) {
            if (!($field['required'] ?? false)) {
                continue;
            }

            $fieldKey = (string) $field['key'];
            $value = ($field['sensitive'] ?? false)
                ? ($secrets[$fieldKey] ?? null)
                : ($config[$fieldKey] ?? null);

            if ($value === null || $value === '') {
                $fieldErrors["fields.{$fieldKey}"] = ["{$field['label']} is required."];
            }
        }

        if ($fieldErrors !== []) {
            throw ValidationException::withMessages($fieldErrors);
        }

        return [[
            'provider_type_key' => $providerKey,
            'profile_name' => trim((string) ($payload['profile_name'] ?? $existing?->profile_name ?? '')),
            'country_code' => $this->nullableUpperString($payload['country_code'] ?? $existing?->country_code),
            'market_id' => $payload['market_id'] ?? $existing?->market_id,
            'merchant_scope_json' => is_array($payload['merchant_scope_json'] ?? null)
                ? $payload['merchant_scope_json']
                : ($existing?->merchant_scope_json ?? []),
            'environment' => $environment,
            'config_json' => $config,
            'secrets_json' => $secrets,
            'active' => array_key_exists('active', $payload)
                ? (bool) $payload['active']
                : (bool) ($existing?->active ?? true),
        ], $fieldsChanged];
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function normalizeFieldValue(array $field, mixed $value): mixed
    {
        $serialize = $field['serialize'] ?? 'trim';

        return match ($serialize) {
            'raw' => is_string($value) ? trim($value) : $value,
            'trim_or_null' => ($trimmed = trim((string) $value)) === '' ? null : $trimmed,
            default => trim((string) $value),
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableUpperString(mixed $value): ?string
    {
        $trimmed = $this->nullableString($value);

        return $trimmed === null ? null : strtoupper($trimmed);
    }
}
