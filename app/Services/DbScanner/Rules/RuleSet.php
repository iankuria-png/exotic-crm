<?php

namespace App\Services\DbScanner\Rules;

/**
 * The immutable, resolved configuration a run executes: shipped rule
 * definitions + network and market overrides + merged list revisions.
 * Jobs resume this exact snapshot; a later pack or override change affects
 * only new runs.
 */
final class RuleSet
{
    /** @var array<string, array<int, string>> surface => rule keys */
    private array $bySurface = [];

    public function __construct(public readonly array $config, public readonly ?int $configVersionId = null)
    {
        foreach ($this->rules() as $key => $rule) {
            if (! $this->active($key)) {
                continue;
            }
            foreach ((array) ($rule['surfaces'] ?? []) as $surface) {
                $this->bySurface[$surface][] = $key;
            }
        }
    }

    public function hash(): string
    {
        return (string) ($this->config['hash'] ?? hash('sha256', json_encode($this->config)));
    }

    public function profile(): string
    {
        return (string) ($this->config['profile'] ?? 'quick');
    }

    public function siteHost(): string
    {
        return (string) ($this->config['site_host'] ?? '');
    }

    /**
     * @return array<string, array>
     */
    public function rules(): array
    {
        return (array) ($this->config['rules'] ?? []);
    }

    public function rule(string $key): ?array
    {
        return $this->config['rules'][$key] ?? null;
    }

    /** Enabled, in this run's profile, and inside an explicit rule subset when one was requested. */
    public function active(string $key): bool
    {
        $rule = $this->rule($key);
        if (! $rule || ! ($rule['enabled'] ?? false) || ! ($rule['in_profile'] ?? false)) {
            return false;
        }
        $subset = $this->config['rule_subset'] ?? null;

        return ! is_array($subset) || in_array($key, $subset, true);
    }

    /**
     * Why a rule is not active, for coverage accounting.
     */
    public function inactiveReason(string $key): ?string
    {
        $rule = $this->rule($key);
        if (! $rule) {
            return 'unknown_rule';
        }
        if (! ($rule['enabled'] ?? false)) {
            return 'disabled';
        }
        if (! ($rule['in_profile'] ?? false)) {
            return 'not_in_profile';
        }
        $subset = $this->config['rule_subset'] ?? null;
        if (is_array($subset) && ! in_array($key, $subset, true)) {
            return 'not_in_subset';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function rulesForSurface(string $surface): array
    {
        return $this->bySurface[$surface] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function activeSurfaces(): array
    {
        return array_keys($this->bySurface);
    }

    public function versionHash(string $key): string
    {
        return (string) ($this->config['rules'][$key]['version_hash'] ?? '');
    }

    public function severity(string $key): string
    {
        return (string) ($this->config['rules'][$key]['severity'] ?? 'warn');
    }

    public function threshold(string $key, string $name, int|float $default): int|float
    {
        $value = $this->config['rules'][$key]['thresholds'][$name] ?? $default;

        return is_numeric($value) ? $value + 0 : $default;
    }

    /**
     * Merged list values (network + market), minus lists disabled for this rule.
     *
     * @return array<int, string>
     */
    public function list(string $listKey, ?string $forRule = null): array
    {
        if ($forRule !== null) {
            $disabled = (array) ($this->config['rules'][$forRule]['disabled_lists'] ?? []);
            if (in_array($listKey, $disabled, true)) {
                return [];
            }
        }

        return array_values((array) ($this->config['lists'][$listKey] ?? []));
    }

    /**
     * Lists a rule consumes, for the Rules UI and lexicon rules.
     *
     * @return array<int, string>
     */
    public function ruleLists(string $key): array
    {
        return (array) ($this->config['rules'][$key]['lists'] ?? []);
    }

    public function isSubsetRun(): bool
    {
        return is_array($this->config['rule_subset'] ?? null);
    }
}
