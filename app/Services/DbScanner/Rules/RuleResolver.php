<?php

namespace App\Services\DbScanner\Rules;

use App\Models\DbScanConfigVersion;
use App\Models\DbScanConnection;
use App\Models\DbScanList;
use App\Models\DbScanRule;
use App\Models\DbScanRuleOverride;
use App\Models\Platform;
use App\Services\DbScanner\Malware\HostContext;
use App\Services\DbScanner\Surfaces\SecretPolicy;
use App\Services\DbScanner\Surfaces\SurfaceRegistry;
use App\Support\WordPressSiteConnection;

/**
 * Resolves pack definitions + network override + market override + merged
 * list revisions into one immutable configuration version for a run.
 */
class RuleResolver
{
    public function __construct(private readonly SurfaceRegistry $surfaces) {}

    public function resolve(Platform $platform, string $profile, ?array $ruleSubset = null, ?DbScanConnection $connection = null): RuleSet
    {
        $config = $this->build($platform, $profile, $ruleSubset, $connection);
        $json = $this->canonical($config);
        $hash = hash('sha256', $json);
        $config['hash'] = $hash;

        $version = DbScanConfigVersion::query()->firstOrCreate(
            ['hash' => $hash],
            ['config' => $json, 'created_at' => now()]
        );

        return new RuleSet($config, (int) $version->id);
    }

    public function fromVersion(DbScanConfigVersion $version): RuleSet
    {
        $config = $version->decoded();
        $config['hash'] = $version->hash;

        return new RuleSet($config, (int) $version->id);
    }

    public function build(Platform $platform, string $profile, ?array $ruleSubset = null, ?DbScanConnection $connection = null): array
    {
        $scopeKey = 'platform:'.$platform->id;
        $overrides = DbScanRuleOverride::query()
            ->whereIn('scope_key', ['network', $scopeKey])
            ->get()
            ->groupBy('rule_key');

        $profileSurfaces = array_keys($this->surfaces->forProfile($profile));
        $rules = [];

        foreach (DbScanRule::query()->where('retired', false)->orderBy('key')->get() as $rule) {
            $definition = (array) $rule->definition;
            $network = optional($overrides->get($rule->key))->firstWhere('scope_key', 'network');
            $market = optional($overrides->get($rule->key))->firstWhere('scope_key', $scopeKey);

            $enabled = (bool) $rule->enabled;
            foreach ([$network, $market] as $o) {
                if ($o && $o->enabled !== null) {
                    $enabled = (bool) $o->enabled;
                }
            }

            $severity = $rule->default_severity;
            foreach ([$network, $market] as $o) {
                if ($o && $o->severity) {
                    $severity = $o->severity;
                }
            }

            $thresholds = (array) ($definition['thresholds'] ?? []);
            foreach ([$network, $market] as $o) {
                foreach ((array) ($o?->thresholds ?? []) as $name => $value) {
                    if (array_key_exists($name, $thresholds) && is_numeric($value)) {
                        $thresholds[$name] = $value + 0;
                    }
                }
            }

            $disabledLists = [];
            foreach ([$network, $market] as $o) {
                $disabledLists = array_merge($disabledLists, (array) ($o?->disabled_lists ?? []));
            }

            $ruleSurfaces = array_values((array) $rule->surfaces);
            $inProfile = in_array($profile, (array) $rule->profiles, true)
                && ($ruleSurfaces === [] || array_intersect($ruleSurfaces, $profileSurfaces) !== []);

            $rules[$rule->key] = [
                'key' => $rule->key,
                'pack' => $rule->pack,
                'pack_version' => $rule->pack_version,
                'category' => $rule->category,
                'kind' => $rule->kind,
                'title' => $rule->title,
                'matcher' => $definition['matcher'] ?? null,
                'surfaces' => array_values(array_intersect($ruleSurfaces, $profileSurfaces)),
                'all_surfaces' => $ruleSurfaces,
                'severity' => $severity,
                'confidence' => $rule->default_confidence,
                'thresholds' => $thresholds,
                'lists' => array_values((array) ($definition['lists'] ?? [])),
                'disabled_lists' => array_values(array_unique($disabledLists)),
                'enabled' => $enabled,
                'in_profile' => $inProfile,
                'version_hash' => $rule->definition_hash,
            ];
        }

        $lists = [];
        $revisions = [];
        foreach (DbScanList::query()->whereIn('scope_key', ['network', $scopeKey])->orderBy('scope_key')->get() as $list) {
            foreach ((array) $list->entries as $entry) {
                $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_array($entry) && ! empty($entry['expires_at']) && strtotime((string) $entry['expires_at']) < time()) {
                    continue;
                }
                $lists[$list->key][] = (string) $value;
            }
            $revisions[$list->key][] = $list->scope_key.':'.$list->revision;
        }
        foreach ($lists as $key => $values) {
            $values = array_values(array_unique($values));
            sort($values);
            $lists[$key] = $values;
        }
        ksort($lists);
        ksort($revisions);

        $site = WordPressSiteConnection::fromPlatform($platform);

        // Every market domain is ours, as crm:db-scan-report already assumed.
        $networkHosts = $lists['allow.network_domains'] ?? [];
        foreach (Platform::query()->get(['id', 'domain', 'wp_api_url']) as $market) {
            $host = HostContext::hostOf(WordPressSiteConnection::fromPlatform($market)->baseUrl);
            if ($host !== '') {
                $networkHosts[] = $host;
            }
        }
        $networkHosts = array_values(array_unique($networkHosts));
        sort($networkHosts);

        return [
            'version' => 1,
            'profile' => $profile,
            'platform_id' => (int) $platform->id,
            'site_host' => HostContext::hostOf($site->baseUrl),
            'network_hosts' => $networkHosts,
            'connection_config_version' => $connection?->config_version,
            'secret_policy' => SecretPolicy::VERSION,
            'surfaces' => array_map(fn ($s) => $s->adapterVersion, $this->surfaces->forProfile($profile)),
            'rule_subset' => $ruleSubset ? array_values($ruleSubset) : null,
            'rules' => $rules,
            'lists' => $lists,
            'list_revisions' => $revisions,
        ];
    }

    public function hostContext(RuleSet $rules): HostContext
    {
        return new HostContext(
            siteHost: $rules->siteHost(),
            networkHosts: (array) ($rules->config['network_hosts'] ?? []),
            scriptHosts: $rules->list('allow.script_hosts'),
            iframeHosts: $rules->list('allow.iframe_hosts'),
            gtmContainers: $rules->list('allow.gtm_containers'),
            outboundAllow: $rules->list('allow.outbound_domains'),
        );
    }

    private function canonical(array $config): string
    {
        return json_encode($this->sortKeys($config), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->sortKeys($v), $value);
    }
}
