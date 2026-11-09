<?php

namespace App\Services\SubscriptionAudit;

use App\Models\SubscriptionAuditRun;
use App\Services\Mcp\ToolResultSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SubscriptionAuditBuilder
{
    public const RULE_VERSION = '2026-11-08.1';

    private const SOURCE_LIMIT = 30000;

    public function build(int $days = 90, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): SubscriptionAuditRun
    {
        if ($days < 1 || $days > 90) {
            throw new SubscriptionAuditException('The reporting window must be between 1 and 90 days.');
        }
        $to = ($to ?? CarbonImmutable::now('UTC'))->utc()->startOfSecond();
        $from = ($from ?? $to->subDays($days))->utc()->startOfSecond();
        if ($from >= $to || $from->diffInSeconds($to) > 90 * 86400 || $to->isFuture()) {
            throw new SubscriptionAuditException('Use an ordered historical UTC window of at most 90 days.');
        }
        foreach (['deals', 'payments', 'platforms', 'audit_log', 'timeline_events', 'subscription_audit_runs', 'subscription_audit_events'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new SubscriptionAuditException('Required reporting sources or migrations are unavailable.', 'audit_source_unavailable', 503, -32020);
            }
        }
        // All reads use a single repeatable snapshot. No source model hooks are invoked.
        if (DB::connection()->getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction(function () use ($from, $to): SubscriptionAuditRun {
            $profileAsOf = CarbonImmutable::now('UTC')->startOfSecond();
            $started = microtime(true);
            $toSql = $to->toDateTimeString();
            ['events' => $events, 'deals' => $deals, 'payments' => $payments, 'trialEvidence' => $trialEvidence, 'platforms' => $platforms, 'sourceCounts' => $sourceCounts, 'skippedAttempts' => $skippedAttempts] = $this->sources($from, $to);
            [$approvals, $approvalCount] = $this->approvalCandidates($events, $to);
            $aliases = Schema::hasTable('mcp_staff_aliases') ? DB::table('mcp_staff_aliases')->where('active', true)->pluck('agent_alias', 'user_id')->all() : [];
            $coverage = [
                'state' => 'partial', 'time_basis' => 'UTC; from inclusive, to exclusive',
                'source_counts' => $sourceCounts + ['attempts_excluded' => $skippedAttempts, 'approval_records' => $approvalCount],
                'platforms' => [],
                'caveats' => [
                    'Retained CRM history is reconstructed; direct WordPress/SQL changes and deleted or unlogged history may be absent.',
                    'CRM-recorded payment success is not independent provider or statement verification.',
                    'Current approver and assigned-owner fields do not establish historical authorisation or the actor.',
                    'Null provider environments and missing action-specific terms reduce assurance; unknowns are not misconduct.',
                    'Country labels are retained website metadata; separate platform IDs are never merged.',
                ],
            ];
            $run = SubscriptionAuditRun::create(['run_ref' => (string) Str::uuid(), 'status' => 'building', 'from_at' => $from, 'to_at' => $to, 'as_of' => CarbonImmutable::now('UTC'), 'rule_version' => self::RULE_VERSION, 'coverage' => $coverage]);
            usort($events, fn ($a, $b) => [$a['occurred_at'], $a['source_type'], $a['source_id']] <=> [$b['occurred_at'], $b['source_type'], $b['source_id']]);
            $allocated = [];
            $siteCounts = [];
            $batch = [];
            foreach ($events as $event) {
                $event['trial_evidence'] = $trialEvidence[$event['payment_id']] ?? null;
                $event['approval_candidates'] = $approvals[$event['platform_id'].':'.$event['deal_id']] ?? [];
                $platform = $platforms[$event['platform_id']] ?? null;
                $host = $platform ? parse_url(str_contains($platform->domain ?? '', '://') ? $platform->domain : 'https://'.($platform->domain ?? ''), PHP_URL_HOST) : null;
                $row = app(SubscriptionAuditClassifier::class)->project($event, $deals[$event['deal_id']] ?? null, $payments[$event['payment_id']] ?? null, [
                    'website' => $host, 'market_label' => $platform?->name, 'country' => $platform?->country, 'currency' => $platform?->currency_code,
                ], $aliases, $allocated);
                $row['run_id'] = $run->id;
                $row['payload'] = json_encode(app(ToolResultSanitizer::class)->sanitize($row['payload']), JSON_THROW_ON_ERROR);
                $batch[] = $row;
                $siteCounts[$event['platform_id']] = ($siteCounts[$event['platform_id']] ?? 0) + 1;
                if (count($batch) === 100) {
                    DB::table('subscription_audit_events')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                DB::table('subscription_audit_events')->insert($batch);
            }
            foreach ($platforms as $platform) {
                $coverage['platforms'][] = ['platform_id' => (int) $platform->id, 'market_label' => $platform->name, 'country' => $platform->country,
                    'state' => isset($siteCounts[$platform->id]) ? 'partial' : 'no_observed_activity', 'event_count' => $siteCounts[$platform->id] ?? 0];
            }
            unset($events, $deals, $payments, $trialEvidence, $batch, $allocated, $approvals, $aliases);
            if (Schema::hasTable('subscription_audit_profiles')) {
                $coverage['profiles'] = app(SubscriptionProfileAuditBuilder::class)->build($run, $profileAsOf, $started);
            } else {
                $coverage['profiles'] = ['state' => 'unavailable', 'reason' => 'profile_reporting_migration_missing'];
            }
            $coverage['trial_approval_source'] = app(TrialAuditEvidence::class)->available() ? 'available' : 'unavailable';
            if ($coverage['trial_approval_source'] === 'unavailable') {
                $coverage['caveats'][] = 'Durable trial approval source tables are unavailable; missing approval evidence may be a source-coverage gap.';
            }
            $run->update(['coverage' => $coverage, 'status' => 'completed']);

            $completed = $run->fresh();
            if (($coverage['profiles']['state'] ?? null) === 'partial' && microtime(true) - $started > (float) config('mcp.subscription_profiles.build_seconds', 600)) {
                throw new SubscriptionAuditException('Snapshot completion exceeded its time budget.', 'audit_source_limit', 503, -32020);
            }

            return $completed;
        }, 1);
    }

    /** Retained approval candidates are context, not proof of authorising a given effect. */
    public function approvalCandidates(array $events, CarbonImmutable $to): array
    {
        $candidates = [];
        $count = 0;
        foreach (array_chunk(array_filter(array_unique(array_column($events, 'deal_id'))), 500) as $ids) {
            $rows = $this->bounded(DB::table('audit_log')->where('entity_type', 'deal')->whereIn('entity_id', $ids)->whereIn('action', ['deal_discount', 'deal_free_trial'])->where('created_at', '<', $to->toDateTimeString())->orderByDesc('id'), ['id', 'platform_id', 'entity_id', 'action', 'actor_id', 'after_state', 'created_at']);
            $count += count($rows);
            if ($count > self::SOURCE_LIMIT) {
                throw new SubscriptionAuditException('Approval history exceeds the bounded build limit.', 'audit_source_limit', 503, -32020);
            }
            foreach ($rows as $approval) {
                $key = $approval->platform_id.':'.$approval->entity_id;
                if (count($candidates[$key] ?? []) >= 5) {
                    continue;
                }
                $after = AuditValue::json($approval->after_state);
                $candidates[$key][] = ['kind' => $approval->action, 'recorded_at' => AuditValue::iso($approval->created_at),
                    'source_ref' => AuditValue::handle('src', 'approval:'.$approval->id),
                    'approver_handle' => AuditValue::handle('staff', $after['discount_approved_by'] ?? $after['free_trial_approved_by'] ?? null),
                    'recording_actor_handle' => AuditValue::handle('staff', $approval->actor_id),
                    'approval_method' => ($after['approval_mode'] ?? null) === 'pin' ? 'pin_authorisation_recorded' : 'staff_approval_record', 'effect_link' => 'unverified'];
            }
        }

        return [$candidates, $count];
    }

    /** Shared source reconstruction for event windows and all-retained profile history. */
    public function sources(?CarbonImmutable $from, CarbonImmutable $to, ?array $clientIds = null): array
    {
        $dealScope = fn () => DB::table('deals')->whereIn('client_id', $clientIds ?? [])->select('id');
        $fromSql = $from?->toDateTimeString();
        $toSql = $to->toDateTimeString();
        $platforms = DB::table('platforms')->get(['id', 'name', 'domain', 'country', 'currency_code'])->keyBy('id');
        $audits = $this->bounded(DB::table('audit_log')->where('entity_type', 'deal')->whereIn('action', ['deal_activate', 'deal_renew', 'deal_extend'])->when($clientIds !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('entity_id', $dealScope())->orWhereIn('after_state->new_deal_id', $dealScope())))->when($fromSql !== null, fn ($q) => $q->where('created_at', '>=', $fromSql))->where('created_at', '<', $toSql)->orderBy('id'), ['id', 'platform_id', 'actor_id', 'entity_id', 'action', 'before_state', 'after_state', 'created_at']);
        $timeline = $this->bounded(DB::table('timeline_events')->whereIn('event_type', ['subscription_activation_recorded', 'deal_activated', 'deal_extended', 'deal_renewed'])->when($clientIds !== null, fn ($q) => $q->where(fn ($q) => $q->where(fn ($q) => $q->where('entity_type', 'deal')->whereIn('entity_id', $dealScope()))->orWhere(fn ($q) => $q->where('entity_type', 'client')->whereIn('entity_id', $clientIds))->orWhereIn('content->new_deal_id', $dealScope())))->when($fromSql !== null, fn ($q) => $q->where('created_at', '>=', $fromSql))->where('created_at', '<', $toSql)->orderBy('id'), ['id', 'platform_id', 'entity_type', 'entity_id', 'actor_id', 'event_type', 'content', 'created_at']);
        $dealIds = [];
        foreach ($audits as $row) {
            $after = AuditValue::json($row->after_state);
            $dealIds[] = $row->action === 'deal_renew' ? (int) ($after['new_deal_id'] ?? 0) : (int) $row->entity_id;
        }
        foreach ($timeline as $row) {
            $content = AuditValue::json($row->content);
            $dealIds[] = (int) ($content['new_deal_id'] ?? $content['deal_id'] ?? ($row->entity_type === 'deal' ? $row->entity_id : 0));
        }
        $current = $this->bounded(DB::table('deals')->when($clientIds !== null, fn ($q) => $q->whereIn('client_id', $clientIds))->when($fromSql !== null, fn ($q) => $q->where('activated_at', '>=', $fromSql))->whereNotNull('activated_at')->where('activated_at', '<', $toSql)->orderBy('id'), $this->sourceColumns('deals'));
        foreach ($current as $deal) {
            $dealIds[] = (int) $deal->id;
        }
        $deals = $this->lookup('deals', array_filter(array_unique($dealIds)));
        $sourceCounts = ['action_audits' => count($audits), 'success_timelines' => count($timeline), 'current_deals' => count($current)];
        $events = [];
        $canonicalLinks = [];
        $canonicalPayments = [];
        $exactOperations = [];
        $representedDeals = [];
        $representedActivations = [];
        $skippedAttempts = 0;
        foreach ($audits as $row) {
            $before = AuditValue::json($row->before_state);
            $after = AuditValue::json($row->after_state);
            $kind = ['deal_activate' => 'activation', 'deal_renew' => 'renewal', 'deal_extend' => 'extension'][$row->action];
            // Activation initiation audits also exist. They are not successful effects.
            if ($kind !== 'extension' && ! in_array($after['deal_status'] ?? $after['new_status'] ?? null, ['active', 'expired', 'renewed'], true)) {
                $skippedAttempts++;

                continue;
            }
            if ($kind === 'extension' && ! array_key_exists('expires_at', $after) && ! array_key_exists('new_expires_at', $after)) {
                $skippedAttempts++;

                continue;
            }
            $dealId = $kind === 'renewal' ? (int) ($after['new_deal_id'] ?? 0) : (int) $row->entity_id;
            $deal = $deals[$dealId] ?? null;
            $paymentId = $kind === 'extension' ? ($after['extension_payment_id'] ?? null) : ($after['payment_id'] ?? null);
            $event = $this->event('audit', $row->id, $kind, $row->platform_id, $dealId ?: null, $deal?->client_id, $paymentId, $row->actor_id, $row->created_at, $before, $after);
            $index = count($events);
            $events[] = $event;
            $canonicalLinks[$this->effectLink($event)] = $index;
            if ($event['payment_id']) {
                $canonicalPayments[$this->paymentLink($event)] = $index;
            }
            $exactOperations[$this->operationLink($event)] = $index;
            $representedDeals[$dealId] = true;
            if ($kind !== 'extension') {
                $representedActivations[$dealId] = true;
            }
        }
        // Prefer the dedicated success snapshot to the older activation timeline.
        $timeline = collect($timeline)->sortBy(fn ($row) => $row->event_type === 'subscription_activation_recorded' ? 0 : 1)->all();
        foreach ($timeline as $row) {
            $content = AuditValue::json($row->content);
            $dealId = (int) ($content['new_deal_id'] ?? $content['deal_id'] ?? ($row->entity_type === 'deal' ? $row->entity_id : 0));
            $deal = $deals[$dealId] ?? null;
            $kind = match ($row->event_type) {
                'deal_extended' => 'extension',
                'deal_renewed' => 'renewal',
                default => ($content['source'] ?? null) === 'crm_extension' ? 'extension'
                    : ((str_contains((string) ($content['source'] ?? ''), 'renewal') || isset($content['renewed_from_deal_id']) || ($content['subscription_lifecycle'] ?? null) === 'renewal') ? 'renewal' : 'activation'),
            };
            // Renewal initiation timeline can precede an actual activation.
            if ($row->event_type === 'deal_renewed' && ! in_array($content['new_status'] ?? null, ['active', 'expired', 'renewed'], true)) {
                continue;
            }
            $paymentId = $kind === 'extension' && $row->event_type !== 'subscription_activation_recorded'
                ? ($content['extension_payment_id'] ?? null) : ($content['payment_id'] ?? null);
            $event = $this->event('timeline', $row->id, $kind, $row->platform_id, $dealId ?: null, $deal?->client_id ?? ($row->entity_type === 'client' ? $row->entity_id : null), $paymentId, $row->actor_id, $row->created_at, [], $content);
            // Only exact identity links collapse paired representations. A null-payment
            // extension has no identity safe enough to infer another independent effect.
            $matched = $canonicalLinks[$this->effectLink($event)] ?? null;
            if ($matched === null && $paymentId && $kind !== 'extension') {
                $matched = $canonicalPayments[$this->paymentLink($event)] ?? null;
            }
            // A recorder snapshot omits expiry and charge terms. It can support an
            // exact same recorded operation, never suppress all changes to a deal.
            if ($matched === null && ($row->event_type === 'subscription_activation_recorded' || $kind === 'extension')) {
                $operationMatch = $exactOperations[$this->operationLink($event)] ?? null;
                if ($operationMatch !== null && (! $event['new_expiry_known'] || ! $events[$operationMatch]['new_expiry_known']
                    || $event['new_expiry'] === $events[$operationMatch]['new_expiry'])) {
                    $matched = $operationMatch;
                }
            }
            if ($matched !== null) {
                $this->supplement($events[$matched], $event);

                continue;
            }
            if ($row->event_type === 'subscription_activation_recorded' && $kind === 'extension'
                && ! $event['new_expiry_known'] && isset($representedDeals[$dealId])) {
                $event['event_type'] = 'observation';
                $event['match_basis'] = 'unresolved_success_snapshot_context_only';
                $event['reasons'][] = 'additional_source_representation_unresolved';
            } elseif (isset($representedDeals[$dealId])) {
                $event['reasons'][] = 'additional_source_representation_unresolved';
            }
            $index = count($events);
            $events[] = $event;
            $canonicalLinks[$this->effectLink($event)] = $index;
            if ($paymentId) {
                $canonicalPayments[$this->paymentLink($event)] = $index;
            }
            $exactOperations[$this->operationLink($event)] = $index;
            $representedDeals[$dealId] = true;
            if ($kind !== 'extension') {
                $representedActivations[$dealId] = true;
            }
        }
        foreach ($current as $deal) {
            if (isset($representedActivations[(int) $deal->id])) {
                continue;
            }
            $event = $this->event('deal_observation', $deal->id, 'observation', $deal->platform_id, $deal->id, $deal->client_id, $deal->payment_id, null, $deal->activated_at, [], [
                'amount' => $deal->amount, 'currency' => $deal->currency, 'is_free_trial' => (bool) $deal->is_free_trial,
                'discount_percentage' => $deal->discount_percentage, 'expires_at' => $deal->expires_at,
            ]);
            $event['match_basis'] = 'current_deal_payment_context_only';
            $events[] = $event;
        }
        unset($audits, $timeline, $current, $dealIds);
        if (count($events) > self::SOURCE_LIMIT) {
            throw new SubscriptionAuditException('The canonical effect set exceeds the bounded reporting limit.', 'audit_source_limit', 503, -32020);
        }
        $paymentIds = array_filter(array_unique(array_column($events, 'payment_id')));
        $payments = $this->lookup('payments', $paymentIds);
        // A reverse link is a visible candidate; it does not silently clear an action.
        $reverse = [];
        $reverseCount = 0;
        foreach (array_chunk(array_filter(array_unique(array_column($events, 'deal_id'))), 500) as $ids) {
            foreach ($this->bounded(DB::table('payments')->whereIn('deal_id', $ids), ['id', 'deal_id']) as $row) {
                if (++$reverseCount > self::SOURCE_LIMIT) {
                    throw new SubscriptionAuditException('Reverse-link candidates exceed the bounded reporting limit.', 'audit_source_limit', 503, -32020);
                }
                $reverse[(int) $row->deal_id][] = (int) $row->id;
            }
        }
        $candidateIds = [];
        foreach ($events as &$event) {
            if (! $event['payment_id'] && $event['event_type'] !== 'extension' && count($reverse[$event['deal_id']] ?? []) === 1) {
                $event['payment_id'] = $reverse[$event['deal_id']][0];
                $event['match_basis'] = 'reverse_link_candidate';
                $candidateIds[] = $event['payment_id'];
            }
        }
        unset($event);
        $payments += $this->lookup('payments', array_unique($candidateIds));
        $trialEvidence = app(TrialAuditEvidence::class)->forPayments($payments);
        if ($clientIds !== null) {
            $clientSites = DB::table('clients')->whereIn('id', $clientIds)->pluck('platform_id', 'id');
            $events = array_values(array_filter($events, fn ($event) => isset($clientSites[$event['client_id']]) && (int) $clientSites[$event['client_id']] === (int) $event['platform_id']));
        }

        return compact('events', 'deals', 'payments', 'trialEvidence', 'platforms', 'sourceCounts', 'skippedAttempts');
    }

    /** Prune only derived snapshots; newest completed run and the six-hour cursor buffer survive. */
    public function prune(): int
    {
        $newest = DB::table('subscription_audit_runs')->where('status', 'completed')->max('id');

        return DB::table('subscription_audit_runs')->where('id', '!=', $newest ?? 0)->where('created_at', '<', now('UTC')->subHours(6))->delete();
    }

    private function bounded($query, array $columns): array
    {
        $rows = $query->limit(self::SOURCE_LIMIT + 1)->get($columns)->all();
        if (count($rows) > self::SOURCE_LIMIT) {
            throw new SubscriptionAuditException('The source window exceeds the bounded audit build limit; use a smaller window.', 'audit_source_limit', 503, -32020);
        }

        return $rows;
    }

    private function lookup(string $table, array $ids): array
    {
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table($table)->whereIn('id', $chunk)->get($this->sourceColumns($table)) as $row) {
                $rows[(int) $row->id] = $row;
            }
        }

        return $rows;
    }

    private function sourceColumns(string $table): array
    {
        return $table === 'deals'
            ? ['id', 'platform_id', 'client_id', 'payment_id', 'amount', 'currency', 'activated_at', 'expires_at', 'is_free_trial', 'free_trial_approved_by', 'discount_percentage', 'discount_approved_by', 'assigned_to']
            : ['id', 'platform_id', 'client_id', 'deal_id', 'amount', 'currency', 'status', 'purpose', 'provider_environment', 'record_classification', 'resolution_code', 'reconciliation_state', 'manual_payment_bundle_id', 'wallet_transaction_id', 'confirmed_by', DB::raw("JSON_EXTRACT(payment_data, '$.test_mode') AS audit_test_mode"), DB::raw((DB::connection()->getDriverName() === 'mysql' ? "JSON_UNQUOTE(JSON_EXTRACT(payment_data, '$.trial_operation_id'))" : "JSON_EXTRACT(payment_data, '$.trial_operation_id')").' AS audit_trial_operation'), DB::raw("JSON_EXTRACT(payment_data, '$.trial_request_id') AS audit_trial_request")];
    }

    public function event(string $source, int $sourceId, string $kind, int $platformId, ?int $dealId, ?int $clientId, mixed $paymentId, ?int $actorId, string $recordedAt, array $before, array $terms): array
    {
        if (! isset($terms['payment_method']) && isset($terms['method'])) {
            $terms['payment_method'] = $terms['method'];
        }
        $newKey = array_key_exists('new_expires_at', $terms) ? 'new_expires_at' : 'expires_at';

        return [
            'source_type' => $source, 'source_id' => $sourceId, 'event_type' => $kind, 'platform_id' => $platformId,
            'deal_id' => $dealId, 'client_id' => $clientId, 'payment_id' => $paymentId ? (int) $paymentId : null,
            'actor_id' => $actorId, 'automatic' => ($terms['execution'] ?? null) === 'automatic',
            'occurred_at' => $source === 'timeline' ? (AuditValue::iso($terms['activated_at'] ?? null) ? CarbonImmutable::parse($terms['activated_at'])->utc()->toDateTimeString() : $recordedAt) : $recordedAt,
            'recorded_at' => $recordedAt, 'old_expiry' => $before['expires_at'] ?? null,
            'old_expiry_known' => array_key_exists('expires_at', $before), 'new_expiry' => $terms[$newKey] ?? null,
            'new_expiry_known' => array_key_exists($newKey, $terms), 'terms' => $terms,
            'completeness' => $source === 'audit' ? 'action_audit' : 'success_timeline',
        ];
    }

    private function effectLink(array $event): string
    {
        // Two source representations with exact action expiry/payment identity describe
        // one effect; independent audit rows are intentionally never collapsed together.
        return implode(':', [$event['platform_id'], $event['deal_id'], $event['event_type'], $event['payment_id'] ?? 'none', $event['new_expiry'] ?? 'unknown']);
    }

    private function paymentLink(array $event): string
    {
        return implode(':', [$event['platform_id'], $event['deal_id'], $event['event_type'], $event['payment_id']]);
    }

    private function operationLink(array $event): string
    {
        return implode(':', [$event['platform_id'], $event['deal_id'], $event['event_type'], $event['payment_id'] ?? 'none', $event['actor_id'] ?? 'system', $event['recorded_at'], $event['terms']['payment_method'] ?? 'unknown']);
    }

    private function supplement(array &$primary, array $support): void
    {
        $primary['supporting_source_refs'][] = AuditValue::handle('src', $support['source_type'].':'.$support['source_id']);
        foreach (['amount', 'currency', 'payment_method', 'is_free_trial', 'free_trial_approved_by', 'discount_percentage', 'discount_approved_by'] as $key) {
            if (! array_key_exists($key, $primary['terms']) && array_key_exists($key, $support['terms'])) {
                $primary['terms'][$key] = $support['terms'][$key];
            }
        }
        if (! $primary['new_expiry_known'] && $support['new_expiry_known']) {
            $primary['new_expiry'] = $support['new_expiry'];
            $primary['new_expiry_known'] = true;
        }
    }
}
