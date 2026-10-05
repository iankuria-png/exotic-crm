<?php

namespace App\Services\DbScanner\Engine;

use App\Services\DbScanner\Reader\MarketDbReader;
use App\Services\DbScanner\Surfaces\SchemaInfo;

/** Dedicated alert adapter: bodies exist only during parsing, never in evidence. */
class WordfenceAlertCollector
{
    public function collect(MarketDbReader $reader, SchemaInfo $schema): array
    {
        $after = 0;
        $read = 0;
        $unparsed = 0;
        $complete = true;
        $groups = [];
        do {
            $page = $reader->select($reader->compiler()->wordfenceLoginAlerts($schema->table('email_log'), $after));
            foreach ($page as $row) {
                $after = (int) $row['id'];
                $read++;
                $metadata = $this->parse((string) $row['alert']);
                unset($row['alert']);
                if ((int) $row['len'] > 16384) {
                    $complete = false;
                }
                if ($metadata === null || ! is_numeric($row['sent_utc'])) {
                    $unparsed++;
                    $complete = false;

                    continue;
                }
                $key = strtolower($metadata['login']).'|'.$metadata['ip'];
                $at = gmdate('Y-m-d\TH:i:s\Z', (int) $row['sent_utc']);
                if (! isset($groups[$key]) && count($groups) >= 1000) {
                    $complete = false;

                    continue;
                }
                $groups[$key] ??= $metadata + ['alerts' => 0, 'first_alert_utc' => $at, 'last_alert_utc' => $at];
                $groups[$key]['alerts']++;
                $groups[$key]['first_alert_utc'] = min($groups[$key]['first_alert_utc'], $at);
                $groups[$key]['last_alert_utc'] = max($groups[$key]['last_alert_utc'], $at);
            }
            if ($read >= 5000) {
                $complete = false;
                break;
            }
        } while (count($page) === 100);

        $users = [];
        foreach (array_chunk(array_unique(array_column($groups, 'login')), 500) as $logins) {
            foreach ($reader->select($reader->compiler()->usersByLogins($schema->table('users'), $logins)) as $user) {
                $users[strtolower($user['user_login'])] = (int) $user['id'];
            }
        }
        foreach ($groups as &$group) {
            $group['user_id'] = $users[strtolower($group['login'])] ?? null;
            $group['actor_account_missing'] = $group['user_id'] === null;
        }
        unset($group);

        return ['complete' => $complete, 'data' => ['logins' => array_values($groups), 'alerts_read' => $read, 'unparsed_alerts' => $unparsed, 'source' => 'Stored Wordfence email alerts; historical claims, not independently verified logins. Dates are alert send times in UTC.']];
    }

    public function parse(string $body): ?array
    {
        $text = html_entity_decode(strip_tags(preg_replace('/<(?:br|\/p|\/div)[^>]*>/i', "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (! preg_match('/username\s+["\x{201c}]([^"\x{201d}\r\n]{1,60})["\x{201d}]\s+who has administrator access signed in/iu', $text, $user)
            || ! preg_match('/User IP:\s*([^\s]+)/i', $text, $ip)
            || ! filter_var($ip[1], FILTER_VALIDATE_IP)) {
            return null;
        }
        preg_match('/User location:[ \t]*([^\r\n]{1,120})/i', $text, $location);

        return ['login' => trim($user[1]), 'ip' => $ip[1], 'location' => isset($location[1]) ? trim($location[1]) : null, 'privileged_at_alert' => true];
    }
}
