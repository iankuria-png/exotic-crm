<?php

namespace App\Services\DbScanner;

use App\Models\DbScanFinding;
use App\Services\DbScanner\Rules\Hit;
use App\Services\DbScanner\Rules\NetworkIndicators;
use App\Services\DbScanner\Rules\RuleSet;

/** Correlates inert findings in CRM, never connects to another market. */
class FleetCampaignCorrelator
{
    public function hits(int $platformId, RuleSet $rules): array
    {
        if (! $rules->active('malware.reinfection_cluster')) {
            return [];
        }
        $findings = DbScanFinding::query()->whereIn('status', DbScanFinding::UNRESOLVED_STATUSES)
            ->whereIn('rule_key', ['access.application_passwords', 'access.activity_behaviour', 'access.account_campaign', 'access.historical_privileged_logins'])
            ->latest('last_seen_at')->limit(4001)->get();

        return $this->correlate($findings->take(4000), $platformId, $rules, $findings->count() <= 4000);
    }

    public function correlate(iterable $findings, int $platformId, RuleSet $rules, bool $complete = true): array
    {
        $groups = [];
        foreach ($findings as $f) {
            $d = $f->evidence['details'] ?? [];
            $indicators = [];
            if ($f->rule_key === 'access.application_passwords' && $f->severity === 'critical' && ! empty($d['name'])) {
                $indicators[] = ['type' => 'application_password', 'value' => $d['name'], 'at' => isset($d['created']) && is_numeric($d['created']) ? gmdate('c', (int) $d['created']) : ($d['at_utc'] ?? null)];
                if (filter_var($d['last_ip'] ?? '', FILTER_VALIDATE_IP)) {
                    $indicators[] = ['type' => 'application_password_last_ip', 'value' => $d['last_ip'], 'at' => ! empty($d['last_used']) && is_numeric($d['last_used']) ? gmdate('c', (int) $d['last_used']) : null];
                }
            }
            // Installs and failures alone cannot be described as successful logins.
            if (($d['kind'] ?? null) === 'new_ip_login' && ! ($d['confirmed_staff_ip'] ?? false) && ! empty($d['ip'])) {
                $indicators[] = ['type' => 'successful_ip', 'value' => $d['ip'], 'at' => $d['at_utc'] ?? null];
            }
            if ($f->rule_key === 'access.account_campaign') {
                foreach ($d['ips'] ?? [] as $ip) {
                    $indicators[] = ['type' => 'successful_ip', 'value' => $ip, 'at' => $d['at_utc'] ?? null];
                }
            }
            if ($f->rule_key === 'access.historical_privileged_logins') {
                foreach ($d['samples'] ?? [] as $sample) {
                    $indicators[] = ['type' => 'historical_login_alert_ip', 'value' => $sample['ip'], 'at' => $sample['first_alert_utc'] ?? null];
                }
            }
            foreach ($indicators as $i) {
                $key = $i['type'].'|'.strtolower($i['value']);
                $groups[$key]['indicator'] = $i;
                $groups[$key]['markets'][(int) $f->platform_id] = true;
                $groups[$key]['ids'][] = $f->id;
                if ($i['at'] && strtotime($i['at']) !== false) {
                    $groups[$key]['times'][(int) $f->platform_id][] = strtotime($i['at']);
                }
            }
        }
        $hits = [];
        foreach ($groups as $key => $g) {
            if (count($g['markets']) < 2 || ! isset($g['markets'][$platformId])) {
                continue;
            }
            $near = false;
            $times = $g['times'] ?? [];
            foreach (array_slice($times[$platformId] ?? [], 0, 50) as $t) {
                foreach ($times as $id => $other) {
                    if ($id === $platformId) {
                        continue;
                    }
                    foreach (array_slice($other, 0, 50) as $o) {
                        $near = $near || abs($t - $o) <= 600;
                    }
                }
            }
            $indicator = $g['indicator'];
            $strong = $indicator['type'] === 'application_password' || NetworkIndicators::matches($indicator['value'], $rules->list('ioc.ips', 'malware.reinfection_cluster'));
            $hits[] = new Hit('malware.reinfection_cluster', 'Shared '.str_replace('_', ' ', $indicator['type']).' observed across markets',
                ['surface' => 'sweep', 'table' => 'market', 'item' => 'fleet:'.hash('sha256', $key), 'object_type' => 'market'],
                ['details' => ['indicator_type' => $indicator['type'], 'indicator' => $indicator['value'], 'platform_ids' => array_keys($g['markets']), 'markets' => count($g['markets']), 'within_ten_minutes' => $near, 'linked_finding_ids' => array_slice(array_unique($g['ids']), 0, 50), 'correlation_complete' => $complete, 'interpretation' => 'Shared evidence for investigation; correlation alone does not prove reinfection.'], 'signals' => ['cross_market_indicator'], 'excerpts' => []],
                $strong ? 'strong' : 'needs_review', 'cross_market_campaign', $strong ? 'critical' : 'warn');
            if (count($hits) >= 50) {
                break;
            }
        }

        return $hits;
    }
}
