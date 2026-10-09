<?php

namespace App\Services\DbScanner;

use App\Services\DbContainment\ContainmentCrypto;
use App\Services\DbScanner\Evidence\EvidenceSanitizer;

/** Metadata-only provenance. Never hashes or stores application-password secrets. */
class KeyObservationBinder
{
    public function group(array $entries, array $identity, int $user, int $row, array $base): array
    {
        $crypto = new ContainmentCrypto;
        $groups = [];
        $seen = [];
        $complete = true;
        $display = [];
        foreach ($entries as $key) {
            if (! is_array($key) || ! is_string($key['name'] ?? null) || ! is_string($key['uuid'] ?? null) || ! preg_match('/^[a-f0-9-]{36}$/iD', $key['uuid']) || isset($seen[$key['uuid']])) {
                $complete = false;

                continue;
            }
            $seen[$key['uuid']] = true;
            $name = $key['name'];
            $safe = (new EvidenceSanitizer(120))->clean($name);
            $display[$safe][$name] = true;
            $groups[$name]['digests'][] = $crypto->keyProvenance($identity, $user, $row, $name, $key['uuid']);
            $groups[$name]['metadata'] ??= $key;
            $groups[$name]['activity'][] = ['created' => (int) ($key['created'] ?? 0), 'last_used' => (int) ($key['last_used'] ?? 0), 'last_ip' => filter_var($key['last_ip'] ?? '', FILTER_VALIDATE_IP) ?: null, 'created_ip' => filter_var($key['created_ip'] ?? '', FILTER_VALIDATE_IP) ?: null];
            $groups[$name]['ips'][] = filter_var($key['last_ip'] ?? '', FILTER_VALIDATE_IP) ?: null;
            $groups[$name]['ips'][] = filter_var($key['created_ip'] ?? '', FILTER_VALIDATE_IP) ?: null;
            $groups[$name]['created'][] = (int) ($key['created'] ?? 0);
            $groups[$name]['last_used'][] = (int) ($key['last_used'] ?? 0);
        }
        $out = [];
        foreach ($groups as $name => $group) {
            $safe = (new EvidenceSanitizer(120))->clean($name);
            $digests = $group['digests'];
            sort($digests);
            $entry = $group['metadata'];
            $out[] = $base + ['name' => $safe, 'created' => min($group['created']), 'last_used' => max($group['last_used']), 'key_activity' => $group['activity'], 'observed_ips' => array_values(array_unique(array_filter($group['ips']))), 'last_ip' => filter_var($entry['last_ip'] ?? '', FILTER_VALIDATE_IP) ?: null, 'created_ip' => filter_var($entry['created_ip'] ?? '', FILTER_VALIDATE_IP) ?: null,
                'key_binding' => ['version' => (string) config('db_containment.key_version'), 'name_id' => $crypto->digest([...$identity, $user, $row, $name], 'key-name'), 'digests' => $digests, 'count' => count($digests), 'complete' => $complete && count($display[$safe]) === 1 && mb_strlen($name) <= 120]];
        }

        return $out;
    }
}
