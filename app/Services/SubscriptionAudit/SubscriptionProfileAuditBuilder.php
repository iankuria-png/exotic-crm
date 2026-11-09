<?php

namespace App\Services\SubscriptionAudit;

use App\Models\Client;
use App\Models\Payment;
use App\Models\SubscriptionAuditRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Complete current cached population. Reads sources; writes profile rows and refines draft event rows before publication. */
class SubscriptionProfileAuditBuilder
{
    private const SOURCE_LIMIT = 30000;

    public function build(SubscriptionAuditRun $run, CarbonImmutable $asOf, float $started): array
    {
        if ($run->status !== 'building') {
            throw new \LogicException('Only an unpublished building run may be projected.');
        }
        $coverage = ['state' => 'partial', 'profile_as_of' => $asOf->toIso8601String(), 'profile_count' => 0, 'platforms' => [],
            'payment_history_basis' => 'all_retained_crm_at_profile_as_of', 'history_completeness' => 'retention_and_direct_wordpress_gaps_unknown'];
        $sites = DB::table('platforms')->get(['id', 'country', 'name'])->keyBy('id');
        Client::query()->notClosed()->select(['id', 'platform_id', 'wp_post_id', 'wp_user_id', 'profile_status', 'needs_payment', 'notactive', 'lifecycle_state', 'escort_expire', 'last_synced_at'])
            ->chunkById(500, function ($clients) use ($run, $asOf, $started, $sites, &$coverage) {
                $this->budget($started);
                $coverage['profile_count'] += $clients->count();
                if ($coverage['profile_count'] > (int) config('mcp.subscription_profiles.max_profiles', 300000)) {
                    throw new SubscriptionAuditException('Profile projection exceeds the reporting ceiling.', 'audit_source_limit', 503, -32020);
                }
                $ids = $clients->pluck('id')->all();
                $deals = $this->bounded(DB::table('deals')->whereIn('client_id', $ids)->select(['id', 'platform_id', 'client_id', 'payment_id', 'product_id', 'status', 'amount', 'currency', 'activated_at', 'expires_at', 'is_free_trial', 'free_trial_approved_by', 'discount_percentage', 'discount_approved_by', 'assigned_to']))->keyBy('id');
                $history = $this->paymentHistory($clients->keyBy('id'), $deals, $started);
                $actions = $this->actions($ids, $run, $asOf, $started);
                $rows = [];
                foreach ($clients as $client) {
                    $active = $client->isActiveProfile();
                    $published = $client->profile_status === 'publish';
                    $h = $history[$client->id] ?? $this->emptyHistory();
                    $state = $h['cash_record_count'] > 0 ? 'cash_recorded' : ($h['uncertain'] ? 'payment_history_unverified' : 'no_retained_cash');
                    $h['has_unverified_candidates'] = $h['uncertain'];
                    unset($h['uncertain']);
                    $h['by_currency'] = array_map(fn ($currency, $minor) => ['currency' => $currency, 'cash_amount' => AuditValue::decimal($minor)], array_keys($h['by_currency']), array_values($h['by_currency']));
                    $recorded = $actions[$client->id] ?? [];
                    $latest = $recorded[0] ?? null;
                    $activation = collect($recorded)->first(fn ($row) => in_array($row['event_type'], ['activation', 'renewal'], true));
                    $flags = $h['has_unverified_candidates'] ? ['payment_history_candidates_unverified'] : [];
                    $postId = (int) $client->wp_post_id > 0 ? (int) $client->wp_post_id : null;
                    $userId = (int) $client->wp_user_id > 0 ? (int) $client->wp_user_id : null;
                    if ($postId === null || ($client->wp_user_id !== null && $userId === null)) {
                        $flags[] = 'wordpress_identity_invalid';
                    }
                    if (! $client->last_synced_at || $client->last_synced_at->lt($asOf->subHours(2))) {
                        $flags[] = 'wordpress_cache_stale_or_unknown';
                    }
                    if (! $client->escort_expire || (int) $client->escort_expire <= $asOf->timestamp) {
                        $flags[] = 'expiry_missing_or_elapsed';
                    }
                    $activeDeals = $deals->filter(fn ($deal) => (int) $deal->client_id === (int) $client->id && (int) $deal->platform_id === (int) $client->platform_id && $deal->status === 'active' && $deal->activated_at && (! $deal->expires_at || $deal->expires_at > $asOf->toDateTimeString()));
                    if ($activeDeals->count() > 1) {
                        $flags[] = 'multiple_current_deals_effect_unresolved';
                    }
                    $payload = [
                        'profile_ref' => AuditValue::handle('profile', $client->platform_id.':'.$client->id),
                        'client_handle' => AuditValue::handle('cli', $client->id), 'platform_id' => (int) $client->platform_id,
                        'market_label' => $sites[$client->platform_id]->name ?? 'Unknown website', 'country' => $sites[$client->platform_id]->country ?? null,
                        'business_active' => $active, 'published' => $published, 'profile_as_of' => $asOf->toIso8601String(),
                        'source_last_synced_at' => $client->last_synced_at?->toIso8601String(), 'source' => 'crm_wordpress_cache',
                        'observed_expiry' => $client->escort_expire ? CarbonImmutable::createFromTimestamp($client->escort_expire, 'UTC')->toIso8601String() : null,
                        'payment_history_state' => $state, 'payment_history' => $h,
                        'finding' => $state === 'no_retained_cash' ? 'No successful cash payment found in retained CRM records' : $state,
                        'latest_activation_at' => $activation['occurred_at'] ?? null, 'latest_activation' => $activation,
                        'latest_action' => $latest, 'action_payment_state' => $latest['payment_state'] ?? 'not_recorded',
                        'authorisation_state' => $latest['authorisation_state'] ?? 'not_recorded', 'review_flags' => $flags,
                    ];
                    $rows[] = ['run_id' => $run->id, 'profile_ref' => $payload['profile_ref'], 'platform_id' => $client->platform_id, 'client_id' => $client->id,
                        'wp_post_id' => $postId, 'wp_user_id' => $userId, 'country' => $payload['country'],
                        'business_active' => $active, 'published' => $published, 'profile_as_of' => $asOf->toDateTimeString(),
                        'source_last_synced_at' => $client->last_synced_at?->toDateTimeString(), 'payment_history_state' => $state,
                        'action_payment_state' => $payload['action_payment_state'], 'authorisation_state' => $payload['authorisation_state'],
                        'payload' => json_encode(app(\App\Services\Mcp\ToolResultSanitizer::class)->sanitize($payload), JSON_THROW_ON_ERROR)];
                    $site = (int) $client->platform_id;
                    $coverage['platforms'][$site] ??= ['platform_id' => $site, 'profile_count' => 0, 'active_count' => 0, 'stale_count' => 0];
                    $coverage['platforms'][$site]['profile_count']++;
                    $coverage['platforms'][$site]['active_count'] += (int) $active;
                    $coverage['platforms'][$site]['stale_count'] += (int) in_array('wordpress_cache_stale_or_unknown', $flags, true);
                }
                foreach (array_chunk($rows, 100) as $batch) {
                    DB::table('subscription_audit_profiles')->insert($batch);
                }
            });
        $this->budget($started);
        $coverage['platforms'] = array_values($coverage['platforms']);

        return $coverage;
    }

    private function emptyHistory(): array
    {
        return ['cash_record_count' => 0, 'wallet_purchase_count' => 0, 'noncash_credit_count' => 0, 'excluded_count' => 0, 'uncertain' => false,
            'earliest_observed_at' => null, 'by_currency' => [], 'assurance' => 'crm_recorded_only', 'completeness' => 'retained_records_only'];
    }

    private function paymentHistory($clients, $deals, float $started): array
    {
        $result = [];
        $query = DB::table('payments')->where(fn ($q) => $q->whereIn('client_id', $clients->keys())->orWhereIn('deal_id', $deals->keys()))
            ->select(['id', 'platform_id', 'client_id', 'deal_id', 'amount', 'currency', 'status', 'source', 'purpose', 'provider_environment', 'created_at', 'record_classification', 'manual_payment_bundle_id', 'reference_root', 'transaction_reference',
                DB::raw("JSON_EXTRACT(payment_data, '$.test_mode') AS test_mode")]);
        foreach ($query->orderBy('id')->lazyById(500)->chunk(500) as $payments) {
            $this->budget($started);
            $eligibleQuery = Payment::query()->reportableSuccessful()->whereIn('id', $payments->pluck('id'));
            if (Schema::hasTable('payment_attributions')) {
                $eligibleQuery->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_attributions')->whereColumn('payment_attributions.payment_id', 'payments.id')->whereIn('attribution_state', ['archived', 'disputed']));
            }
            if (Schema::hasTable('manual_payment_bundles')) {
                $eligibleQuery->where(fn ($q) => $q->whereNull('manual_payment_bundle_id')->orWhereExists(fn ($b) => $b->selectRaw('1')->from('manual_payment_bundles')->whereColumn('manual_payment_bundles.id', 'payments.manual_payment_bundle_id')->where('status', 'committed')->whereColumn('manual_payment_bundles.platform_id', 'payments.platform_id')->whereColumn('manual_payment_bundles.currency', 'payments.currency')->whereColumn('allocated_amount', '<=', 'total_amount')));
            }
            $eligible = $eligibleQuery->pluck('id')->flip();
            $rootSql = "UPPER(TRIM(COALESCE(NULLIF(reference_root, ''), transaction_reference)))";
            $roots = $payments->map(fn ($p) => strtoupper(trim((string) ($p->reference_root ?: $p->transaction_reference))))->filter()->unique()->values()->all();
            $receiptSql = DB::connection()->getDriverName() === 'mysql'
                ? "CASE WHEN manual_payment_bundle_id IS NULL THEN CONCAT('p', id) ELSE CONCAT('b', manual_payment_bundle_id) END"
                : "CASE WHEN manual_payment_bundle_id IS NULL THEN 'p' || id ELSE 'b' || manual_payment_bundle_id END";
            // Group projected column names: MariaDB's ONLY_FULL_GROUP_BY rejects
            // selecting this normalisation expression while grouping by it.
            $receipts = Payment::query()->reportableSuccessful()->whereIn('platform_id', $clients->pluck('platform_id')->unique())
                ->whereIn(DB::raw($rootSql), $roots)->selectRaw('platform_id, '.$rootSql.' as receipt_key, '.$receiptSql.' as receipt_identity');
            $duplicates = $roots ? DB::query()->fromSub($receipts, 'retained_receipts')->select('platform_id', 'receipt_key')
                ->groupBy('platform_id', 'receipt_key')->havingRaw('COUNT(DISTINCT receipt_identity) > 1')
                ->get()->mapWithKeys(fn ($r) => [$r->platform_id.':'.$r->receipt_key => true])->all() : [];
            $multipleObservations = Schema::hasTable('payment_receipt_observations') ? DB::table('payment_receipt_observations')->whereIn('payment_id', $payments->pluck('id'))->select('payment_id')->groupBy('payment_id')->havingRaw('COUNT(*) > 1')->pluck('payment_id')->flip() : collect();
            foreach ($payments as $p) {
                $deal = $deals[$p->deal_id] ?? null;
                $id = $p->client_id ?: $deal?->client_id;
                if ($deal && $p->client_id && (int) $deal->client_id !== (int) $p->client_id) {
                    foreach ([$deal->client_id, $p->client_id] as $conflictId) {
                        if (isset($clients[$conflictId])) {
                            $result[$conflictId] ??= $this->emptyHistory();
                            $result[$conflictId]['uncertain'] = true;
                        }
                    }

                    continue;
                }
                $client = $clients[$id] ?? null;
                if (! $client) {
                    continue;
                }
                $h = $result[$id] ?? $this->emptyHistory();
                $h['earliest_observed_at'] = min($h['earliest_observed_at'] ?? $p->created_at, $p->created_at);
                $identity = (int) $p->platform_id === (int) $client->platform_id
                    && (! $p->deal_id || ($deal && (int) $deal->platform_id === (int) $client->platform_id && (int) $deal->client_id === (int) $id));
                $purpose = $p->purpose ?? 'subscription';
                $minor = AuditValue::minor($p->amount);
                $test = $p->record_classification === 'test' || strtolower((string) $p->provider_environment) === 'sandbox' || in_array($p->test_mode, [true, 1, '1', 'true'], true);
                if ($test || ! in_array($purpose, ['subscription', 'wallet_topup'], true)) {
                    $h['excluded_count']++;
                } elseif (! $identity || $minor === null || isset($duplicates[$p->platform_id.':'.strtoupper(trim((string) ($p->reference_root ?: $p->transaction_reference)))]) || $multipleObservations->has($p->id)) {
                    $h['uncertain'] = true;
                } elseif ($p->source === 'wallet') {
                    $h['wallet_purchase_count']++;
                    // A fungible wallet debit alone cannot establish its cash funding.
                    $h['uncertain'] = true;
                } elseif ($minor <= 0 || $p->source === 'free_trial') {
                    $h['noncash_credit_count']++;
                } elseif (! $eligible->has($p->id)) {
                    $h['excluded_count']++;
                    if (in_array($p->status, Payment::SUCCESSFUL_STATUSES, true)) {
                        $h['uncertain'] = true;
                    }
                } elseif (strtolower((string) $p->provider_environment) !== 'live' || ! preg_match('/^[A-Z]{3}$/D', (string) $p->currency)) {
                    $h['uncertain'] = true;
                } else {
                    $h['cash_record_count']++;
                    $sum = $h['by_currency'][$p->currency] ?? 0;
                    if ($sum > PHP_INT_MAX - $minor) {
                        throw new SubscriptionAuditException('Cash history exceeds exact monetary limits.', 'audit_amount_overflow');
                    }
                    $h['by_currency'][$p->currency] = $sum + $minor;
                }
                $result[$id] = $h;
            }
        }
        if (Schema::hasTable('wallet_transactions')) {
            foreach (DB::table('wallet_transactions')->whereIn('client_id', $clients->keys())->where('type', 'credit')->select(['client_id', 'platform_id', 'payment_id', 'reference_type', 'performed_by', DB::raw("JSON_EXTRACT(metadata, '$.performed_by_role') AS credit_actor_role"), DB::raw("JSON_EXTRACT(metadata, '$.credit_classification') AS credit_classification")])->orderBy('id')->lazy(500) as $credit) {
                $this->budget($started);
                if ((int) $credit->platform_id !== (int) $clients[$credit->client_id]->platform_id) {
                    continue;
                }
                $result[$credit->client_id] ??= $this->emptyHistory();
                if ($credit->payment_id) {
                    $funding = Payment::query()->reportableSuccessful()->whereKey($credit->payment_id)->where('platform_id', $credit->platform_id)->where('client_id', $credit->client_id)->where('purpose', 'wallet_topup')->where('provider_environment', 'live')->where('amount', '>', 0)->exists();
                    if (! $funding) {
                        $result[$credit->client_id]['uncertain'] = true;
                    }
                } else {
                    if (($credit->reference_type === 'admin_adjustment' && $credit->performed_by && trim((string) $credit->credit_actor_role, '"') === 'admin') || trim((string) $credit->credit_classification, '"') === 'noncash_grant') {
                        $result[$credit->client_id]['noncash_credit_count']++;
                    } else {
                        $result[$credit->client_id]['uncertain'] = true;
                    }
                }
            }
        }

        return $result;
    }

    /** Uses the event report's exact canonicalization, supplement and allocation rules. */
    private function actions(array $clientIds, SubscriptionAuditRun $run, CarbonImmutable $asOf, float $started): array
    {
        ['events' => $events, 'deals' => $deals, 'payments' => $payments, 'trialEvidence' => $trial, 'platforms' => $platforms] = app(SubscriptionAuditBuilder::class)->sources(null, $asOf, $clientIds);
        $this->budget($started);
        $traceable = DB::table('subscription_audit_events')->where('run_id', $run->id)->whereIn('client_id', $clientIds)->get()->keyBy('event_ref');
        [$approvalCandidates] = app(SubscriptionAuditBuilder::class)->approvalCandidates($events, $asOf);
        $aliases = Schema::hasTable('mcp_staff_aliases') ? DB::table('mcp_staff_aliases')->where('active', true)->pluck('agent_alias', 'user_id')->all() : [];
        usort($events, fn ($a, $b) => [$a['occurred_at'], $a['source_type'], $a['source_id']] <=> [$b['occurred_at'], $b['source_type'], $b['source_id']]);
        $result = $allocated = $refinements = [];
        foreach ($events as $event) {
            $event['trial_evidence'] = $trial[$event['payment_id']] ?? null;
            $event['approval_candidates'] = $approvalCandidates[$event['platform_id'].':'.$event['deal_id']] ?? [];
            $event['allocation_scope'] = 'all_retained_profile_history';
            $site = $platforms[$event['platform_id']] ?? null;
            $projection = app(SubscriptionAuditClassifier::class)->project($event, $deals[$event['deal_id']] ?? null, $payments[$event['payment_id']] ?? null,
                ['website' => $site ? parse_url(str_contains($site->domain ?? '', '://') ? $site->domain : 'https://'.($site->domain ?? ''), PHP_URL_HOST) : null, 'market_label' => $site?->name, 'country' => $site?->country, 'currency' => $site?->currency_code], $aliases, $allocated);
            $row = $projection['payload'];
            if (isset($traceable[$row['event_ref']])) {
                $refinements[] = array_replace((array) $traceable[$row['event_ref']], [
                    'actor_id' => $projection['actor_id'], 'approver_id' => $projection['approver_id'], 'staff_handle' => $projection['staff_handle'],
                    'exception' => $projection['exception'], 'payload' => json_encode(app(\App\Services\Mcp\ToolResultSanitizer::class)->sanitize($row), JSON_THROW_ON_ERROR)]);
            }
            $row['trace_state'] = $traceable->has($row['event_ref']) ? 'available_in_run' : 'outside_trace_window';
            if ($row['trace_state'] !== 'available_in_run') {
                $row['event_ref'] = null;
            }
            $id = $event['client_id'];
            $result[$id]['latest'] = $row;
            if (in_array($row['event_type'], ['activation', 'renewal'], true)) {
                $result[$id]['activation'] = $row;
            }
        }

        // Refine only matching draft rows before the run becomes visible as completed.
        foreach (array_chunk($refinements, 100) as $batch) {
            DB::table('subscription_audit_events')->upsert($batch, ['id'], ['actor_id', 'approver_id', 'staff_handle', 'exception', 'payload']);
        }
        $this->budget($started);

        return array_map(fn ($rows) => array_values(array_filter([$rows['latest'] ?? null, $rows['activation'] ?? null])), $result);
    }

    private function bounded($query)
    {
        $rows = $query->limit(self::SOURCE_LIMIT + 1)->get();
        if ($rows->count() > self::SOURCE_LIMIT) {
            throw new SubscriptionAuditException('Retained profile sources exceed the reporting ceiling.', 'audit_source_limit', 503, -32020);
        }

        return $rows;
    }

    private function budget(float $started): void
    {
        if (microtime(true) - $started > (float) config('mcp.subscription_profiles.build_seconds', 600)) {
            throw new SubscriptionAuditException('Profile snapshot exceeded its time budget.', 'audit_source_limit', 503, -32020);
        }
    }
}
