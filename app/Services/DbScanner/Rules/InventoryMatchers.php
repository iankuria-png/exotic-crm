<?php

namespace App\Services\DbScanner\Rules;

use App\Services\DbScanner\Malware\HostContext;

/**
 * Rules evaluated against snapshot components (inventory surfaces) and, for
 * drift rules, against the previous compatible snapshot. A drift rule with
 * no prior complete component records a baseline instead of a finding.
 */
class InventoryMatchers
{
    public const IDS = [
        'admin_unexpected_email', 'hidden_admin_capabilities', 'admin_count_drift', 'forged_registration',
        'suspicious_usernames', 'application_passwords', 'open_registration', 'mysql_triggers',
        'mysql_events_routines', 'plugin_not_allowlisted', 'plugin_path_suspicious', 'plugin_theme_drift',
        'cron_unknown_hooks', 'cron_volume', 'cron_overdue', 'site_url_mismatch', 'search_engines_blocked',
        'admin_email_drift', 'upload_path_changed', 'yoast_crawl_baseline', 'permalink_drift', 'external_redirects',
        'autoload_size', 'mass_created_posts', 'orphan_authors', 'expired_transients', 'revision_bloat',
        'slug_aliases', 'orphaned_meta', 'action_scheduler_backlog', 'unexpected_tables', 'table_integrity',
        'invisible_plugins', 'activity_behaviour', 'md5_admin_passwords', 'registration_spam', 'account_campaign', 'email_delivery_health',
        'table_growth', 'role_privilege_risk', 'lookalike_email_domains', 'login_attack_pressure', 'targeted_accounts', 'historical_privileged_logins',
    ];

    /**
     * @return array<int, Hit>
     */
    public function run(string $matcher, string $key, RuleSet $rules, array $components, ?array $previous): array
    {
        $method = lcfirst(str_replace('_', '', ucwords($matcher, '_')));
        if (! in_array($matcher, self::IDS, true) || ! method_exists($this, $method)) {
            return [];
        }

        return $this->{$method}($key, $rules, $components, $previous ?? []);
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    private function adminUnexpectedEmail(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];
        $allow = array_map('strtolower', $rules->list('allow.admin_email_domains', $key));
        $fake = array_map('strtolower', $rules->list('lexicon.fake_admin_domains', $key));
        $site = $rules->siteHost();

        foreach ($c['administrators']['data']['admins'] ?? [] as $admin) {
            $approved = array_map(fn ($email) => \App\Services\DbScanner\Engine\InventoryCollector::hmac(strtolower($email)), $rules->list('allow.admin_emails', $key));
            if (in_array($admin['email_token'] ?? '', $approved, true)) {
                continue;
            }
            $domain = strtolower((string) $admin['email_domain']);
            $siteLike = $site !== '' && in_array($domain, ['www.'.$site, 'mail.'.$site], true);
            $isFake = $domain === '' || HostContext::matches($domain, $fake) || $siteLike;
            if (! $isFake && HostContext::matches($domain, $allow)) {
                continue;
            }
            $hits[] = $this->hit($key, $isFake ? 'Administrator using a fake or support-style email domain' : 'Administrator email outside the approved company domains', 'usermeta', 'user:'.$admin['id'], [
                'user_id' => $admin['id'],
                'login' => $admin['login'],
                'email' => $admin['email_masked'],
                'registered' => $admin['registered'],
            ], [$isFake ? 'fake_domain' : 'outside_allowlist'], 'users.privileged', $admin['id'], $isFake ? 'strong' : 'needs_review');
        }

        return $hits;
    }

    private function hiddenAdminCapabilities(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];
        foreach ($c['administrators']['data']['hidden'] ?? [] as $row) {
            $stale = $row['has_site_privilege'];
            $hits[] = $this->hit(
                $key,
                $stale ? 'Privileged capability row under another table prefix (stale grant)' : 'Privileged capability stored under a non-site meta key',
                'usermeta',
                'umeta:'.$row['umeta_id'],
                ['user_id' => $row['user_id'], 'login' => $row['login'], 'meta_key' => $row['meta_key'], 'grants' => $row['grants'], 'user_row_exists' => $row['user_exists']],
                ['foreign_capability_key'],
                'users.privileged',
                $row['user_id'],
                $stale ? 'needs_review' : 'strong',
                $stale ? 'warn' : null,
                rowId: $row['umeta_id'],
            );
        }
        foreach ($c['administrators']['data']['legacy_level'] ?? [] as $row) {
            $hits[] = $this->hit($key, 'Stale user_level 10 on an account whose role does not grant it', 'usermeta', 'level:'.$row['user_id'], ['user_id' => $row['user_id'], 'login' => $row['login']], ['legacy_user_level'], 'users.privileged', $row['user_id'], 'needs_review', 'warn');
        }

        return $hits;
    }

    private function adminCountDrift(string $key, RuleSet $rules, array $c, array $p): array
    {
        if (! ($p['administrators']['complete'] ?? false) || ! ($c['administrators']['complete'] ?? false)) {
            return [];
        }
        $before = array_column($p['administrators']['data']['admins'] ?? [], 'id');
        $hits = [];
        foreach ($c['administrators']['data']['admins'] ?? [] as $admin) {
            if (! in_array($admin['id'], $before, true)) {
                $hits[] = $this->hit($key, 'New administrator since the previous snapshot', 'usermeta', 'new_admin:'.$admin['id'], [
                    'user_id' => $admin['id'], 'login' => $admin['login'], 'email' => $admin['email_masked'], 'registered' => $admin['registered'],
                    'admins_before' => count($before), 'admins_now' => count($c['administrators']['data']['admins'] ?? []),
                ], ['admin_added'], 'users.privileged', $admin['id'], 'strong');
            }
        }

        return $hits;
    }

    private function forgedRegistration(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];
        foreach ($c['administrators']['data']['admins'] ?? [] as $admin) {
            $future = $admin['registered'] !== '' && strtotime($admin['registered']) > time() + 86400;
            if ((int) $admin['lower_ids_registered_later'] > 0 || $future) {
                $hits[] = $this->hit($key, 'Administrator registration date out of sequence', 'users', 'forged:'.$admin['id'], [
                    'user_id' => $admin['id'], 'login' => $admin['login'], 'registered' => $admin['registered'],
                    'lower_id_users_registered_30d_later' => (int) $admin['lower_ids_registered_later'],
                ], [$future ? 'future_date' : 'backdated'], 'users.privileged', $admin['id'], 'needs_review');
            }
        }

        return $hits;
    }

    private function suspiciousUsernames(string $key, RuleSet $rules, array $c, array $p): array
    {
        $names = array_map('strtolower', $rules->list('lexicon.suspicious_usernames', $key));
        $hits = [];
        foreach ($c['administrators']['data']['admins'] ?? [] as $admin) {
            $login = strtolower((string) $admin['login']);
            $random = (bool) preg_match('/^[a-z0-9]{12,}$/', $login) && preg_match('/\d/', $login) && ! preg_match('/[aeiou]{2}/', $login);
            if (in_array($login, $names, true) || $random) {
                $hits[] = $this->hit($key, 'Administrator with a generic or attacker-style username', 'users', 'username:'.$admin['id'], [
                    'user_id' => $admin['id'], 'login' => $admin['login'], 'email' => $admin['email_masked'],
                ], [$random ? 'random_login' : 'listed_login'], 'users.privileged', $admin['id'], 'needs_review');
            }
        }

        return $hits;
    }

    private function applicationPasswords(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];

        $trustedIps = $rules->list('allow.crm_ips', $key);
        foreach ($c['app_passwords']['data'] ?? [] as $entry) {
            $name = (string) $entry['name'];
            $ips = array_values(array_unique(array_filter([...($entry['observed_ips'] ?? []), $entry['created_ip'] ?? null, $entry['last_ip'] ?? null])));
            $external = array_values(array_diff($ips, $trustedIps));
            $deceptive = (bool) preg_match('/system.?key|do.?not.?delete|injector|bootstrap|xr-auto/i', $name);
            // A CRM name family still requires every recorded IP to be trusted; deceptive names never bypass.
            if ((preg_match('/^(?:EWMS|Exotic[ -]CRM(?: Sync)?)$/i', $name) || ($name === 'tzz' && $rules->siteHost() === 'exotic-tz.net')) && ! $deceptive && $external === [] && $ips !== []) {
                continue;
            }
            $risk = ! $entry['privileged'] || $deceptive || $external !== [];
            $hits[] = $this->hit($key, $risk ? 'Suspicious application password on a WordPress account' : 'Application password needs ownership review', 'usermeta', 'app_password:'.$entry['user_id'].':'.($entry['key_binding']['name_id'] ?? $name), $entry,
                array_values(array_filter([! $entry['privileged'] ? 'non_staff_key' : null, $deceptive ? 'deceptive_key_name' : null, $external !== [] ? 'outside_crm_hosts' : null])),
                'usermeta.app_passwords', $entry['user_id'], $risk ? 'strong' : 'needs_review', $risk ? 'critical' : 'warn', $entry['row_id']);
        }

        return $hits;
    }

    private function md5AdminPasswords(string $key, RuleSet $rules, array $c, array $p): array
    {
        $users = array_values(array_map(fn ($a) => ['user_id' => $a['id'], 'login' => $a['login']], array_filter($c['administrators']['data']['admins'] ?? [], fn ($a) => $a['md5_password'] ?? false)));

        return $users === [] ? [] : [$this->hit($key, 'Privileged accounts use legacy unsalted MD5 passwords', 'users', 'md5:market', ['count' => count($users), 'accounts' => array_slice($users, 0, 100), 'list_complete' => count($users) <= 100, 'scheme' => 'legacy_md5'], ['weak_password_scheme'], 'users.privileged', null, 'strong', 'warn')];
    }

    private function invisiblePlugins(string $key, RuleSet $rules, array $c, array $p): array
    {
        $o = $c['core_options']['data'] ?? [];
        $hits = [];
        foreach ($o['active_plugins'] ?? [] as $plugin) {
            $slug = explode('/', $plugin)[0];
            $missing = ($o['plugin_inventory_present'] ?? false) && ! in_array($plugin, $o['plugin_checked'] ?? [], true);
            $versionless = in_array($plugin, $o['versionless_plugins'] ?? [], true) || in_array($slug, $o['versionless_plugins'] ?? [], true);
            if (! $missing && ! $versionless) {
                continue;
            }
            $hits[] = $this->hit($key, 'Active plugin entry with no readable plugin, confirm on disk', 'options', 'invisible:'.$plugin,
                ['plugin' => $plugin, 'missing_from_update_inventory' => $missing, 'host_agent_version_null' => $versionless],
                array_values(array_filter([$missing ? 'missing_update_inventory' : null, $versionless ? 'versionless_plugin' : null])), 'options.core', null, $versionless ? 'strong' : 'needs_review', $versionless ? 'critical' : 'warn');
        }

        return $hits;
    }

    private function activityBehaviour(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];
        $data = $c['activity.behaviour']['data'] ?? [];
        $approvedActors = array_map(fn ($e) => \App\Services\DbScanner\Engine\InventoryCollector::hmac(strtolower($e)), $rules->list('allow.it_emails', $key));
        $actions = [];
        foreach ($data['events'] ?? [] as $event) {
            $staff = HostContext::matches((string) ($event['email_domain'] ?? ''), $rules->list('allow.admin_email_domains', $key)) || in_array($event['email_token'] ?? '', $approvedActors, true);
            $approved = $staff && $this->approvedInstall((string) ($event['name'] ?? ''), $rules->list('allow.install_names', $key));
            if ($event['kind'] === 'web_install' && ! $approved && ($event['seconds_after_login'] ?? null) !== null) {
                $actions[$event['user_id'].'|'.$event['ip'].'|'.(strtotime($event['at_utc']) - (int) $event['seconds_after_login'])] = true;
            }
        }
        foreach ($data['events'] ?? [] as $event) {
            $install = $event['kind'] === 'web_install';
            $ip = (string) ($event['ip'] ?? '');
            $ioc = NetworkIndicators::matches($ip, $rules->list('ioc.ips', $key));
            $staffIp = NetworkIndicators::matches($ip, $rules->list('allow.staff_ips', $key)) && ! $ioc;
            $staff = HostContext::matches((string) ($event['email_domain'] ?? ''), $rules->list('allow.admin_email_domains', $key)) || in_array($event['email_token'] ?? '', $approvedActors, true);
            $campaignPlugin = $install && $this->approvedInstall((string) ($event['name'] ?? ''), $rules->list('ioc.plugins', $key));
            $approved = ! $campaignPlugin && $install && $staff && $this->approvedInstall((string) ($event['name'] ?? ''), $rules->list('allow.install_names', $key)) && ! $ioc;
            $sequence = $install && ($event['seconds_after_login'] ?? null) !== null && ($event['new_ip'] ?? false);
            $followedByAction = isset($actions[$event['user_id'].'|'.$ip.'|'.strtotime($event['at_utc'])]);
            $rapidFailure = ($event['seconds_after_failure'] ?? null) !== null && $event['seconds_after_failure'] <= 10;
            $strong = ! $approved && ($install ? ($campaignPlugin || $ioc || ($sequence && ! $staffIp)) : ($ioc || (! $staffIp && ($followedByAction || ((int) ($event['failed_same_ip'] ?? 0) >= 20) || $rapidFailure))));
            $title = $install
                ? ($approved ? 'Approved staff plugin change' : ($sequence ? 'Web plugin/theme change within minutes of a new-IP login' : 'Plugin, theme or ZIP change from a web session'))
                : ($strong ? 'Untrusted privileged login with attack or takeover evidence' : ($staffIp ? 'Staff login from a confirmed network address' : 'Privileged login from an IP absent from available history'));
            if ($event['actor_account_missing'] ?? false) {
                $title .= ' — actor account no longer exists';
            }
            $event += ['approved_install' => $approved, 'confirmed_staff_ip' => $staffIp, 'campaign_ip' => $ioc, 'followed_by_privileged_action' => $followedByAction];
            $hits[] = $this->hit($key, $title, 'aryo_activity_log', 'activity:'.$event['row_id'], $event,
                [$event['kind'], $approved ? 'approved_staff_install' : ($strong ? 'takeover_sequence' : 'ownership_review')], 'activity.behaviour', $event['user_id'], $strong ? 'strong' : 'needs_review', $strong ? 'critical' : (($approved || $staffIp) ? 'info' : 'warn'), $event['row_id']);
        }
        $days = [];
        foreach ($data['bursts'] ?? [] as $burst) {
            $day = isset($burst['day_bucket']) ? gmdate('Y-m-d', (int) $burst['day_bucket'] * 86400) : substr($burst['at_utc'] ?? '', 0, 10);
            $days[$day]['attempts'] = ($days[$day]['attempts'] ?? 0) + (int) $burst['n'];
            $days[$day]['ips'][] = ['ip' => $burst['ip'], 'attempts' => (int) $burst['n']];
        }
        foreach ($days as $day => $stats) {
            usort($stats['ips'], fn ($a, $b) => $b['attempts'] <=> $a['attempts']);
            $hits[] = $this->hit($key, 'Daily failed-login pressure across high-volume IPs', 'aryo_activity_log', 'burst:day:'.$day,
                ['at_utc' => $day.'T00:00:00Z', 'attempts' => $stats['attempts'], 'ip_count' => count($stats['ips']), 'top_ips' => array_slice($stats['ips'], 0, 10)], ['ip_login_burst', 'daily_aggregate'], 'activity.behaviour', null, 'strong', 'warn');
        }

        return $hits;
    }

    private function approvedInstall(string $name, array $approved): bool
    {
        $normalize = fn ($n) => strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', html_entity_decode($n)), '-'));
        $name = $normalize(preg_replace('/(?:[-_.]v?\d+(?:[._-]\d+)*)?\.zip$/i', '', $name));
        foreach ($approved as $entry) {
            if ($name === $normalize($entry)) {
                return true;
            }
        }

        return false;
    }

    private function registrationSpam(string $key, RuleSet $rules, array $c, array $p): array
    {
        $s = $c['users.registration_health']['data'] ?? [];
        $bursts = $c['activity.behaviour']['data']['registrations'] ?? [];
        $count = (int) ($s['suspicious'] ?? 0);
        $total = (int) ($s['total'] ?? 0);
        if ($count < $rules->threshold($key, 'min_accounts', 20) && $bursts === []) {
            return [];
        }
        usort($bursts, fn ($a, $b) => (int) $b['n'] <=> (int) $a['n']);
        $ioc = array_filter($bursts, fn ($b) => NetworkIndicators::matches((string) $b['ip'], $rules->list('ioc.ips', $key)));

        return [$this->hit($key, 'Registration spam or concentrated account creation', 'users', 'registration:market', [
            'suspicious_accounts' => $count, 'total_accounts' => $total, 'percentage' => $total > 0 ? round(100 * $count / $total, 2) : 0,
            'users_can_register' => $c['core_options']['data']['users_can_register'] ?? null,
            'top_registration_ips' => array_slice($bursts, 0, 10), 'ioc_registration_count' => array_sum(array_column($ioc, 'n')), 'registration_window' => '30 days ending at latest available activity',
        ], ['all_account_counts', 'registration_spam'], 'users.registration_health', null, 'strong', ($count >= 1000 || $ioc !== []) ? 'critical' : 'warn')];
    }

    private function accountCampaign(string $key, RuleSet $rules, array $c, array $p): array
    {
        $accounts = [];
        $data = $c['activity.behaviour']['data'] ?? [];
        $suspiciousKeyUsers = array_column(array_filter($c['app_passwords']['data'] ?? [], fn ($r) => ! ($r['privileged'] ?? true) && ! preg_match('/^(?:EWMS|Exotic[ -]CRM(?: Sync)?)$/i', $r['name'])), 'user_id');
        foreach (['login_windows', 'success_ips', 'control_ips', 'keyed_account_logins'] as $type) {
            foreach ($data[$type] ?? [] as $r) {
                $staff = HostContext::matches((string) ($r['email_domain'] ?? ''), $rules->list('allow.admin_email_domains', $key));
                $ioc = NetworkIndicators::matches((string) ($r['ip'] ?? ''), $rules->list('ioc.ips', $key));
                $rotating = $type === 'login_windows' && ! $staff && (int) $r['n'] >= 10 && (int) $r['ips'] >= 5 && ((int) $r['last_time'] - (int) $r['first_time']) / max(1, (int) $r['n'] - 1) <= 30;
                $control = $type === 'control_ips';
                $keyRotation = $type === 'keyed_account_logins' && ! $staff && in_array((int) $r['user_id'], $suspiciousKeyUsers, true);
                if (! $ioc && ! $rotating && ! $control && ! $keyRotation) {
                    continue;
                }
                $id = (int) $r['user_id'];
                $accounts[$id] ??= ['user_id' => $id, 'login' => $r['login'] ?? null, 'at_utc' => $r['at_utc'], 'ioc_successes' => 0, 'ips' => [], 'rotating_windows' => 0, 'control_failures' => 0, 'keyed_rotation' => null];
                $a = &$accounts[$id];
                $a['at_utc'] = min($a['at_utc'], $r['at_utc']);
                if ($ioc && $type === 'success_ips') {
                    $a['ioc_successes'] += (int) $r['n'];
                    $a['ips'][$r['ip']] = true;
                }
                $a['rotating_windows'] += $rotating ? 1 : 0;
                $a['control_failures'] += $control ? (int) $r['failures'] : 0;
                if ($keyRotation) {
                    $a['keyed_rotation'] = ['logins' => (int) $r['n'], 'distinct_ips' => (int) $r['ips'], 'window' => '30 days ending at latest available activity'];
                }
                if ($control && isset($r['ip'])) {
                    $a['ips'][$r['ip']] = true;
                }
                unset($a);
            }
        }
        $hits = [];
        foreach ($accounts as $id => $a) {
            $a['ips'] = array_slice(array_keys($a['ips']), 0, 30);
            $hits[] = $this->hit($key, 'Account logins match campaign or rapid rotating-IP behaviour', 'aryo_activity_log', 'campaign:account:'.$id, $a,
                array_values(array_filter([$a['ioc_successes'] ? 'campaign_ip_success' : null, $a['rotating_windows'] ? 'rotating_ip_login_burst' : null, $a['control_failures'] ? 'success_then_other_user_failures' : null, $a['keyed_rotation'] ? 'keyed_account_rotating_ips' : null])), 'activity.behaviour', $id, 'strong', 'critical');
        }

        return $hits;
    }

    private function targetedAccounts(string $key, RuleSet $rules, array $c, array $p): array
    {
        $accounts = [];
        $privileged = array_column($c['administrators']['data']['admins'] ?? [], 'id');
        $staffTokens = array_map(fn ($email) => \App\Services\DbScanner\Engine\InventoryCollector::hmac(strtolower($email)), array_merge($rules->list('allow.it_emails', $key), $rules->list('allow.admin_emails', $key)));
        foreach ($c['activity.behaviour']['data']['targeted_accounts'] ?? [] as $row) {
            if (! NetworkIndicators::matches((string) $row['ip'], $rules->list('ioc.ips', $key)) || in_array((int) $row['user_id'], $privileged, true)
                || in_array($row['email_token'] ?? '', $staffTokens, true)) {
                continue;
            }
            $id = (int) $row['user_id'];
            $accounts[$id] ??= ['user_id' => $id, 'login' => $row['login'] ?? null, 'attempts' => 0, 'ips' => [], 'at_utc' => $row['at_utc']];
            $accounts[$id]['attempts'] += (int) $row['n'];
            $accounts[$id]['ips'][] = $row['ip'];
        }

        return $accounts === [] ? [] : [$this->hit($key, 'Accounts under campaign targeting; no successful login in the available window', 'aryo_activity_log', 'targeted:market', [
            'account_count' => count($accounts), 'accounts' => array_slice(array_values($accounts), 0, 50), 'list_complete' => count($accounts) <= 50,
            'window' => '30 days ending at latest available activity', 'interpretation' => 'Failed attempts are targeting evidence, not proof of compromise. Accounts registered before this window and had no recorded successful login within it.',
        ], ['campaign_account_targeting'], 'activity.behaviour', null, 'strong', 'warn')];
    }

    private function historicalPrivilegedLogins(string $key, RuleSet $rules, array $c, array $p): array
    {
        $accounts = [];
        foreach ($c['email.wordfence_logins']['data']['logins'] ?? [] as $row) {
            $ioc = NetworkIndicators::matches($row['ip'], $rules->list('ioc.ips', $key));
            if (! $ioc && NetworkIndicators::matches($row['ip'], $rules->list('allow.staff_ips', $key))) {
                continue;
            }
            $name = strtolower($row['login']);
            $accounts[$name] ??= ['login' => $row['login'], 'user_id' => $row['user_id'], 'actor_account_missing' => $row['actor_account_missing'], 'alerts' => 0, 'ioc_alerts' => 0, 'ips' => [], 'samples' => [], 'at_utc' => $row['first_alert_utc']];
            $accounts[$name]['alerts'] += $row['alerts'];
            $accounts[$name]['ioc_alerts'] += $ioc ? $row['alerts'] : 0;
            $accounts[$name]['ips'][$row['ip']] = true;
            if (count($accounts[$name]['samples']) < 20) {
                $accounts[$name]['samples'][] = ['ip' => $row['ip'], 'location' => $row['location'], 'first_alert_utc' => $row['first_alert_utc'], 'last_alert_utc' => $row['last_alert_utc'], 'alerts' => $row['alerts']];
            }
            $accounts[$name]['at_utc'] = min($accounts[$name]['at_utc'], $row['first_alert_utc']);
        }
        $hits = [];
        foreach ($accounts as $name => $row) {
            $row['distinct_ips'] = count($row['ips']);
            $row['ips'] = array_slice(array_keys($row['ips']), 0, 30);
            $row['metadata_complete'] = $c['email.wordfence_logins']['complete'] ?? false;
            $row['source'] = $c['email.wordfence_logins']['data']['source'];
            $hits[] = $this->hit($key, 'Historical privileged-login alerts from unconfirmed addresses'.($row['actor_account_missing'] ? ' — actor account no longer exists' : ''), 'email_log', 'wordfence:'.hash('sha256', $name), $row,
                ['historical_admin_login_alert', $row['ioc_alerts'] ? 'campaign_ip_in_alert' : 'unconfirmed_login_address'], 'email.wordfence_logins', $row['user_id'], 'needs_review', $row['ioc_alerts'] ? 'critical' : 'warn');
        }

        return $hits;
    }

    private function emailDeliveryHealth(string $key, RuleSet $rules, array $c, array $p): array
    {
        $s = $c['activity.behaviour']['data']['email_health'] ?? [];
        $failed = (int) ($s['failed'] ?? 0);
        $total = $failed + (int) ($s['sent'] ?? 0);
        $highRate = $total >= $rules->threshold($key, 'min_messages', 20) && $failed / max(1, $total) >= $rules->threshold($key, 'failure_rate', 0.25);
        if (! $highRate && (int) ($s['smtp_auth'] ?? 0) === 0) {
            return [];
        }

        return [$this->hit($key, $highRate ? 'Email delivery failure rate is high' : 'SMTP authentication failures are recorded', 'aryo_activity_log', 'email:health', $s + ['high_failure_rate' => $highRate, 'low_sample_volume' => $total < $rules->threshold($key, 'min_messages', 20), 'failure_rate' => round($failed / max(1, $total), 4), 'window' => '30 days ending at latest available activity'], ['email_health', (int) ($s['smtp_auth'] ?? 0) > 0 ? 'smtp_authentication_failures' : 'delivery_failures'], 'activity.behaviour', null, 'strong', 'warn')];
    }

    private function openRegistration(string $key, RuleSet $rules, array $c, array $p): array
    {
        $o = $c['core_options']['data'] ?? null;
        if (! $o || (string) ($o['users_can_register'] ?? '0') !== '1') {
            return [];
        }
        $role = (string) ($o['default_role'] ?? 'subscriber');
        $roles = $o['user_roles'] ?? null;
        if ($roles === null || ! isset($roles[$role])) {
            return [$this->hit($key, 'Open registration with a default role that could not be classified', 'options', 'default_role', ['default_role' => $role], ['role_unknown'], 'options.core', null, 'needs_review', 'warn')];
        }
        if ($roles[$role]['privileged'] || $role === 'administrator') {
            return [$this->hit($key, 'Open registration grants a privileged default role', 'options', 'default_role', ['default_role' => $role, 'privileged_caps' => $roles[$role]['caps']], ['privileged_default_role'], 'options.core', null, 'strong')];
        }

        return [];
    }

    /**
     * Non-administrator roles that grant site control. Code-execution
     * capabilities (theme/plugin file editing, installs) are admin-equivalent.
     */
    private function rolePrivilegeRisk(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];
        foreach ($c['administrators']['data']['role_risk'] ?? [] as $role) {
            $code = $role['code_execution_caps'] ?? [];
            $title = $code !== []
                ? 'Non-administrator role can edit or install code ('.implode(', ', $code).')'
                : (($role['administrator_capability'] ?? false)
                    ? 'Non-administrator role grants the literal "administrator" capability'
                    : 'Non-administrator role holds site-control capabilities');
            $hits[] = $this->hit($key, $title, 'options', 'role:'.$role['role'], [
                'role' => $role['role'],
                'members' => $role['members'],
                'privileged_caps' => $role['privileged_caps'],
                'definition_hash' => hash('sha256', implode('|', (function ($caps) {
                    sort($caps);

                    return $caps;
                })($role['privileged_caps']))),
            ], array_map(fn ($cap) => 'cap:'.$cap, $role['privileged_caps']), 'users.privileged', null, 'needs_review', 'warn');
        }

        return $hits;
    }

    /**
     * Many accounts whose password resets go to a domain the network may not
     * control: www.<site>, near-misses of the site domain, or listed domains.
     */
    private function lookalikeEmailDomains(string $key, RuleSet $rules, array $c, array $p): array
    {
        $site = $rules->siteHost();
        $listed = array_map('strtolower', $rules->list('lexicon.placeholder_email_domains', $key));
        $min = (int) $rules->threshold($key, 'min_accounts', 10);
        $hits = [];
        foreach ($c['email_domains']['data'] ?? [] as $row) {
            $domain = strtolower((string) $row['domain']);
            $accounts = (int) $row['accounts'];
            if ($domain === 'www.'.$site) {
                $accounts -= (int) ($row['onboard_accounts'] ?? 0);
            }
            if ($domain === '' || $accounts < $min || $domain === $site) {
                continue;
            }
            $reason = null;
            if ($site !== '' && $domain === 'www.'.$site) {
                $reason = 'www_prefixed_site_domain';
            } elseif ($site !== '' && strlen($domain) > 4 && levenshtein($domain, $site) <= 2) {
                $reason = 'near_miss_of_site_domain';
            } elseif (in_array($domain, $listed, true)) {
                $reason = 'listed_placeholder_domain';
            }
            if ($reason) {
                $hits[] = $this->hit($key, 'Accounts use an email domain the network may not control', 'users', 'email_domain:'.$domain, [
                    'domain' => $domain, 'accounts' => $accounts, 'site' => $site, 'reason' => $reason,
                ], [$reason], 'users.email_domains', null, 'needs_review');
            }
        }

        return $hits;
    }

    private function loginAttackPressure(string $key, RuleSet $rules, array $c, array $p): array
    {
        $data = $c['failed_logins']['data'] ?? null;
        $threshold = (int) $rules->threshold($key, 'failed_7d', 1000);
        if (! $data || (int) $data['failed'] < $threshold) {
            return [];
        }

        return [$this->hit($key, 'Sustained failed-login pressure in the last 7 days', 'aryo_activity_log', 'failed_logins_7d', [
            'failed_logins' => (int) $data['failed'],
            'usernames_targeted' => (int) $data['usernames'],
            'max_ips_against_one_username' => (int) $data['max_ips_per_username'],
            'top_targets' => $data['top'],
            'threshold' => $threshold,
        ], ['credential_stuffing'], 'activity.failed_logins', null, null)];
    }

    // ------------------------------------------------------------------
    // Persistence
    // ------------------------------------------------------------------

    private function mysqlTriggers(string $key, RuleSet $rules, array $c, array $p): array
    {
        $approved = $rules->list('allow.trigger_hashes', $key);
        $hits = [];
        foreach ($c['triggers']['data'] ?? [] as $t) {
            if (in_array($t['definition_hash'], $approved, true)) {
                continue;
            }
            $known = (bool) preg_match('/^escort_live_url_sync/', (string) $t['name']);
            $title = $t['harmful'] ? 'Database trigger that rewrites accounts, options or content' : ($known ? 'Network trigger awaiting definition review' : 'Unexpected database trigger');
            $hits[] = $this->hit($key, $title, 'information_schema.TRIGGERS', 'trigger:'.$t['name'], [
                'trigger' => $t['name'], 'timing' => $t['timing'], 'event' => $t['event'], 'table' => $t['table'],
                'definition_sha256' => $t['body_complete'] ? $t['definition_hash'] : null,
                'excerpt' => $t['excerpt'],
            ], array_filter([$t['harmful'] ? 'harmful_body' : null, $known ? 'known_network_name' : null]), 'schema.triggers', null,
                $t['harmful'] ? 'strong' : 'needs_review',
                $t['harmful'] ? 'critical' : ($known ? 'info' : null));
        }

        return $hits;
    }

    private function mysqlEventsRoutines(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];
        foreach ($c['events_routines']['data'] ?? [] as $item) {
            $hits[] = $this->hit($key, 'Scheduled event or stored routine in the market schema', 'information_schema', strtolower($item['type']).':'.$item['name'], $item, ['schema_object:'.$item['type']], 'schema.events_routines', null, 'strong');
        }

        return $hits;
    }

    private function pluginNotAllowlisted(string $key, RuleSet $rules, array $c, array $p): array
    {
        $allow = $rules->list('allow.plugins', $key);
        $hits = [];
        foreach ($c['core_options']['data']['active_plugins'] ?? [] as $path) {
            $dir = str_contains($path, '/') ? strstr($path, '/', true) : $path;
            if ($this->listed($dir, $allow) || $this->listed($path, $allow)) {
                continue;
            }
            $public = in_array($dir, $rules->list('reference.public_plugins', $key), true);
            $ioc = in_array($dir, $rules->list('ioc.plugins', $key), true);
            $hits[] = $this->hit($key, $public ? 'Public repository plugin needs ownership review' : 'Active plugin not on the approved plugin list', 'options', 'plugin:'.$path, ['plugin' => $path, 'repository_reviewed' => $public], [$ioc ? 'campaign_plugin' : 'not_allowlisted'], 'options.core', null, $ioc ? 'strong' : 'needs_review', $ioc ? 'critical' : 'warn');
        }

        return $hits;
    }

    private function pluginPathSuspicious(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];
        foreach ($c['core_options']['data']['active_plugins'] ?? [] as $path) {
            $dir = str_contains($path, '/') ? strstr($path, '/', true) : '';
            $reason = null;
            if ($dir !== '' && str_starts_with($dir, '.')) {
                $reason = 'hidden directory';
            } elseif (preg_match('/^(wp-?(compat|core|config|system|update|security-core|cache-core)|wordpress-?(core|system)|core-?(update|compat))/i', $dir ?: $path)) {
                $reason = 'name imitates WordPress core';
            } elseif (! preg_match('/\.php$/i', $path)) {
                $reason = 'entry is not a PHP file';
            } elseif (str_contains($path, '..')) {
                $reason = 'path traversal';
            }
            if ($reason) {
                $hits[] = $this->hit($key, 'Active plugin entry with a suspicious path', 'options', 'plugin_path:'.$path, ['plugin' => $path, 'reason' => $reason], [str_replace(' ', '_', $reason)], 'options.core', null, 'needs_review');
            }
        }

        return $hits;
    }

    private function pluginThemeDrift(string $key, RuleSet $rules, array $c, array $p): array
    {
        if (! isset($p['core_options']['data'])) {
            return [];
        }
        $now = $c['core_options']['data'] ?? [];
        $was = $p['core_options']['data'];
        $hits = [];
        $added = array_diff($now['active_plugins'] ?? [], $was['active_plugins'] ?? []);
        $removed = array_diff($was['active_plugins'] ?? [], $now['active_plugins'] ?? []);
        foreach ($added as $plugin) {
            $hits[] = $this->hit($key, 'Plugin activated since the previous snapshot', 'options', 'activated:'.$plugin, ['plugin' => $plugin], ['plugin_activated'], 'options.core', null, 'needs_review');
        }
        foreach ($removed as $plugin) {
            $hits[] = $this->hit($key, 'Plugin deactivated since the previous snapshot', 'options', 'deactivated:'.$plugin, ['plugin' => $plugin], ['plugin_deactivated'], 'options.core', null, 'needs_review', 'info');
        }
        foreach (['template', 'stylesheet'] as $field) {
            if (($now[$field] ?? null) !== ($was[$field] ?? null) && ($was[$field] ?? null) !== null) {
                $hits[] = $this->hit($key, 'Active theme changed since the previous snapshot', 'options', 'theme:'.$field, ['field' => $field, 'before' => $was[$field], 'after' => $now[$field] ?? null], ['theme_changed'], 'options.core', null, 'needs_review');
            }
        }

        return $hits;
    }

    private function cronUnknownHooks(string $key, RuleSet $rules, array $c, array $p): array
    {
        $cron = $c['core_options']['data']['cron'] ?? null;
        if (! $cron || ! $cron['parsed']) {
            return [];
        }
        $allow = $rules->list('allow.cron_hooks', $key);
        $hits = [];
        foreach ($cron['payload_hooks'] ?? [] as $hook => $excerpt) {
            $hits[] = $this->hit($key, 'Cron event carrying code-like arguments', 'options', 'cron_payload:'.$hook, ['hook' => $hook, 'excerpt' => $excerpt], ['cron_payload'], 'options.core', null, 'strong', 'critical');
        }
        foreach (array_keys($cron['hooks'] ?? []) as $hook) {
            if ($this->listed((string) $hook, $allow) || isset($cron['payload_hooks'][$hook])) {
                continue;
            }
            $odd = (bool) preg_match('/^[a-f0-9]{16,}$|eval|base64|shell|backdoor/i', (string) $hook);
            $hits[] = $this->hit($key, $odd ? 'Cron hook with a random or code-like name' : 'Cron hook not on the approved list', 'options', 'cron:'.$hook, ['hook' => $hook, 'events' => $cron['hooks'][$hook]], [$odd ? 'odd_hook_name' : 'not_allowlisted'], 'options.core', null, 'needs_review');
            if (count($hits) >= 100) {
                break;
            }
        }

        return $hits;
    }

    private function cronVolume(string $key, RuleSet $rules, array $c, array $p): array
    {
        $cron = $c['core_options']['data']['cron'] ?? null;
        if (! $cron || ! $cron['parsed']) {
            return [];
        }
        $maxEvents = (int) $rules->threshold($key, 'max_events', 50);
        $maxDupes = (int) $rules->threshold($key, 'max_duplicates', 10);
        $hits = [];
        if ($cron['events'] > $maxEvents) {
            $hits[] = $this->hit($key, 'More scheduled cron events than the wp doctor threshold', 'options', 'cron_events', ['events' => $cron['events'], 'threshold' => $maxEvents], ['cron_volume'], 'options.core', null, null);
        }
        foreach ($cron['hooks'] as $hook => $n) {
            if ($n > $maxDupes) {
                $hits[] = $this->hit($key, 'Cron hook scheduled more times than the duplicate threshold', 'options', 'cron_dupes:'.$hook, ['hook' => $hook, 'count' => $n, 'threshold' => $maxDupes], ['cron_duplicates'], 'options.core', null, null);
            }
        }

        return $hits;
    }

    private function cronOverdue(string $key, RuleSet $rules, array $c, array $p): array
    {
        $cron = $c['core_options']['data']['cron'] ?? null;
        if (! $cron || ! $cron['parsed'] || (int) $cron['overdue'] === 0) {
            return [];
        }

        return [$this->hit($key, 'Cron events overdue by more than 24 hours (WP-Cron may not be running)', 'options', 'cron_overdue', ['overdue_events' => $cron['overdue']], ['cron_overdue'], 'options.core', null, null)];
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    private function siteUrlMismatch(string $key, RuleSet $rules, array $c, array $p): array
    {
        $site = $rules->siteHost();
        $o = $c['core_options']['data'] ?? [];
        $hits = [];
        if ($site === '') {
            return [];
        }
        foreach (['siteurl', 'home'] as $field) {
            $host = HostContext::hostOf((string) ($o[$field] ?? ''));
            if ($host !== '' && $host !== $site) {
                $hits[] = $this->hit($key, '`'.$field.'` points to a different host than the platform domain', 'options', 'url:'.$field, ['option' => $field, 'host' => $host, 'expected' => $site], ['site_url_mismatch'], 'options.core', null, null);
            }
        }

        return $hits;
    }

    private function searchEnginesBlocked(string $key, RuleSet $rules, array $c, array $p): array
    {
        if ((string) ($c['core_options']['data']['blog_public'] ?? '1') !== '0') {
            return [];
        }

        return [$this->hit($key, 'Search engines are discouraged (blog_public = 0)', 'options', 'blog_public', ['blog_public' => '0'], ['blog_public_off'], 'options.core', null, null)];
    }

    private function adminEmailDrift(string $key, RuleSet $rules, array $c, array $p): array
    {
        $now = $c['core_options']['data']['admin_email_token'] ?? null;
        $was = $p['core_options']['data']['admin_email_token'] ?? null;
        if ($was === null || $now === null || $now === $was) {
            return [];
        }

        return [$this->hit($key, 'Site admin email changed since the previous snapshot', 'options', 'admin_email', [
            'before' => $p['core_options']['data']['admin_email_masked'] ?? null,
            'after' => $c['core_options']['data']['admin_email_masked'] ?? null,
        ], ['admin_email_changed'], 'options.core', null, null)];
    }

    private function uploadPathChanged(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];
        foreach (['upload_path', 'upload_url_path'] as $field) {
            $value = trim((string) ($c['core_options']['data'][$field] ?? ''));
            if ($value !== '') {
                $hits[] = $this->hit($key, '`'.$field.'` is set', 'options', 'upload:'.$field, ['option' => $field, 'value' => mb_substr($value, 0, 160)], ['upload_path_set'], 'options.core', null, null);
            }
        }

        return $hits;
    }

    private function yoastCrawlBaseline(string $key, RuleSet $rules, array $c, array $p): array
    {
        $wpseo = $c['core_options']['data']['wpseo'] ?? null;
        if (! is_array($wpseo)) {
            return [];
        }
        $expected = [
            'remove_feed_search' => true, 'deny_search_crawling' => true, 'redirect_search_pretty_urls' => true,
            'search_cleanup_emoji' => false, 'search_cleanup_patterns' => false, 'clean_permalinks' => true,
        ];
        $drift = [];
        foreach ($expected as $field => $value) {
            if (array_key_exists($field, $wpseo) && (bool) $wpseo[$field] !== $value) {
                $drift[$field] = ['is' => (bool) $wpseo[$field], 'expected' => $value];
            }
        }

        return $drift === [] ? [] : [$this->hit($key, 'Yoast crawl settings differ from the network baseline', 'options', 'wpseo_crawl', ['differences' => $drift], array_map(fn ($f) => 'setting:'.$f, array_keys($drift)), 'options.core', null, null)];
    }

    private function permalinkDrift(string $key, RuleSet $rules, array $c, array $p): array
    {
        if (! isset($p['core_options']['data'])) {
            return [];
        }
        $hits = [];
        foreach (['permalink_structure', 'taxonomy_profile_url'] as $field) {
            $now = $c['core_options']['data'][$field] ?? null;
            $was = $p['core_options']['data'][$field] ?? null;
            if ($was !== null && $now !== $was) {
                $hits[] = $this->hit($key, 'Permalink setting changed since the previous snapshot', 'options', 'permalink:'.$field, ['option' => $field, 'before' => $was, 'after' => $now], ['permalink_changed'], 'options.core', null, null);
            }
        }

        return $hits;
    }

    private function externalRedirects(string $key, RuleSet $rules, array $c, array $p): array
    {
        $network = array_merge($rules->list('allow.outbound_domains'), (array) ($rules->config['network_hosts'] ?? []), [$rules->siteHost()]);
        $hits = [];
        foreach ($c['core_options']['data']['redirects'] ?? [] as $r) {
            if (HostContext::matches($r['host'], $network)) {
                continue;
            }
            $partner = (bool) preg_match('~^/?partners(?:/|$)~i', (string) $r['origin']);
            $hits[] = $this->hit($key, $partner ? 'Partner link-swap redirect needs business review' : 'Yoast redirect sends a site path to an external domain', 'options', 'redirect:'.$r['origin'], $r, [$partner ? 'partner_link_swap' : 'external_redirect', 'host:'.$r['host']], 'options.core', null, $partner ? 'needs_review' : 'strong', $partner ? 'info' : null);
            if (count($hits) >= 100) {
                break;
            }
        }

        return $hits;
    }

    // ------------------------------------------------------------------
    // Hygiene and volume
    // ------------------------------------------------------------------

    private function autoloadSize(string $key, RuleSet $rules, array $c, array $p): array
    {
        $data = $c['autoload']['data'] ?? null;
        $max = (int) $rules->threshold($key, 'max_bytes', 921600);
        if (! $data || $data['bytes'] <= $max) {
            return [];
        }

        return [$this->hit($key, 'Autoloaded options exceed the size threshold', 'options', 'autoload_total', ['bytes' => $data['bytes'], 'options' => $data['count'], 'threshold' => $max, 'largest' => $data['top']], ['autoload_size'], 'options.autoload', null, null)];
    }

    private function massCreatedPosts(string $key, RuleSet $rules, array $c, array $p): array
    {
        $rows = $c['daily_volume']['data'] ?? null;
        if (! $rows) {
            return [];
        }
        $minPosts = (int) $rules->threshold($key, 'min_posts', 50);
        $factor = (float) $rules->threshold($key, 'factor', 10);
        $byType = [];
        foreach ($rows as $r) {
            $byType[$r['type']][] = $r;
        }
        $hits = [];
        foreach ($byType as $type => $days) {
            $counts = array_column($days, 'n');
            sort($counts);
            $median = $counts[intdiv(count($counts), 2)] ?? 0;
            foreach ($days as $r) {
                if ($r['n'] >= $minPosts && $r['n'] >= $factor * max(1, $median)) {
                    $hits[] = $this->hit($key, 'Unusual burst of published posts in one day', 'posts', 'burst:'.$type.':'.$r['d'], ['date' => $r['d'], 'post_type' => $type, 'published' => $r['n'], 'daily_median' => $median], ['publish_burst'], 'posts.daily_volume', null, null, $r['n'] >= 4 * $minPosts ? 'critical' : 'warn');
                }
            }
        }

        return $hits;
    }

    private function orphanAuthors(string $key, RuleSet $rules, array $c, array $p): array
    {
        $hits = [];
        foreach ($c['orphan_authors']['data'] ?? [] as $r) {
            $hits[] = $this->hit($key, 'Published content whose author account does not exist', 'posts', 'orphan_author:'.$r['type'], ['post_type' => $r['type'], 'posts' => $r['n']], ['orphan_author'], 'posts.orphan_authors', null, null);
        }

        return $hits;
    }

    private function expiredTransients(string $key, RuleSet $rules, array $c, array $p): array
    {
        $n = (int) ($c['expired_transients']['data']['count'] ?? 0);
        $max = (int) $rules->threshold($key, 'max', 1000);

        return $n > $max ? [$this->hit($key, 'Expired transients above the threshold', 'options', 'expired_transients', ['count' => $n, 'threshold' => $max], ['expired_transients'], 'options.transients_expired', null, null)] : [];
    }

    private function revisionBloat(string $key, RuleSet $rules, array $c, array $p): array
    {
        $rows = $c['revision_counts']['data'] ?? [];

        return $rows === [] ? [] : [$this->hit($key, 'Posts with more than 25 revisions', 'posts', 'revision_bloat', ['top' => $rows], ['revision_bloat'], 'posts.revision_counts', null, null)];
    }

    private function slugAliases(string $key, RuleSet $rules, array $c, array $p): array
    {
        $n = (int) ($c['slug_aliases']['data']['shared'] ?? 0);

        return $n > 0 ? [$this->hit($key, 'Old profile slugs held by more than one profile (their old URLs return 404)', 'postmeta', 'slug_aliases', ['shared_old_slugs' => $n, 'post_type' => $c['slug_aliases']['data']['post_type'] ?? null], ['ambiguous_slugs'], 'postmeta.slug_aliases', null, null)] : [];
    }

    private function orphanedMeta(string $key, RuleSet $rules, array $c, array $p): array
    {
        $n = (int) ($c['orphan_postmeta']['data']['count'] ?? 0);
        $max = (int) $rules->threshold($key, 'max', 1000);

        return $n > $max ? [$this->hit($key, 'Postmeta rows pointing at posts that no longer exist', 'postmeta', 'orphan_postmeta', ['count' => $n, 'threshold' => $max], ['orphaned_meta'], 'postmeta.orphans', null, null)] : [];
    }

    private function actionSchedulerBacklog(string $key, RuleSet $rules, array $c, array $p): array
    {
        $by = $c['action_scheduler']['data'] ?? null;
        if (! $by) {
            return [];
        }
        $failed = (int) ($by['failed']['n'] ?? 0);
        $stale = (int) ($by['pending']['old'] ?? 0);
        $max = (int) $rules->threshold($key, 'max', 100);

        return ($failed > $max || $stale > $max) ? [$this->hit($key, 'Action Scheduler backlog', 'actionscheduler_actions', 'backlog', ['failed' => $failed, 'pending_older_than_7_days' => $stale, 'threshold' => $max], ['action_scheduler_backlog'], 'actionscheduler.summary', null, null)] : [];
    }

    private function unexpectedTables(string $key, RuleSet $rules, array $c, array $p): array
    {
        $data = $c['tables']['data'] ?? null;
        if (! $data) {
            return [];
        }
        $allow = $rules->list('allow.tables', $key);
        $prefix = (string) $data['prefix'];
        $hits = [];
        foreach ($data['custom'] as $table) {
            $suffix = substr($table, strlen($prefix));
            if ($this->listed($suffix, $allow)) {
                continue;
            }
            $hits[] = $this->hit($key, 'Table that is not core, a known plugin\'s or allowlisted', 'information_schema.TABLES', 'table:'.$table, ['table' => $table, 'bytes' => $data['tables'][$table]['bytes'] ?? null], ['unknown_table'], 'schema.tables', null, 'needs_review');
        }
        foreach ($data['unprefixed'] as $table) {
            if ($this->listed($table, $allow)) {
                continue;
            }
            $parallel = (bool) preg_match('/^(backup|bak|old|copy|tmp)[_a-z0-9]*_(posts|options|users|usermeta)$/i', $table);
            $hits[] = $this->hit($key, $parallel ? 'Parallel copy of a WordPress table' : 'Table outside the site prefix', 'information_schema.TABLES', 'table:'.$table, ['table' => $table, 'bytes' => $data['tables'][$table]['bytes'] ?? null], [$parallel ? 'parallel_table' : 'unprefixed_table'], 'schema.tables', null, 'needs_review');
            if (count($hits) >= 100) {
                break;
            }
        }

        return $hits;
    }

    private function tableIntegrity(string $key, RuleSet $rules, array $c, array $p): array
    {
        $data = $c['tables']['data'] ?? null;
        if (! $data) {
            return [];
        }
        $hits = [];
        if ($data['no_primary_key'] !== []) {
            $hits[] = $this->hit($key, 'Tables without a primary key', 'information_schema.TABLES', 'no_primary_key', ['tables' => array_slice($data['no_primary_key'], 0, 30)], ['no_primary_key'], 'schema.tables', null, null);
        }
        $myisam = array_keys(array_filter($data['tables'], fn ($t) => strtolower((string) ($t['engine'] ?? '')) === 'myisam'));
        if ($myisam !== []) {
            $hits[] = $this->hit($key, 'MyISAM tables (no transactions or crash recovery)', 'information_schema.TABLES', 'myisam', ['tables' => array_slice($myisam, 0, 30)], ['myisam'], 'schema.tables', null, null);
        }
        $collations = array_values(array_unique(array_filter(array_column($data['tables'], 'collation'))));
        if (count($collations) > 1) {
            $hits[] = $this->hit($key, 'Mixed table collations', 'information_schema.TABLES', 'collations', ['collations' => array_slice($collations, 0, 10)], ['mixed_collations'], 'schema.tables', null, null);
        }

        return $hits;
    }

    private function tableGrowth(string $key, RuleSet $rules, array $c, array $p): array
    {
        $now = $c['tables']['data']['tables'] ?? null;
        $was = $p['tables']['data']['tables'] ?? null;
        if (! $now || ! $was) {
            return [];
        }
        $factor = (float) $rules->threshold($key, 'factor', 2.0);
        $minBytes = (int) $rules->threshold($key, 'min_bytes', 10 * 1024 * 1024);
        $hits = [];
        foreach ($now as $table => $meta) {
            $after = (int) ($meta['bytes'] ?? 0);
            $before = (int) ($was[$table]['bytes'] ?? 0);
            if ($before > 0 && $after >= $minBytes && $after > $before * $factor) {
                $hits[] = $this->hit($key, 'Table grew sharply since the previous snapshot (estimate)', 'information_schema.TABLES', 'growth:'.$table, ['table' => $table, 'bytes_before_estimate' => $before, 'bytes_now_estimate' => $after, 'factor' => $factor], ['table_growth'], 'schema.tables', null, null);
            }
        }

        return $hits;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Exact match, or a reviewed prefix entry ending in "*".
     *
     * @param  array<int, string>  $list
     */
    private function listed(string $value, array $list): bool
    {
        $value = strtolower($value);
        foreach ($list as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }
            if (str_ends_with($entry, '*') ? str_starts_with($value, rtrim($entry, '*')) : $value === $entry) {
                return true;
            }
        }

        return false;
    }

    private function hit(
        string $key,
        string $title,
        string $table,
        string $item,
        array $details,
        array $signals,
        string $surface,
        int|string|null $objectId,
        ?string $confidence,
        ?string $severity = null,
        int|string|null $rowId = null,
    ): Hit {
        return new Hit(
            $key,
            $title,
            array_filter([
                'surface' => $surface,
                'table' => $table,
                'row_id' => $rowId,
                'item' => mb_substr($item, 0, 190),
                'object_type' => str_starts_with($table, 'information_schema') ? 'schema' : ($table === 'options' ? 'option' : 'record'),
                'object_id' => $objectId,
            ], fn ($v) => $v !== null && $v !== ''),
            ['details' => $details, 'signals' => array_values($signals), 'excerpts' => isset($details['excerpt']) ? [$details['excerpt']] : []],
            $confidence,
            null,
            $severity,
        );
    }
}
