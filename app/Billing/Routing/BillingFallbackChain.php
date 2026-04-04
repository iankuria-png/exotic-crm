<?php

namespace App\Billing\Routing;

use App\Billing\Support\ExecutionMode;

final class BillingFallbackChain
{
    /**
     * @var list<array{
     *     provider_key: string,
     *     provider_profile_key: ?string,
     *     execution_mode: ExecutionMode,
     *     reason: ?string
     * }>
     */
    private array $steps = [];

    /**
     * @param  iterable<int, array{
     *     provider_key: string,
     *     provider_profile_key?: ?string,
     *     execution_mode?: ExecutionMode|string|null,
     *     reason?: ?string
     * }>  $steps
     */
    public function __construct(iterable $steps = [])
    {
        foreach ($steps as $step) {
            $providerKey = strtolower(trim((string) ($step['provider_key'] ?? '')));
            if ($providerKey === '') {
                continue;
            }

            $executionMode = $step['execution_mode'] ?? ExecutionMode::Direct;
            if (!$executionMode instanceof ExecutionMode) {
                $executionMode = ExecutionMode::from((string) $executionMode);
            }

            $this->steps[] = [
                'provider_key' => $providerKey,
                'provider_profile_key' => isset($step['provider_profile_key']) && trim((string) $step['provider_profile_key']) !== ''
                    ? trim((string) $step['provider_profile_key'])
                    : null,
                'execution_mode' => $executionMode,
                'reason' => isset($step['reason']) && trim((string) $step['reason']) !== ''
                    ? trim((string) $step['reason'])
                    : null,
            ];
        }
    }

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @return list<array{
     *     provider_key: string,
     *     provider_profile_key: ?string,
     *     execution_mode: ExecutionMode,
     *     reason: ?string
     * }>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return list<string>
     */
    public function providerKeys(): array
    {
        return array_map(
            static fn (array $step): string => $step['provider_key'],
            $this->steps
        );
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }
}
