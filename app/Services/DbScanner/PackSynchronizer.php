<?php

namespace App\Services\DbScanner;

use App\Models\DbScanList;
use App\Models\DbScanRule;
use App\Models\DbScanRuleOverride;
use App\Models\DbScanRuleVersion;
use App\Models\DbScanSchedule;
use App\Models\DbScanSetting;
use App\Services\DbScanner\Rules\InventoryMatchers;
use App\Services\DbScanner\Rules\RowMatchers;
use App\Services\DbScanner\Surfaces\SurfaceRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Validates the shipped JSON packs and upserts them.
 *
 * Validation runs before anything is written: unknown matcher IDs or
 * surfaces, bad severities, and saved overrides that no longer fit the
 * pack all block activation with a visible error. Each changed definition
 * becomes a new immutable rule version; rules removed from a pack are
 * retired (kept for finding provenance), never deleted.
 */
class PackSynchronizer
{
    public const PACKS = ['core', 'malware', 'exotic'];

    private const CORRELATION_MATCHERS = ['reinfection_cluster'];

    public function __construct(private readonly SurfaceRegistry $surfaces) {}

    public function packPath(string $name): string
    {
        return resource_path('db-scanner/packs/'.$name.'.json');
    }

    /**
     * @return array{rules: array<int, array>, lists: array<int, array>, errors: array<int, string>, packs: array<string, string>}
     */
    public function load(): array
    {
        $errors = [];
        $rules = [];
        $packs = [];

        foreach (self::PACKS as $name) {
            $data = json_decode((string) @file_get_contents($this->packPath($name)), true);
            if (! is_array($data) || ! isset($data['rules'], $data['version'])) {
                $errors[] = "Pack {$name} is missing or invalid JSON.";

                continue;
            }
            $packs[$name] = (string) $data['version'];
            foreach ($data['rules'] as $rule) {
                $rule['pack'] = $name;
                $rule['pack_version'] = (string) $data['version'];
                $errors = array_merge($errors, $this->validateRule($rule));
                $rules[] = $rule;
            }
        }

        $keys = array_column($rules, 'key');
        foreach (array_count_values($keys) as $key => $count) {
            if ($count > 1) {
                $errors[] = "Rule {$key} is defined more than once.";
            }
        }

        $lists = json_decode((string) @file_get_contents($this->packPath('lists')), true);
        $lists = is_array($lists['lists'] ?? null) ? $lists['lists'] : [];
        if ($lists === []) {
            $errors[] = 'lists.json is missing or empty.';
        }

        return ['rules' => $rules, 'lists' => $lists, 'errors' => $errors, 'packs' => $packs];
    }

    public function validateRule(array $rule): array
    {
        $errors = [];
        $key = (string) ($rule['key'] ?? '?');

        if (! preg_match('/^[a-z]+\.[a-z0-9_]+$/', $key)) {
            $errors[] = "Rule key {$key} is malformed.";
        }
        $matcher = (string) ($rule['matcher'] ?? '');
        $known = in_array($matcher, RowMatchers::IDS, true) || in_array($matcher, InventoryMatchers::IDS, true) || in_array($matcher, self::CORRELATION_MATCHERS, true);
        if (! $known) {
            $errors[] = "Rule {$key} names unknown matcher {$matcher}.";
        }
        foreach ((array) ($rule['surfaces'] ?? []) as $surface) {
            if (! $this->surfaces->get((string) $surface)) {
                $errors[] = "Rule {$key} references unknown surface {$surface}.";
            }
        }
        if (! in_array($rule['severity'] ?? null, ['critical', 'warn', 'info'], true)) {
            $errors[] = "Rule {$key} has an invalid severity.";
        }
        if (! in_array($rule['confidence'] ?? null, [null, 'confirmed', 'strong', 'needs_review'], true)) {
            $errors[] = "Rule {$key} has an invalid confidence.";
        }
        foreach ((array) ($rule['profiles'] ?? []) as $profile) {
            if (! in_array($profile, ['quick', 'standard', 'deep'], true)) {
                $errors[] = "Rule {$key} has an invalid profile.";
            }
        }
        foreach ((array) ($rule['thresholds'] ?? []) as $name => $value) {
            if (! is_numeric($value)) {
                $errors[] = "Rule {$key} threshold {$name} is not numeric.";
            }
        }

        return $errors;
    }

    /**
     * @return array{created: int, updated: int, versions: int, retired: int, lists_seeded: int, schedules_seeded: int, packs: array}
     */
    public function sync(bool $dryRun = false): array
    {
        $loaded = $this->load();
        if ($loaded['errors'] !== []) {
            throw new RuntimeException(implode(' ', $loaded['errors']));
        }

        $this->assertOverridesCompatible($loaded['rules']);

        $summary = ['created' => 0, 'updated' => 0, 'versions' => 0, 'retired' => 0, 'lists_seeded' => 0, 'schedules_seeded' => 0, 'packs' => $loaded['packs']];
        if ($dryRun) {
            return $summary + ['dry_run' => true];
        }

        DB::transaction(function () use ($loaded, &$summary) {
            $seen = [];
            foreach ($loaded['rules'] as $rule) {
                $definition = $this->definition($rule);
                $hash = hash('sha256', json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $seen[] = $rule['key'];

                $version = DbScanRuleVersion::query()->firstOrCreate(
                    ['rule_key' => $rule['key'], 'definition_hash' => $hash],
                    ['pack_version' => $rule['pack_version'], 'definition' => $definition, 'created_at' => now()]
                );
                if ($version->wasRecentlyCreated) {
                    $summary['versions']++;
                }

                $existing = DbScanRule::query()->where('key', $rule['key'])->first();
                $attributes = [
                    'pack' => $rule['pack'],
                    'pack_version' => $rule['pack_version'],
                    'category' => $rule['category'],
                    'kind' => $rule['kind'],
                    'title' => $rule['title'],
                    'surfaces' => array_values((array) $rule['surfaces']),
                    'definition' => $definition,
                    'default_severity' => $rule['severity'],
                    'default_confidence' => $rule['confidence'],
                    'profiles' => array_values((array) $rule['profiles']),
                    'allowlistable' => (bool) ($rule['allowlistable'] ?? true),
                    'why' => $rule['why'] ?? null,
                    'remediation' => $rule['remediation'] ?? null,
                    'references' => array_values((array) ($rule['references'] ?? [])),
                    'definition_hash' => $hash,
                    'retired' => false,
                ];

                if ($existing) {
                    if ($existing->definition_hash !== $hash || $existing->retired) {
                        $summary['updated']++;
                    }
                    $existing->fill($attributes)->save();
                } else {
                    DbScanRule::query()->create($attributes + ['key' => $rule['key'], 'enabled' => (bool) ($rule['enabled'] ?? true)]);
                    $summary['created']++;
                }
            }

            $summary['retired'] = DbScanRule::query()->whereNotIn('key', $seen)->where('retired', false)->update(['retired' => true, 'enabled' => false]);

            foreach ($loaded['lists'] as $list) {
                $existing = DbScanList::query()->where('key', $list['key'])->where('scope_key', 'network')->lockForUpdate()->first();
                $shipped = fn ($v) => ['value' => (string) $v, 'note' => 'Shipped default', 'added_by' => null, 'added_at' => now()->toIso8601String()];
                if (! $existing) {
                    DbScanList::query()->create([
                        'key' => $list['key'],
                        'scope_key' => 'network',
                        'platform_id' => null,
                        'kind' => $list['kind'],
                        'description' => $list['description'] ?? null,
                        'entries' => array_map($shipped, $list['entries']),
                        'revision' => 1,
                    ]);
                    $summary['lists_seeded']++;

                    continue;
                }

                // New shipped defaults join existing lists, except values an
                // admin deliberately removed (recorded in the list audit trail).
                $present = array_map(fn ($e) => (string) (is_array($e) ? ($e['value'] ?? '') : $e), (array) $existing->entries);
                $removed = $this->removedListValues($list['key']);
                $missing = array_values(array_diff(array_map('strval', $list['entries']), $present, $removed));
                if ($missing !== []) {
                    $existing->entries = array_merge((array) $existing->entries, array_map($shipped, $missing));
                    $existing->revision = (int) $existing->revision + 1;
                    $existing->save();
                    $summary['list_entries_added'] = ($summary['list_entries_added'] ?? 0) + count($missing);
                }
            }

            if (! DbScanSchedule::query()->exists()) {
                foreach ([
                    ['name' => 'Nightly quick', 'profile' => 'quick', 'cron' => '30 1 * * *'],
                    ['name' => 'Weekly deep', 'profile' => 'deep', 'cron' => '0 2 * * 0'],
                ] as $seed) {
                    DbScanSchedule::query()->create($seed + [
                        'market_scope' => ['mode' => 'enabled_connections'],
                        'window' => ['start' => '01:00', 'end' => '05:30'],
                        'enabled' => false,
                        'revision' => 1,
                    ]);
                    $summary['schedules_seeded']++;
                }
            }

            DbScanSetting::current();
        });

        return $summary;
    }

    /**
     * Saved overrides must still fit the shipped rule (thresholds that exist,
     * lists the rule actually uses). An incompatible override blocks sync.
     */
    private function assertOverridesCompatible(array $rules): void
    {
        $byKey = [];
        foreach ($rules as $rule) {
            $byKey[$rule['key']] = $rule;
        }

        $problems = [];
        foreach (DbScanRuleOverride::query()->get() as $override) {
            $rule = $byKey[$override->rule_key] ?? null;
            if (! $rule) {
                continue; // retired rules keep their overrides inert
            }
            foreach (array_keys((array) $override->thresholds) as $name) {
                if (! array_key_exists($name, (array) ($rule['thresholds'] ?? []))) {
                    $problems[] = "Override for {$override->rule_key} ({$override->scope_key}) sets unknown threshold {$name}.";
                }
            }
            foreach ((array) $override->disabled_lists as $list) {
                if (! in_array($list, (array) ($rule['lists'] ?? []), true)) {
                    $problems[] = "Override for {$override->rule_key} ({$override->scope_key}) disables list {$list}, which the rule no longer uses.";
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }
    }

    /**
     * @return array<int, string>
     */
    private function removedListValues(string $key): array
    {
        $removed = [];
        foreach (\App\Models\DbScanAuditEvent::query()->where('entity', 'list')->where('entity_id', $key)->where('action', 'update')->get(['after']) as $event) {
            foreach ((array) ($event->after['removed'] ?? []) as $value) {
                $removed[] = (string) $value;
            }
        }

        return array_values(array_unique($removed));
    }

    private function definition(array $rule): array
    {
        return [
            'matcher' => $rule['matcher'],
            'surfaces' => array_values((array) $rule['surfaces']),
            'severity' => $rule['severity'],
            'confidence' => $rule['confidence'],
            'profiles' => array_values((array) $rule['profiles']),
            'thresholds' => (array) ($rule['thresholds'] ?? []),
            'lists' => array_values((array) ($rule['lists'] ?? [])),
            'kind' => $rule['kind'],
            'category' => $rule['category'],
        ];
    }
}
