<?php

namespace App\Billing\Providers\Schemas;

use App\Billing\Contracts\ProviderCredentialSchema;

abstract class AbstractProviderCredentialSchema implements ProviderCredentialSchema
{
    final public function providerKey(): string
    {
        return $this->key();
    }

    final public function label(): string
    {
        return $this->providerLabel();
    }

    final public function fields(): array
    {
        return $this->fieldDefinitions();
    }

    final public function supportedEnvironments(): array
    {
        return $this->environments();
    }

    abstract protected function key(): string;

    abstract protected function providerLabel(): string;

    /**
     * @return list<array<string, mixed>>
     */
    abstract protected function fieldDefinitions(): array;

    /**
     * @return list<string>
     */
    protected function environments(): array
    {
        return ['sandbox', 'production'];
    }

    /**
     * @param  list<string>|null  $options
     * @return array<string, mixed>
     */
    protected static function field(
        string $key,
        string $label,
        string $type,
        bool $required = false,
        bool $sensitive = false,
        ?array $options = null,
        ?string $configuredFlag = null,
        ?string $default = null,
        ?string $serialize = null
    ): array {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'sensitive' => $sensitive,
            'placeholder' => $label,
            'options' => $options,
            'configured_flag' => $configuredFlag,
            'default' => $default,
            'serialize' => $serialize ?? match ($type) {
                'url' => 'trim_or_null',
                'select' => 'raw',
                default => 'trim',
            },
        ], static fn ($value) => $value !== null);
    }
}
