<?php

namespace App\Services\DbScanner;

use App\Models\DbScanFinding;
use Illuminate\Database\Eloquent\Builder;

/** Presentation only: does not change detection, severity or native disposition. */
class FindingInterpretation
{
    public const GROUP_CAP = 10000;

    public const FAMILIES = [
        'login_campaign' => ['Login campaigns', 'Review linked accounts and verify current privileges, sessions and keys. Retained history can match after a fix.'],
        'confirmed_payload' => ['Confirmed database indicators', 'Review the exact matched database indicator and remediate its subject; verify with compatible coverage.'],
        'stored_script' => ['Stored scripts', 'Confirm the script owner, placement and public execution. Back up and remove unapproved or obsolete code.'],
        'permissions' => ['Role and permission risks', 'Compare exact permissions with approved duties; remove unnecessary privileges and verify staff workflows.'],
        'failed_attempt' => ['Failed login attempts', 'Review current login protection and associated successes. Failed attempts alone do not establish compromise.'],
        'activity_record' => ['Activity records', 'Confirm the recorded actor and action. An informational activity record does not establish compromise.'],
        'access_review' => ['Access requiring review', 'Confirm account or integration ownership and the exact access risk before changing credentials or permissions.'],
        'database_indicator' => ['Other database indicators', 'Review the exact evidence and execution context before concluding that a payload is malicious.'],
        'persistence' => ['Persistence', 'Review the exact plugin, scheduled task or database object and its approved purpose.'],
        'content' => ['Content integrity', 'Verify ownership and the affected content, repair the exact subject and recheck.'],
        'config' => ['Configuration', 'Compare the setting with the approved market policy and verify the correction.'],
        'seo' => ['SEO and links', 'Review the exact link or setting against the market policy; retain approved partner exchanges.'],
        'hygiene' => ['Database hygiene', 'Review the measured database condition and its operational impact before changing data.'],
        'other' => ['Other findings', 'Review the finding guidance and its exact evidence.'],
    ];

    /** A portable SQL CASE shared by filters, summaries and presentation. */
    public static function familySql(): string
    {
        return "CASE
            WHEN rule_key IN ('malware.reinfection_cluster', 'access.account_campaign') THEN 'login_campaign'
            WHEN category = 'malware' AND confidence = 'confirmed' THEN 'confirmed_payload'
            WHEN rule_key = 'malware.remote_loader' OR behavior IN ('stored_loader', 'third_party_widget', 'injected_loader', 'third_party_execution') THEN 'stored_script'
            WHEN rule_key IN ('access.role_grants_admin_capability', 'access.hidden_admin_capabilities', 'access.admin_count_drift') THEN 'permissions'
            WHEN rule_key IN ('access.login_attack_pressure', 'access.targeted_accounts') OR (rule_key = 'access.activity_behaviour' AND title = 'Daily failed-login pressure across high-volume IPs') THEN 'failed_attempt'
            WHEN category = 'access' AND severity = 'info' THEN 'activity_record'
            WHEN category = 'access' THEN 'access_review'
            WHEN category = 'malware' THEN 'database_indicator'
            WHEN category IN ('persistence', 'content', 'config', 'seo', 'hygiene') THEN category
            ELSE 'other' END";
    }

    public static function family(DbScanFinding $f): string
    {
        if (in_array($f->rule_key, ['malware.reinfection_cluster', 'access.account_campaign'], true)) {
            return 'login_campaign';
        }
        if ($f->category === 'malware' && $f->confidence === 'confirmed') {
            return 'confirmed_payload';
        }
        if ($f->rule_key === 'malware.remote_loader' || in_array($f->behavior, ['stored_loader', 'third_party_widget', 'injected_loader', 'third_party_execution'], true)) {
            return 'stored_script';
        }
        if (in_array($f->rule_key, ['access.role_grants_admin_capability', 'access.hidden_admin_capabilities', 'access.admin_count_drift'], true)) {
            return 'permissions';
        }
        if (in_array($f->rule_key, ['access.login_attack_pressure', 'access.targeted_accounts'], true) || ($f->rule_key === 'access.activity_behaviour' && $f->title === 'Daily failed-login pressure across high-volume IPs')) {
            return 'failed_attempt';
        }
        if ($f->category === 'access') {
            return $f->severity === 'info' ? 'activity_record' : 'access_review';
        }

        return $f->category === 'malware' ? 'database_indicator' : (isset(self::FAMILIES[$f->category]) ? $f->category : 'other');
    }

    public static function counts(Builder $query): array
    {
        return (clone $query)->reorder()->selectRaw(self::familySql().' AS family, COUNT(*) AS n')->groupBy('family')->get()
            ->map(fn ($r) => ['key' => $r->family, 'label' => self::FAMILIES[$r->family][0], 'findings' => (int) $r->n])->all();
    }

    /** Only explicit valid ISO activity dates, never scanner detection dates. */
    public static function eventAt(DbScanFinding $f): ?string
    {
        $value = $f->evidence['details']['at_utc'] ?? null;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        $parsed = date_parse($value);
        if ($parsed['error_count'] || $parsed['warning_count']) {
            return null;
        }

        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    public static function summary(DbScanFinding $f): array
    {
        $family = self::family($f);
        $daily = $f->rule_key === 'access.activity_behaviour' && $f->title === 'Daily failed-login pressure across high-volume IPs';

        return [
            'family' => $family,
            'family_label' => self::FAMILIES[$family][0],
            'next_action' => self::FAMILIES[$family][1],
            'event_at' => self::eventAt($f),
            'event_precision' => $daily ? 'day' : 'time',
            'event_label' => $daily ? 'Activity date' : ($f->rule_key === 'access.account_campaign' ? 'Earliest recorded activity' : 'Recorded activity'),
            'current_controls' => 'Not established by this finding',
            'counting_unit' => $f->rule_key === 'malware.reinfection_cluster' ? 'correlation finding' : ($f->rule_key === 'access.role_grants_admin_capability' ? 'role definition' : 'finding'),
        ];
    }

    public static function workflow(DbScanFinding $f): array
    {
        $report = $f->relationLoaded('latestRemediation') ? $f->latestRemediation : null;
        $stage = 'needs_follow_up';
        $label = 'Needs follow-up';
        if ($f->status === 'resolved') {
            [$stage, $label] = ['resolved', 'Finding resolved by verification'];
        } elseif ($f->status === 'contained') {
            [$stage, $label] = ['contained', 'Containment action verified'];
        } elseif (in_array($f->status, DbScanFinding::SUPPRESSED_STATUSES, true)) {
            [$stage, $label] = ['triaged', 'Triaged; absence not verified'];
        } elseif ($report) {
            if ($f->last_seen_at && $f->last_seen_at->gt($report->created_at)) {
                [$stage, $label] = ['evidence_after_report', 'Evidence seen after report'];
            } elseif ($f->last_seen_at && $f->last_seen_at->eq($report->created_at)) {
                [$stage, $label] = ['timing_uncertain', 'Verification timing uncertain'];
            } else {
                [$stage, $label] = ['awaiting_verification', 'Reported fix; awaiting verification'];
            }
        } elseif ($f->severity === 'info') {
            [$stage, $label] = ['activity_review', 'Activity record to review'];
        }

        return [
            'stage' => $stage, 'label' => $label,
            'reported_at' => $report?->created_at?->toIso8601String(),
            'report_note' => $report?->note,
            'report_actor_id' => $report?->actor_id,
            'explanation' => 'A report records a claimed remediation. Retained historical evidence can be seen again without a new attack or a failed fix. Resolution requires the native compatible verification checks.',
        ];
    }

    public static function awaiting(Builder $query): Builder
    {
        return $query->whereIn('status', DbScanFinding::OPEN_STATUSES)->whereHas('latestRemediation', fn ($q) => $q->where(function ($w) {
            $w->whereColumn('db_scan_finding_events.created_at', '>', 'db_scan_findings.last_seen_at')->orWhereNull('db_scan_findings.last_seen_at');
        }));
    }

    public static function filterWorkflow(Builder $query, string $workflow): void
    {
        if ($workflow === 'awaiting_verification') {
            self::awaiting($query);
        } elseif ($workflow === 'needs_follow_up') {
            $query->whereIn('status', DbScanFinding::OPEN_STATUSES)->where('severity', '!=', 'info')->whereNot(function ($q) {
                self::awaiting($q);
            });
        }
    }

    public static function groupKey(DbScanFinding $f): string
    {
        $d = $f->evidence['details'] ?? [];
        if ($f->rule_key === 'malware.reinfection_cluster' && is_string($d['indicator'] ?? null) && is_string($d['indicator_type'] ?? null)) {
            $identity = [$f->rule_key, $d['indicator_type'], $d['indicator']];
        } elseif ($f->rule_key === 'access.role_grants_admin_capability' && ! empty($d['role']) && ! empty($d['definition_hash'])) {
            $identity = [$f->rule_key, $d['role'], $d['definition_hash']];
        } else {
            $identity = [$f->platform_id, $f->rule_key, $f->subject_hash ?: $f->id];
        }

        return hash('sha256', json_encode($identity));
    }

    public static function groups(iterable $findings, array $names): array
    {
        $groups = [];
        foreach ($findings as $f) {
            $key = self::groupKey($f);
            $d = $f->evidence['details'] ?? [];
            $label = $f->rule_key === 'malware.reinfection_cluster' ? 'Shared login indicator: '.($d['indicator'] ?? 'review evidence') : ($d['role'] ?? $d['login'] ?? $f->subject['label'] ?? $f->title);
            $groups[$key] ??= ['key' => $key, 'label' => (string) $label, 'family' => self::family($f), 'family_label' => self::FAMILIES[self::family($f)][0], 'findings' => 0, 'critical' => 0, 'platform_ids' => [], 'member_ids' => [], 'rule_key' => $f->rule_key];
            $groups[$key]['findings']++;
            $groups[$key]['critical'] += $f->severity === 'critical' ? 1 : 0;
            $groups[$key]['platform_ids'][(int) $f->platform_id] = true;
            $groups[$key]['member_ids'][] = $f->id;
        }
        foreach ($groups as &$g) {
            $g['platform_ids'] = array_keys($g['platform_ids']);
            $g['markets'] = count($g['platform_ids']);
            $g['market_names'] = array_values(array_intersect_key($names, array_flip($g['platform_ids'])));
        }
        unset($g);
        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => ($b['critical'] <=> $a['critical']) ?: ($b['findings'] <=> $a['findings']) ?: strcmp($a['key'], $b['key']));

        return $groups;
    }
}
