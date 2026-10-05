<?php

namespace App\Services\DbScanner;

/** Bounded fleet summaries over already inert, scoped findings. */
class FleetTriage
{
    public function summarize(iterable $findings, array $names): array
    {
        $groups = [];
        $identities = [];
        foreach ($findings as $finding) {
            $d = $finding->evidence['details'] ?? [];
            $subject = $finding->subject ?? [];
            $identity = $d['plugin'] ?? $d['name'] ?? $d['login'] ?? $d['role'] ?? $d['option_name'] ?? $subject['label'] ?? $finding->title;
            $key = hash('sha256', $finding->rule_key.'|'.$identity.'|'.($d['definition_hash'] ?? ''));
            $groups[$key] ??= ['key' => $key, 'rule_key' => $finding->rule_key, 'identity' => (string) $identity, 'findings' => 0, 'critical' => 0, 'platform_ids' => []];
            $groups[$key]['definition_hash'] = $d['definition_hash'] ?? null;
            $groups[$key]['findings']++;
            $groups[$key]['critical'] += $finding->severity === 'critical' ? 1 : 0;
            $groups[$key]['platform_ids'][(int) $finding->platform_id] = true;
            foreach (['ip' => 'ip', 'last_ip' => 'ip', 'created_ip' => 'ip', 'login' => 'username', 'plugin' => 'plugin'] as $field => $type) {
                if (is_string($d[$field] ?? null) && $d[$field] !== '') {
                    $this->add($identities, $type, $d[$field], $finding);
                }
            }
            foreach ($d['ips'] ?? [] as $ip) {
                if (is_string($ip)) {
                    $this->add($identities, 'ip', $ip, $finding);
                }
            }
            if ($finding->rule_key === 'access.application_passwords' && ! empty($d['name'])) {
                $this->add($identities, 'application_password', $d['name'], $finding);
            }
        }
        $groupRows = array_values(array_map(function ($row) use ($names) {
            $row['platform_ids'] = array_keys($row['platform_ids']);
            $row['markets'] = count($row['platform_ids']);
            $row['market_names'] = array_values(array_intersect_key($names, array_flip($row['platform_ids'])));

            return $row;
        }, $groups));
        usort($groupRows, fn ($a, $b) => [$b['critical'], $b['markets'], $b['findings']] <=> [$a['critical'], $a['markets'], $a['findings']]);
        $correlations = [];
        foreach ($identities as $row) {
            if (count($row['platform_ids']) < 2) {
                continue;
            }
            $row['platform_ids'] = array_keys($row['platform_ids']);
            $row['markets'] = count($row['platform_ids']);
            $row['market_names'] = array_values(array_intersect_key($names, array_flip($row['platform_ids'])));
            $row['days'] = array_keys($row['days']);
            $row['same_day_markets'] = max(array_map('count', $row['day_markets']));
            unset($row['day_markets']);
            $correlations[] = $row;
        }
        usort($correlations, fn ($a, $b) => [$b['same_day_markets'], $b['markets']] <=> [$a['same_day_markets'], $a['markets']]);

        return ['groups' => array_slice($groupRows, 0, 100), 'correlations' => array_slice($correlations, 0, 100)];
    }

    private function add(array &$items, string $type, string $value, $finding): void
    {
        $key = $type.'|'.strtolower($value);
        $items[$key] ??= ['type' => $type, 'value' => mb_substr($value, 0, 200), 'platform_ids' => [], 'days' => [], 'day_markets' => [], 'findings' => 0];
        $items[$key]['platform_ids'][(int) $finding->platform_id] = true;
        $items[$key]['findings']++;
        $day = substr((string) ($finding->evidence['details']['at_utc'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $items[$key]['days'][$day] = true;
            $items[$key]['day_markets'][$day][(int) $finding->platform_id] = true;
        } else {
            $items[$key]['day_markets']['undated'] ??= [];
        }
    }
}
