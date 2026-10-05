<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanSnapshot;
use App\Services\DbScanner\Evidence\EvidenceSanitizer;
use App\Services\DbScanner\Reader\MarketDbReader;
use App\Services\DbScanner\Surfaces\SchemaInfo;

/** Extracts bounded behaviour without storing raw logs, emails or request payloads. */
class ActivityCollector
{
    public function __construct(private readonly EvidenceSanitizer $sanitizer) {}

    public function collect(MarketDbReader $reader, SchemaInfo $schema, array $admins, array $core, array $appPasswords = []): array
    {
        $table = $schema->table('aryo_activity_log');
        $c = $reader->compiler();
        $known = [];
        $logins = [];
        $events = [];
        $history = [];
        $after = 0;
        $read = 0;
        $complete = true;
        $adminRows = array_column($admins['data']['admins'] ?? [], null, 'id');
        // Fleet history keys identify the same staff mailbox even when logins differ.
        $fleetHistory = [];
        foreach (DbScanSnapshot::query()->latest('taken_at')->limit(200)->get(['components']) as $snapshot) {
            foreach ($snapshot->components['activity.behaviour']['data']['staff_history'] ?? [] as $seen) {
                $key = ($seen['email_token'] ?? '').'|'.($seen['ip'] ?? '');
                $fleetHistory[$key] = min($fleetHistory[$key] ?? PHP_INT_MAX, (int) ($seen['time_utc'] ?? PHP_INT_MAX));
            }
        }
        $zone = $core['data']['timezone_string'] ?? null;
        $offset = (int) round((float) ($core['data']['gmt_offset'] ?? 0) * 3600);
        $utc = function (int $time) use ($zone, $offset): string {
            try {
                if ($zone) {
                    return (new \DateTimeImmutable(gmdate('Y-m-d H:i:s', $time), new \DateTimeZone($zone)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                }
            } catch (\Throwable) {
                // WordPress fixed-offset fallback.
            }

            return gmdate('Y-m-d\TH:i:s\Z', $time - $offset);
        };
        do {
            $page = $reader->select($c->activityEvents($table, $after));
            foreach ($page as $row) {
                $after = (int) $row['id'];
                $read++;
                $id = (int) $row['user_id'];
                $ip = filter_var($row['hist_ip'], FILTER_VALIDATE_IP) ?: '';
                $time = (int) $row['hist_time'];
                $action = strtolower((string) $row['action']);
                $source = strtolower((string) $row['request_source']);
                $actor = $adminRows[$id] ?? null;
                if ($action === 'logged_in') {
                    if ($actor && $ip !== '') {
                        $identity = $actor['email_token'].'|'.$ip;
                        $utcTime = strtotime($utc($time));
                        $seenAcrossFleet = ($fleetHistory[$identity] ?? PHP_INT_MAX) < $utcTime;
                        $new = ! isset($known[$id][$ip]) && ! $seenAcrossFleet;
                        $logins[$id] = ['time' => $time, 'ip' => $ip, 'new' => $new];
                        if ($new) {
                            $failed = $reader->select($c->activityFailureContext($table, $actor['login'], $ip, $time))[0] ?? [];
                            $events[] = ['kind' => 'new_ip_login', 'row_id' => $after, 'user_id' => $id, 'login' => $actor['login'], 'email_domain' => $actor['email_domain'], 'email_token' => $actor['email_token'], 'ip' => $ip, 'at_utc' => $utc($time), 'failed_before' => (int) ($failed['n'] ?? 0), 'failed_same_ip' => (int) ($failed['same_ip'] ?? 0), 'seconds_after_failure' => isset($failed['last_failure']) ? $time - (int) $failed['last_failure'] : null];
                        }
                        $known[$id][$ip] = true;
                        if (count($history) < 1000 || isset($history[$identity])) {
                            $history[$identity] ??= ['email_token' => $actor['email_token'], 'ip' => $ip, 'time_utc' => $utcTime];
                        } else {
                            $complete = false;
                        }
                    }

                    continue;
                }
                if (preg_match('/cli|cron|rest|app:/', $source) || ($id === 0 && in_array($ip, ['127.0.0.1', '::1'], true))) {
                    continue;
                }
                $login = $logins[$id] ?? null;
                $seconds = $login ? $time - $login['time'] : null;
                $events[] = [
                    'kind' => 'web_install', 'row_id' => $after, 'user_id' => $id,
                    'login' => $actor['login'] ?? null, 'ip' => $ip, 'at_utc' => $utc($time),
                    'email_domain' => $actor['email_domain'] ?? null, 'email_token' => $actor['email_token'] ?? null,
                    'object_type' => mb_substr((string) $row['object_type'], 0, 40),
                    'name' => $this->sanitizer->clean((string) $row['object_name']),
                    'action' => $action, 'request_source' => mb_substr($source, 0, 60),
                    'seconds_after_login' => $seconds !== null && $seconds >= 0 && $seconds <= 300 && $login['ip'] === $ip ? $seconds : null,
                    'new_ip' => $login && $login['ip'] === $ip ? $login['new'] : false,
                ];
                if (count($events) >= 2000) {
                    $complete = false;
                    break 2;
                }
            }
            if ($read >= 100000 || count($events) >= 2000) {
                $complete = false;
                break;
            }
        } while (count($page) === 500);

        $bursts = $reader->select($c->activityDailyBursts($table, $offset));
        $windows = $reader->select($c->activityLoginWindows($table));
        $successes = $reader->select($c->activitySuccessIps($table));
        $controls = $reader->select($c->activityControlIps($table));
        $targeted = $reader->select($c->targetedAccounts($table, $schema->table('users')));
        $keyed = [];
        foreach (array_chunk(array_unique(array_column($appPasswords['data'] ?? [], 'user_id')), 500) as $ids) {
            $keyed = array_merge($keyed, $reader->select($c->keyedAccountLogins($table, $ids)));
        }
        $registrations = $reader->select($c->activityRegistrationBursts($table));
        $email = $reader->select($c->activityEmailHealth($table))[0] ?? [];
        $ids = array_values(array_unique(array_merge(array_column($events, 'user_id'), array_column($windows, 'user_id'), array_column($successes, 'user_id'), array_column($controls, 'user_id'), array_column($targeted, 'user_id'), array_column($keyed, 'user_id'))));
        $users = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($reader->select($c->usersByIds($schema->table('users'), $chunk)) as $u) {
                $address = strtolower((string) $u['user_email']);
                $users[(int) $u['id']] = ['login' => mb_substr($u['user_login'], 0, 60), 'email_domain' => substr(strrchr($address, '@') ?: '', 1), 'email_token' => InventoryCollector::hmac($address)];
            }
        }
        foreach ($events as &$event) {
            $actor = $users[$event['user_id']] ?? [];
            $event['actor_account_missing'] = $event['user_id'] > 0 && $actor === [];
            $event['login'] = $actor['login'] ?? null;
            $event['email_domain'] = $actor['email_domain'] ?? null;
            $event['email_token'] = $actor['email_token'] ?? null;
        }
        unset($event);
        $decorate = function (array $rows, int $cap) use ($utc, $users, &$complete): array {
            if (count($rows) > $cap) {
                $complete = false;
            }

            return array_map(fn ($r) => $r + ($users[(int) ($r['user_id'] ?? 0)] ?? []) + ['at_utc' => $utc((int) ($r['first_time'] ?? 0))], array_slice($rows, 0, $cap));
        };
        if (count($bursts) > 1000 || count($registrations) > 500) {
            $complete = false;
        }

        return ['complete' => $complete && ($admins['complete'] ?? false), 'data' => [
            'events' => $events, 'bursts' => array_slice($bursts, 0, 1000),
            'login_windows' => $decorate($windows, 500), 'success_ips' => $decorate($successes, 2000), 'control_ips' => $decorate($controls, 500),
            'targeted_accounts' => $decorate($targeted, 500), 'keyed_account_logins' => $decorate($keyed, 500),
            'registrations' => array_slice($registrations, 0, 500),
            'email_health' => ['failed' => (int) ($email['failed'] ?? 0), 'sent' => (int) ($email['sent'] ?? 0), 'smtp_auth' => (int) ($email['smtp_auth'] ?? 0), 'window_start_utc' => $utc((int) ($email['first_time'] ?? 0)), 'window_end_utc' => $utc((int) ($email['last_time'] ?? 0))],
            'staff_history' => array_values($history), 'timezone' => $zone ?: $offset, 'events_read' => $read,
        ]];
    }
}
