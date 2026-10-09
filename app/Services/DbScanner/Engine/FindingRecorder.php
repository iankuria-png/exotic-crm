<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanFinding;
use App\Models\DbScanFindingEvent;
use App\Models\DbScanMarketRun;
use App\Models\DbScanObservation;
use App\Models\DbScanSuppression;
use App\Services\DbScanner\Rules\Hit;
use App\Services\DbScanner\Rules\RuleSet;
use Illuminate\Support\Facades\DB;

/**
 * Records hits as findings + immutable observations, inside the caller's
 * chunk-commit transaction.
 *
 * - Identity: market + rule key + canonical subject (fingerprint).
 * - Occurrences count distinct observing runs, never worker attempts.
 * - A resolved finding that reappears reopens; an expired snooze reopens.
 * - Suppressions hold only while the payload fingerprint and rule
 *   semantics they were created for are unchanged and unexpired.
 * - Test runs never touch canonical findings; they keep bounded samples.
 */
class FindingRecorder
{
    public const PER_RULE_RUN_CAP = 300;

    /**
     * @param  array<int, Hit>  $hits
     * @return array{new: int, seen: int, reopened: int, capped: array<string, int>}
     */
    public function record(array $hits, DbScanMarketRun $run, RuleSet $rules, array &$runCounters): array
    {
        $stats = ['new' => 0, 'seen' => 0, 'reopened' => 0, 'capped' => []];

        if ($run->mode === 'test') {
            $samples = (array) ($run->test_samples ?? []);
            $limit = (int) config('db_scanner.envelope.test_sample_limit', 20);
            foreach ($hits as $hit) {
                if (count($samples) >= $limit) {
                    break;
                }
                $samples[] = [
                    'rule_key' => $hit->ruleKey,
                    'title' => $hit->title,
                    'subject' => $hit->subject,
                    'evidence' => $hit->evidence,
                    'confidence' => $hit->confidence,
                    'severity' => $this->severity($hit, $rules),
                ];
            }
            $run->test_samples = $samples;

            return $stats;
        }

        $evidenceCap = (int) config('db_scanner.envelope.max_evidence_bytes_per_run', 20 * 1024 * 1024);
        $findingCap = (int) config('db_scanner.envelope.max_findings_per_run', 10000);

        foreach ($hits as $hit) {
            $perRule = (int) ($runCounters['rule_matches'][$hit->ruleKey] ?? 0);
            if ($perRule >= self::PER_RULE_RUN_CAP
                || (int) ($runCounters['findings'] ?? 0) >= $findingCap
                || (int) ($runCounters['evidence_bytes'] ?? 0) >= $evidenceCap) {
                $stats['capped'][$hit->ruleKey] = ($stats['capped'][$hit->ruleKey] ?? 0) + 1;

                continue;
            }

            $outcome = $this->recordOne($hit, $run, $rules);
            if ($outcome === null) {
                continue;
            }

            $runCounters['rule_matches'][$hit->ruleKey] = $perRule + 1;
            $runCounters['findings'] = (int) ($runCounters['findings'] ?? 0) + 1;
            $runCounters['evidence_bytes'] = (int) ($runCounters['evidence_bytes'] ?? 0) + strlen((string) json_encode($hit->evidence));
            $stats[$outcome]++;
        }

        return $stats;
    }

    /**
     * @return 'new'|'seen'|'reopened'|null null when already observed in this run
     */
    private function recordOne(Hit $hit, DbScanMarketRun $run, RuleSet $rules): ?string
    {
        $rule = $rules->rule($hit->ruleKey) ?? [];
        $subjectHash = $hit->subjectHash();
        $fingerprint = hash('sha256', $run->platform_id.'|'.$hit->ruleKey.'|'.$subjectHash);
        $versionHash = $rules->versionHash($hit->ruleKey);
        $severity = $this->severity($hit, $rules);
        $confidence = $hit->confidence ?? ($rule['confidence'] ?? null);
        $now = now();

        $observed = DbScanObservation::query()
            ->where('market_run_id', $run->id)
            ->where('rule_key', $hit->ruleKey)
            ->where('subject_hash', $subjectHash)
            ->exists();
        if ($observed) {
            return null;
        }

        $finding = DbScanFinding::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
        $outcome = 'seen';
        $fromStatus = null;

        if (! $finding) {
            $suppression = $this->matchingSuppression($hit, $run->platform_id, $subjectHash, $versionHash);
            $finding = DbScanFinding::query()->create([
                'platform_id' => $run->platform_id,
                'fingerprint' => $fingerprint,
                'rule_key' => $hit->ruleKey,
                'rule_version_hash' => $versionHash,
                'pack' => (string) ($rule['pack'] ?? ''),
                'pack_version' => (string) ($rule['pack_version'] ?? ''),
                'category' => (string) ($rule['category'] ?? ''),
                'behavior' => $hit->behavior,
                'severity' => $severity,
                'confidence' => $confidence,
                'title' => mb_substr($hit->title, 0, 255),
                'subject' => $hit->subject,
                'subject_hash' => $subjectHash,
                'evidence' => $this->evidence($hit),
                'status' => $suppression ? 'allowlisted' : 'open',
                'suppression_id' => $suppression?->id,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'occurrences' => 1,
                'last_run_id' => $run->id,
            ]);
            $outcome = 'new';
            $this->event($finding, $run, 'detected', null, $finding->status, $suppression ? 'Matched an active suppression.' : null);
        } else {
            $fromStatus = $finding->status;
            $reopen = false;
            $reason = null;

            if (in_array($finding->status, ['resolved', 'contained'], true)) {
                $reopen = true;
                $reason = 'Seen again after resolution.';
            } elseif ($finding->status === 'snoozed' && $finding->snoozed_until && $finding->snoozed_until->isPast()) {
                $reopen = true;
                $reason = 'Snooze expired.';
            } elseif (in_array($finding->status, ['false_positive', 'allowlisted'], true)) {
                $payloadChanged = $hit->payloadHash !== null
                    && ($finding->evidence['payload_sha256'] ?? null) !== null
                    && $hit->payloadHash !== $finding->evidence['payload_sha256'];
                $semanticsChanged = $finding->rule_version_hash !== $versionHash;
                $suppression = $finding->suppression_id ? DbScanSuppression::query()->find($finding->suppression_id) : null;
                $expired = $suppression && ($suppression->revoked_at || ($suppression->expires_at && $suppression->expires_at->isPast()));
                if ($payloadChanged || $semanticsChanged || $expired) {
                    $reopen = true;
                    $reason = $payloadChanged ? 'Payload changed since it was dismissed.' : ($semanticsChanged ? 'Rule semantics changed since it was dismissed.' : 'Suppression expired or was revoked.');
                }
            }

            $updates = [
                'last_seen_at' => $now,
                'evidence' => $this->evidence($hit),
                'severity' => $severity,
                'confidence' => $confidence,
                'behavior' => $hit->behavior,
                'title' => mb_substr($hit->title, 0, 255),
                'rule_version_hash' => $versionHash,
                'pack_version' => (string) ($rule['pack_version'] ?? $finding->pack_version),
            ];
            if ((int) $finding->last_run_id !== (int) $run->id) {
                $updates['occurrences'] = $finding->occurrences + 1;
                $updates['last_run_id'] = $run->id;
            }
            if ($reopen) {
                $updates += ['status' => 'open', 'resolved_at' => null, 'snoozed_until' => null, 'suppression_id' => null];
                $outcome = 'reopened';
            }
            $finding->forceFill($updates)->save();

            // Repeat sightings are already counted by occurrences and kept as
            // observations; only status changes become timeline events.
            if ($reopen) {
                $this->event($finding, $run, 'reopened', $fromStatus, $finding->status, $reason);
            }
        }

        $observation = DbScanObservation::query()->create([
            'finding_id' => $finding->id,
            'market_run_id' => $run->id,
            'rule_key' => $hit->ruleKey,
            'rule_version_hash' => $versionHash,
            'config_version_id' => $run->config_version_id,
            'subject_hash' => $subjectHash,
            'payload_hash' => $hit->payloadHash,
            'payload_hash_type' => $hit->payloadHashType,
            'confidence' => $confidence,
            'behavior' => $hit->behavior,
            'evidence' => $this->evidence($hit),
            'created_at' => $now,
        ]);
        $finding->forceFill(['latest_observation_id' => $observation->id])->save();

        return $outcome;
    }

    public function severity(Hit $hit, RuleSet $rules): string
    {
        if ($hit->severity) {
            return $hit->severity;
        }
        $rule = $rules->rule($hit->ruleKey) ?? [];
        if (($rule['category'] ?? null) === 'malware' && $hit->confidence === 'needs_review') {
            return 'warn';
        }

        return $rules->severity($hit->ruleKey);
    }

    private function evidence(Hit $hit): array
    {
        $evidence = $hit->evidence;
        if ($hit->payloadHash) {
            $evidence['payload_sha256'] = $hit->payloadHash;
            $evidence['payload_hash_type'] = $hit->payloadHashType;
        }
        $evidence['excerpts'] = array_slice((array) ($evidence['excerpts'] ?? []), 0, (int) config('db_scanner.evidence.excerpts_per_finding', 5));

        return $evidence;
    }

    private function matchingSuppression(Hit $hit, int $platformId, string $subjectHash, string $versionHash): ?DbScanSuppression
    {
        return DbScanSuppression::query()
            ->where('rule_key', $hit->ruleKey)
            ->whereIn('scope_key', ['network', 'platform:'.$platformId])
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('rule_version_hash', $versionHash)
            ->where(function ($q) use ($subjectHash, $hit) {
                $q->where('subject_hash', $subjectHash);
                if ($hit->payloadHash) {
                    $q->orWhere('value_fingerprint', $hit->payloadHash);
                }
            })
            ->first();
    }

    public function event(DbScanFinding $finding, ?DbScanMarketRun $run, string $type, ?string $from, ?string $to, ?string $note = null, ?int $actorId = null): void
    {
        DbScanFindingEvent::query()->create([
            'finding_id' => $finding->id,
            'market_run_id' => $run?->id,
            'actor_id' => $actorId,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * Resolve a finding after compatible complete coverage + point recheck.
     */
    public function resolve(DbScanFinding $finding, DbScanMarketRun $run, string $note): void
    {
        DB::transaction(function () use ($finding, $run, $note) {
            $locked = DbScanFinding::query()->whereKey($finding->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'resolved') {
                return;
            }
            $from = $locked->status;
            $locked->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'snoozed_until' => null])->save();
            $this->event($locked, $run, 'resolved_by_scan', $from, 'resolved', $note);
        });
    }
}
