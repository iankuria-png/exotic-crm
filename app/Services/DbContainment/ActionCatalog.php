<?php

namespace App\Services\DbContainment;

use App\Models\DbScanFinding;
use App\Services\DbScanner\Evidence\EvidenceSanitizer;
use App\Services\DbScanner\Malware\SerializedParser;

class ActionCatalog
{
    public function __construct(private readonly ContainmentCrypto $crypto, private readonly ContainmentPolicy $policy) {}

    public function offered(DbScanFinding $finding): array
    {
        if (! in_array($finding->status, ['open', 'acknowledged'], true)) {
            return [];
        }

        return match ($finding->rule_key) {
            'access.application_passwords' => ['revoke_app_password', 'lock_account', 'end_sessions'],
            'access.account_campaign' => ['lock_account', 'end_sessions'],
            'access.activity_behaviour', 'access.historical_privileged_logins' => ['end_sessions'],
            'access.hidden_admin_capabilities' => in_array('legacy_user_level', $finding->evidence['signals'] ?? [], true) ? ['clear_user_level'] : [],
            'content.hidden_text' => ($finding->subject['table'] ?? '') === 'options' && str_starts_with($finding->evidence['details']['exposure'] ?? '', 'rendered_widget:') ? ['remove_hidden_link'] : [],
            'persistence.invisible_plugins' => ['deactivate_dangling_plugin_entry'],
            default => [],
        };
    }

    public function selector(DbScanFinding $finding, array $actions, array $identity): array
    {
        if ($actions === [] || array_diff($actions, $this->offered($finding))) {
            throw new ContainmentException('action_not_supported_for_finding');
        }
        $user = (int) ($finding->evidence['details']['user_id'] ?? $finding->subject['object_id'] ?? 0);
        if (array_intersect($actions, ['lock_account', 'revoke_app_password', 'end_sessions', 'clear_user_level'])) {
            if ($user < 1) {
                throw new ContainmentException('deleted_or_ambiguous_actor');
            }

            return ['user_ids' => [$user], 'option_names' => [$identity['prefix'].'user_roles']];
        }
        if (in_array('remove_hidden_link', $actions, true)) {
            $option = $finding->subject['item'] ?? $finding->evidence['details']['option'] ?? '';

            // Resolve option by fixed subject row ID in the service; never accept a client option name.
            return ['option_names' => ['widget_text', 'widget_custom_html', 'widget_block', 'sidebars_widgets'], 'finding_row_id' => (int) ($finding->subject['row_id'] ?? 0)];
        }

        return ['option_names' => ['active_plugins']];
    }

    public function parse(string $raw): array
    {
        $parser = new SerializedParser(30000, 24);
        $data = $parser->parse($raw);
        if (! is_array($data) || $parser->error || $parser->classes || $parser->hasReferences || $parser->hasCustom) {
            throw new ContainmentException('malformed_or_object_metadata');
        }

        return $data;
    }

    public function plan(DbScanFinding $finding, array $actions, array $identity, array $before, array $config): array
    {
        $diff = [];
        $after = $before;
        $lines = [];
        $privileged = false;
        $users = [];
        $options = [];
        $prefix = $identity['prefix'];
        $detail = $finding->evidence['details'] ?? [];
        if (isset($before['users'])) {
            if (count($before['users']) !== 1) {
                throw new ContainmentException('ambiguous_account_set');
            }
            $user = $before['users'][0];
            $id = (int) $user['ID'];
            $users = [$id];
            $metadata = array_filter($before['usermeta'], fn ($r) => (int) $r['user_id'] === $id);
            $rolesRow = collect($before['options'])->firstWhere('option_name', $prefix.'user_roles');
            $roles = $this->parse($rolesRow['option_value']);
            foreach ($metadata as $row) {
                if ($row['meta_key'] === $prefix.'capabilities') {
                    foreach ($this->parse($row['meta_value']) as $cap => $granted) {
                        if (! $granted) {
                            continue;
                        }
                        $caps = $roles[$cap]['capabilities'] ?? [$cap => true];
                        foreach (['manage_options', 'edit_users', 'create_users', 'delete_users', 'edit_plugins', 'install_plugins', 'edit_themes', 'unfiltered_html', 'unfiltered_upload', 'administrator', 'level_10'] as $power) {
                            if (! empty($caps[$power])) {
                                $privileged = true;
                            }
                        }
                    }
                }
            }
            $protected = $this->policy->protectedUser($user, $config['protected_emails'] ?? []);
            if ($protected && array_diff($actions, ['end_sessions'])) {
                throw new ContainmentException('protected_staff_identity');
            }
            $privileged = $privileged || $protected;
            if (! empty($detail['login']) && strtolower($detail['login']) !== strtolower($user['user_login'])) {
                throw new ContainmentException('actor_identity_changed');
            }
            if ($finding->rule_key === 'access.application_passwords') {
                $this->resolveKeys($finding, $identity, $metadata);
            } // also binds offered lock to the observed account
            if ($finding->rule_key === 'access.account_campaign' && strtotime($user['user_registered'].' UTC') >= strtotime($detail['at_utc'] ?? $finding->first_seen_at->toIso8601String())) {
                throw new ContainmentException('account_identity_unbound');
            }
            if (in_array('lock_account', $actions, true)) {
                $after['users'][0]['user_pass'] = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                $after['users'][0]['user_activation_key'] = '';
                $sessionCount = $this->sessionCount($metadata);
                $lines[] = "LOCK #$id ".$user['user_login']." — new random password (nobody knows it), clear reset key, end $sessionCount session(s)";
            }
            if (array_intersect($actions, ['lock_account', 'end_sessions'])) {
                $after['usermeta'] = array_values(array_filter($after['usermeta'], fn ($r) => $r['meta_key'] !== 'session_tokens'));
                if (in_array('end_sessions', $actions, true)) {
                    $lines[] = 'END SESSIONS #'.$id.' '.$user['user_login'].' — '.$this->sessionCount($metadata).' session(s)';
                }
            }
            if (in_array('revoke_app_password', $actions, true)) {
                $keys = $this->resolveKeys($finding, $identity, $metadata);
                foreach ($after['usermeta'] as &$row) {
                    if ((int) $row['umeta_id'] === $keys['row_id']) {
                        $row['meta_value'] = serialize(array_values(array_filter($keys['all'], fn ($k) => ! in_array($k['uuid'], $keys['uuids'], true))));
                    }
                }
                unset($row);
                $lines[] = 'REVOKE #'.$id.' '.$user['user_login'].' — application password "'.(new EvidenceSanitizer(120))->clean($keys['name']).'" ('.count($keys['uuids']).')';
            }
            if (in_array('clear_user_level', $actions, true)) {
                if ($privileged) {
                    throw new ContainmentException('current_capabilities_still_privileged');
                }
                $n = 0;
                foreach ($after['usermeta'] as &$row) {
                    if ($row['meta_key'] === $prefix.'user_level') {
                        $row['meta_value'] = '0';
                        $n++;
                    }
                } unset($row);
                if (! $n) {
                    throw new ContainmentException('user_level_no_longer_present');
                }
                $lines[] = "CLEAR USER LEVEL #$id — $n row(s) to 0";
            }
        } elseif (in_array('remove_hidden_link', $actions, true)) {
            $hostRe = implode('|', array_map(fn ($h) => preg_quote($h, '#'), config('db_containment.hidden_link_hosts')));
            $pattern = '#<div\s+style="[^"]*position:\s*absolute;[^"]*left:\s*-\d{3,}px;?[^"]*">\s*<a\s+href="https?://(?:www\.)?(?:'.$hostRe.')/?"[^>]*>[^<]*</a>\s*</div>(?:\r?\n)?#i';
            $target = (int) ($finding->subject['row_id'] ?? 0);
            $removed = 0;
            foreach ($after['options'] as &$row) {
                if ((int) $row['option_id'] !== $target || ! in_array($row['option_name'], ['widget_text', 'widget_custom_html', 'widget_block'], true)) {
                    continue;
                }
                $sidebars = $this->parse(collect($before['options'])->firstWhere('option_name', 'sidebars_widgets')['option_value']);
                $data = $this->parse($row['option_value']);
                $field = $row['option_name'] === 'widget_text' ? 'text' : 'content';
                foreach ($data as $widget => &$value) {
                    if (! is_array($value) || ! is_string($value[$field] ?? null)) {
                        continue;
                    }
                    $widgetId = str_replace('widget_', '', $row['option_name']).'-'.$widget;
                    $placement = [];
                    foreach ($sidebars as $sidebar => $widgets) {
                        if ($sidebar !== 'wp_inactive_widgets' && is_array($widgets) && in_array($widgetId, $widgets, true)) {
                            $placement[] = $sidebar;
                        }
                    }
                    if (! $placement) {
                        continue;
                    }
                    $oldContent = $value[$field];
                    preg_match_all($pattern, $value[$field], $matches);
                    foreach ($matches[0] as $block) {
                        $lines[] = 'REMOVE '.$row['option_name'].'-'.$widget.' — '.(new EvidenceSanitizer(500))->clean($block);
                        $removed++;
                    }
                    $value[$field] = preg_replace($pattern, '', $value[$field]);
                    if ($matches[0]) {
                        $diff[] = ['widget' => $widgetId, 'sidebars' => $placement, 'before' => (new EvidenceSanitizer(2000))->clean($oldContent), 'after' => (new EvidenceSanitizer(2000))->clean($value[$field])];
                    }
                }unset($value);
                $row['option_value'] = serialize($data);
                $options[] = $row['option_name'];
            }unset($row);
            if (! $removed) {
                throw new ContainmentException('proven_hidden_link_no_longer_present');
            }
        } else {
            $path = $detail['plugin'] ?? '';
            if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+\.php$#D', $path) || str_contains($path, '..')) {
                throw new ContainmentException('ambiguous_plugin_path');
            }
            $proof = $config['disk_absence_proofs'][$path] ?? null;
            if (! $proof || strtotime($proof['checked_at'] ?? '') < time() - 900 || empty($proof['checked_by'])) {
                throw new ContainmentException('recent_independent_disk_absence_proof_required');
            }
            foreach ($after['options'] as &$row) {
                $plugins = $this->parse($row['option_value']);
                if (! in_array($path, $plugins, true)) {
                    throw new ContainmentException('plugin_entry_no_longer_active');
                }
                $row['option_value'] = serialize(array_values(array_diff($plugins, [$path])));
            }unset($row);
            $options = ['active_plugins'];
            $lines[] = 'DEACTIVATE dangling entry '.$path.' — no file deletion';
        }

        return ['diff' => $diff, 'after' => $after, 'lines' => $lines, 'privileged' => $privileged, 'cache' => ['user_ids' => $users, 'option_names' => $options, 'session_check' => (bool) array_intersect($actions, ['lock_account', 'end_sessions']), 'content_check' => $options !== []]];
    }

    public function mergeChanges(array $original, array $planned, array $current): array
    {
        foreach (['users' => 'ID', 'usermeta' => 'umeta_id'] as $table => $pk) {
            $old = array_column($original[$table], null, $pk);
            $new = array_column($planned[$table], null, $pk);
            $actual = array_column($current[$table], null, $pk);
            foreach ($old as $id => $row) {
                if (! isset($new[$id])) {
                    unset($actual[$id]);

                    continue;
                }
                foreach ($new[$id] as $column => $value) {
                    if ($value === $row[$column] || ! isset($actual[$id])) {
                        continue;
                    }
                    if ($table === 'usermeta' && $row['meta_key'] === '_application_passwords' && $column === 'meta_value') {
                        $before = $this->parse($row[$column]);
                        $wanted = $this->parse($value);
                        $removed = array_diff(array_column($before, 'uuid'), array_column($wanted, 'uuid'));
                        $value = serialize(array_values(array_filter($this->parse($actual[$id][$column]), fn ($key) => ! in_array($key['uuid'], $removed, true))));
                    }
                    $actual[$id][$column] = $value;
                }
            }
            $current[$table] = array_values($actual);
        }

        return $current;
    }

    private function sessionCount(array $metadata): int
    {
        $n = 0;
        foreach ($metadata as $row) {
            if ($row['meta_key'] === 'session_tokens') {
                $n += count($this->parse($row['meta_value']));
            }
        }

        return $n;
    }

    public function resolveKeys(DbScanFinding $finding, array $identity, array $metadata): array
    {
        $binding = $finding->evidence['details']['key_binding'] ?? null;
        if (! $binding || ! ($binding['complete'] ?? false) || ($binding['version'] ?? '') !== (string) config('db_containment.key_version')) {
            throw new ContainmentException('fresh_provenance_scan_required');
        }
        $rows = array_values(array_filter($metadata, fn ($r) => $r['meta_key'] === '_application_passwords'));
        if (count($rows) !== 1 || (int) $rows[0]['umeta_id'] !== (int) $finding->subject['row_id']) {
            throw new ContainmentException('ambiguous_key_metadata');
        }
        $all = $this->parse($rows[0]['meta_value']);
        $uuids = [];
        $groups = [];
        foreach ($all as $key) {
            if (! is_array($key) || ! is_string($key['uuid'] ?? null) || ! preg_match('/^[a-f0-9-]{36}$/iD', $key['uuid']) || isset($uuids[$key['uuid']])) {
                throw new ContainmentException('invalid_or_duplicate_key_uuid');
            }
            $uuids[$key['uuid']] = true;
            $name = $key['name'] ?? '';
            if (! is_string($name)) {
                throw new ContainmentException('invalid_key_name');
            }
            $groups[$name][] = $key;
        }
        $displays = [];
        foreach ($groups as $name => $keys) {
            $displays[(new EvidenceSanitizer(120))->clean((string) $name)][] = $name;
        }
        foreach ($groups as $name => $keys) {
            if (count($displays[(new EvidenceSanitizer(120))->clean((string) $name)]) !== 1) {
                throw new ContainmentException('ambiguous_key_display');
            }
            $nameId = $this->crypto->digest([$identity['host'], $identity['schema'], $identity['prefix'], (int) $rows[0]['user_id'], (int) $rows[0]['umeta_id'], $name], 'key-name');
            if (! hash_equals($binding['name_id'] ?? '', $nameId)) {
                continue;
            }
            if ($this->policy->protectedKey($name)) {
                throw new ContainmentException('protected_integration_key');
            }
            $digests = array_map(fn ($key) => $this->crypto->keyProvenance([$identity['host'], $identity['schema'], $identity['prefix']], (int) $rows[0]['user_id'], (int) $rows[0]['umeta_id'], $name, $key['uuid']), $keys);
            sort($digests);
            if ($digests !== $binding['digests'] || count($keys) !== $binding['count']) {
                throw new ContainmentException('key_identity_changed_since_detection');
            }

            return ['row_id' => (int) $rows[0]['umeta_id'], 'name' => $name, 'uuids' => array_column($keys, 'uuid'), 'all' => $all];
        }
        throw new ContainmentException('key_identity_changed_since_detection');
    }
}
