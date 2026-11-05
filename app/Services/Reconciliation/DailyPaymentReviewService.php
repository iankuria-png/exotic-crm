<?php

namespace App\Services\Reconciliation;

use App\Models\DailyPaymentReview;
use App\Models\ManualPaymentBundle;
use App\Models\Payment;
use App\Models\PaymentCorrectionRequest;
use App\Models\PaymentReviewAttachment;
use App\Models\PaymentReviewOperation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PaymentQueueQueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Daily decisions belong to this ledger. Never save a Payment or activate a Deal here. */
class DailyPaymentReviewService
{
    public function __construct(private readonly ReviewAccess $access) {}

    public function listing(User $actor, array $filters, bool $export = false): array
    {
        $markets = $this->access->markets($actor);
        if ($export) {
            $exportScope = ! empty($filters['platform_id']) ? [(int) $filters['platform_id']] : $markets;
            $this->access->ensure($actor, $exportScope, 'export');
        }
        if (! empty($filters['platform_id'])) {
            $this->access->ensure($actor, [(int) $filters['platform_id']], $export ? 'export' : 'review');
            $markets = array_values(array_intersect($markets, [(int) $filters['platform_id']]));
        }
        $zone = config('reconciliation.timezone');
        $day = Carbon::parse($filters['date'] ?? now($zone)->toDateString(), $zone)->startOfDay();
        $launch = DB::table('payment_review_settings')->where('key', 'launch_date')->value('value');
        $from = ! empty($filters['earlier_unresolved']) ? Carbon::parse($launch, $zone)->startOfDay() : $day->copy();
        // NULL sources are common in legacy manual entries.
        $q = Payment::query()->businessVisible()->whereIn('platform_id', $markets)
            ->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', 'subscription'))
            ->where('amount', '>', 0)->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'wallet'));
        $dateSql = 'COALESCE((SELECT b.created_at FROM manual_payment_bundles b WHERE b.id=payments.manual_payment_bundle_id), payments.completed_at, payments.created_at)';
        $q->whereRaw($dateSql.' >= ?', [$from->copy()->utc()->toDateTimeString()])
            ->whereRaw($dateSql.' < ?', [$day->copy()->addDay()->utc()->toDateTimeString()])
            ->orderByDesc('payments.id');
        if (! empty($filters['history'])) {
            $ledger = DailyPaymentReview::whereBetween('business_date', [$from->toDateString(), $day->toDateString()]);
            $ledger->where(function ($query) use ($markets) {
                if (! $markets) {
                    $query->whereRaw('1=0');
                } else {
                    foreach ($markets as $market) {
                        $query->orWhereJsonContains('platform_ids_json', $market);
                    }
                }
            });
            $saved = $ledger->orderByDesc('id')->limit(config('reconciliation.max_units') + 1)->get();
            abort_if($saved->count() > config('reconciliation.max_units'), 422, 'Too many historical reviews. Narrow the market or day.');
            $keys = $saved->map(fn ($r) => $r->unit_type.':'.$r->unit_id);
        } else {
            $ids = $q->select(['id', 'manual_payment_bundle_id'])->limit(config('reconciliation.max_units') + 1)->get();
            abort_if($ids->count() > config('reconciliation.max_units'), 422, 'Too many records. Narrow the day or market before reviewing/exporting.');
            $keys = $ids->map(fn ($p) => $p->manual_payment_bundle_id ? 'bundle:'.$p->manual_payment_bundle_id : 'payment:'.$p->id)->unique();
        }
        $units = [];
        foreach ($keys as $key) {
            [$type, $id] = explode(':', $key);
            try {
                $u = $this->unit($actor, $type, (int) $id, false, $export);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if ($e->getStatusCode() === 403) {
                    continue;
                } throw $e;
            }
            if (! in_array($u['financial_status'], ['completed', 'expired', 'proof_active', 'committed']) && ! $u['review_id']) {
                continue;
            }
            if (($filters['channel'] ?? 'manual') !== 'all' && $u['channel'] !== ($filters['channel'] ?? 'manual')) {
                continue;
            }
            if (! empty($filters['owner_id']) && ! in_array((int) $filters['owner_id'], $u['recorder_ids'])) {
                continue;
            }
            if (! empty($filters['search']) && ! str_contains(mb_strtolower(json_encode([$u['reference'], $u['clients'], $u['id']])), mb_strtolower($filters['search']))) {
                continue;
            }
            if (! empty($filters['earlier_unresolved']) && in_array($u['review_status'], ['approved', 'denied']) && ! $u['flag_reason']) {
                continue;
            }
            $units[] = $u;
        }
        $counts = array_fill_keys(['pending', 'correction_requested', 'resubmitted', 'approved', 'denied', 'flagged'], 0);
        $totals = [];
        foreach ($units as $u) {
            $counts[$u['review_status']]++;
            if ($u['flag_reason']) {
                $counts['flagged']++;
            }
            if (! $u['is_current_cash'] || ! $u['currency']) {
                continue;
            }
            $c = $u['currency'];
            $totals[$c] ??= ['currency' => $c, 'recorded' => '0.00', 'approved' => '0.00'];
            $totals[$c]['recorded'] = $this->sum($totals[$c]['recorded'], $u['amount']);
            if ($u['review_status'] === 'approved') {
                $totals[$c]['approved'] = $this->sum($totals[$c]['approved'], $u['amount']);
            }
        }
        if (! empty($filters['status'])) {
            $units = array_values(array_filter($units, fn ($u) => $u['review_status'] === $filters['status']));
        }
        if (! empty($filters['flagged'])) {
            $units = array_values(array_filter($units, fn ($u) => (bool) $u['flag_reason']));
        }
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return ['data' => $export ? $units : array_slice($units, ($page - 1) * $perPage, $perPage),
            'summary' => ['counts' => $counts, 'totals' => array_values($totals)],
            'meta' => ['total' => count($units), 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil(count($units) / $perPage)), 'date' => $day->toDateString(), 'timezone' => $zone, 'as_of' => now()->toIso8601String()]];
    }

    public function unit(User $actor, string $type, int $id, bool $lock = false, bool $export = false, bool $assigned = false): array
    {
        $this->access->ready();
        abort_unless(in_array($type, ['payment', 'bundle']), 404);
        $bundle = null;
        if ($type === 'bundle') {
            $bq = ManualPaymentBundle::whereKey($id);
            if ($lock) {
                $bq->lockForUpdate();
            } $bundle = $bq->firstOrFail();
            $q = Payment::where('manual_payment_bundle_id', $id);
        } else {
            $q = Payment::whereKey($id);
        }
        if ($lock) {
            $q->lockForUpdate();
        }
        $payments = $q->with(['client', 'deal', 'product', 'manualSubmission', 'attribution'])->orderBy('id')->get();
        abort_if($payments->isEmpty(), 404);
        if ($type === 'payment') {
            abort_if((bool) $payments->first()->manual_payment_bundle_id, 422, 'Review the shared receipt as one bundle.');
        }
        $markets = array_values(array_unique(array_map('intval', $payments->pluck('platform_id')->all())));
        if ($bundle) {
            $markets = array_values(array_unique([...$markets, (int) $bundle->platform_id]));
        }
        $rq = DailyPaymentReview::where('unit_type', $type)->where('unit_id', $id);
        if ($lock) {
            $rq->lockForUpdate();
        } $review = $rq->first();
        if ($assigned) {
            abort_unless($actor->isActive() && $review && PaymentCorrectionRequest::where('review_id', $review->id)->where('assigned_to', $actor->id)->whereIn('status', ['open', 'submitted'])->exists(), 403);
            foreach ($markets as $m) {
                app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $m);
            }
        } else {
            $this->access->ensure($actor, $markets);
            if ($export) {
                $this->access->ensure($actor, $markets, 'export');
            }
        }
        if ($lock) {
            DB::table('payment_manual_submissions')->whereIn('payment_id', $payments->pluck('id'))->lockForUpdate()->get();
        }
        $snapshot = [];
        $clients = [];
        $recorders = [];
        $blockers = [];
        $manual = false;
        foreach ($payments as $p) {
            $manualId = Payment::whereKey($p->id);
            app(PaymentQueueQueryBuilder::class)->whereManualChannel($manualId);
            $manual = $manual || $manualId->exists();
            $snapshot[] = ['id' => $p->id, 'platform_id' => $p->platform_id, 'client_id' => $p->client_id, 'deal_id' => $p->deal_id,
                'amount' => (string) $p->amount, 'currency' => $p->currency, 'reference' => $p->transaction_reference ?: $p->reference_number,
                'completed_at' => $p->completed_at?->toIso8601String(), 'created_at' => $p->created_at?->toIso8601String(),
                'status' => $p->status, 'resolution' => $p->resolution_code, 'test' => $p->record_classification, 'environment' => $p->provider_environment,
                'source' => $p->source, 'purpose' => $p->purpose, 'confirmed_by' => $p->confirmed_by,
                'purchase' => data_get($p->payment_data, 'recovery_purchase'),
                'deal' => $p->deal?->only(['product_id', 'product_price_id', 'amount', 'currency', 'duration_days', 'activated_at', 'expires_at', 'is_free_trial']),
                'proof' => $p->manualSubmission?->only(['id', 'transaction_reference', 'proof_path', 'proof_size_bytes', 'updated_at', 'review_decision']),
                'attribution' => $p->attribution?->only(['attribution_state', 'version'])];
            $clients[] = ['id' => $p->client_id, 'name' => $p->client?->name ?: ($p->attribution?->original_client_id ? 'Archived advertiser' : 'Unmatched advertiser'), 'package' => $p->product?->name, 'duration_days' => $p->deal?->duration_days, 'payment_id' => $p->id];
            if ($p->confirmed_by) {
                $recorders[] = (int) $p->confirmed_by;
            }
            $actors = DB::table('audit_log')->where('platform_id', $p->platform_id)->where('entity_type', 'payment')->where('entity_id', $p->id)
                ->whereIn('action', ['payment_confirm_match', 'payment_manual_approve', 'payment_manual_verify', 'payment_import_commit', 'payment_create'])->whereNotNull('actor_id')->pluck('actor_id')->all();
            $recorders = [...$recorders, ...array_map('intval', $actors)];
            if (! $p->client_id && ! $p->attribution?->original_client_id) {
                $blockers[] = 'Advertiser attribution is missing';
            }
            if ($p->attribution?->attribution_state === 'disputed') {
                $blockers[] = 'Advertiser attribution is disputed';
            }
            if (! $p->currency || (float) $p->amount <= 0) {
                $blockers[] = 'Amount or currency is invalid';
            }
            if ($p->isSandboxTest() || $p->isClassifiedTest() || in_array($p->resolution_code, ['reversed', 'invalid_reference']) || ! in_array($p->purpose, [null, 'subscription'], true) || $p->source === 'wallet' || $p->deal?->is_free_trial) {
                $blockers[] = 'This is not eligible subscription cash';
            }
            if (! in_array($p->status, Payment::SUCCESSFUL_STATUSES) && ! ($p->manualSubmission?->activated_on_submit && $p->manualSubmission?->review_decision !== 'rejected' && $p->deal?->status === 'active' && ! in_array($p->status, ['failed', 'reversed']))) {
                $blockers[] = 'Collection is not completed';
            }
            $ref = trim((string) ($p->transaction_reference ?: $p->reference_number));
            if ($ref === '') {
                $blockers[] = 'Receipt reference is missing';
            } elseif (Payment::businessVisible()->where('platform_id', $p->platform_id)->where('amount', '>', 0)->whereNotIn('id', $payments->pluck('id'))
                ->where(fn ($q) => $q->where('transaction_reference', $ref)->orWhere('reference_number', $ref))->exists()) {
                $blockers[] = 'Receipt reference also appears on another record';
            }
        }
        if ($bundle) {
            $snapshot[] = ['bundle' => $bundle->only(['id', 'platform_id', 'total_amount', 'allocated_amount', 'unallocated_amount', 'currency', 'status', 'reference_root', 'created_by'])];
            if ($bundle->created_by) {
                $recorders[] = (int) $bundle->created_by;
            }
            if ($bundle->status !== 'committed' || (float) $bundle->unallocated_amount !== 0.0) {
                $blockers[] = 'Shared receipt is not fully committed and allocated';
            }
            if ($payments->pluck('currency')->unique()->count() !== 1 || $payments->first()->currency !== $bundle->currency || abs($payments->sum('amount') - (float) $bundle->allocated_amount) > 0.005) {
                $blockers[] = 'Shared receipt allocations do not balance';
            }
        }
        $attachments = $review ? PaymentReviewAttachment::where('review_id', $review->id)->orderBy('id')->get() : collect();
        $correction = $review ? PaymentCorrectionRequest::where('review_id', $review->id)->whereNotNull('open_review_id')->first() : null;
        $financialSnapshot = array_map(function ($entry) {
            unset($entry['proof']);

            return $entry;
        }, $snapshot);
        $financialDigest = hash('sha256', json_encode($financialSnapshot, JSON_THROW_ON_ERROR));
        $snapshot[] = ['evidence' => $attachments->map->only(['id', 'sha256'])->all(), 'correction_revisions' => $correction?->response_revisions_json ?? []];
        $digest = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $stale = $review && ! hash_equals($review->source_digest, $digest);
        $status = $review?->status ?? 'pending';
        if ($stale && $status === 'approved') {
            $status = 'pending';
        }
        $first = $payments->first();
        $date = $bundle?->created_at ?: ($first->completed_at ?: $first->created_at);
        $recorders = array_values(array_unique($recorders));
        $unit = ['key' => $type.':'.$id, 'type' => $type, 'id' => $id, 'platform_ids' => $markets,
            'amount' => number_format((float) ($bundle?->total_amount ?? $first->amount), 2, '.', ''), 'currency' => $bundle?->currency ?: $first->currency,
            'reference' => $bundle?->reference_root ?: ($first->transaction_reference ?: $first->reference_number), 'clients' => $clients,
            'date' => $date?->toIso8601String(), 'date_fallback' => (bool) $bundle || ! $first->completed_at,
            'business_date' => $date?->copy()->timezone(config('reconciliation.timezone'))->toDateString(),
            'channel' => $manual ? 'manual' : ($first->provider_key ? 'provider' : 'other'),
            'financial_status' => $bundle?->status ?: ($first->manualSubmission?->activated_on_submit && $first->manualSubmission?->review_decision !== 'rejected' && $first->deal?->status === 'active' && ! in_array($first->status, ['failed', 'reversed']) ? 'proof_active' : $first->status),
            'is_current_cash' => $payments->every(fn ($p) => (float) $p->amount > 0 && ! $p->isSandboxTest() && ! $p->isClassifiedTest() && ! in_array($p->resolution_code, ['reversed', 'invalid_reference']) && $p->source !== 'wallet' && in_array($p->purpose, [null, 'subscription'], true) && ! $p->deal?->is_free_trial && (in_array($p->status, Payment::SUCCESSFUL_STATUSES) || ($p->manualSubmission?->activated_on_submit && $p->manualSubmission?->review_decision !== 'rejected' && $p->deal?->status === 'active' && ! in_array($p->status, ['failed', 'reversed'])))),
            'recorder_ids' => $recorders, 'recorders' => User::whereIn('id', $recorders)->get(['id', 'name'])->toArray(),
            'review_id' => $review?->id, 'review_version' => $review?->version ?? 0, 'source_digest' => $digest,
            'financial_digest' => $financialDigest, 'review_status' => $status, 'stale' => (bool) $stale, 'flag_reason' => $review?->flag_reason,
            'decision_reason' => $review?->decision_reason, 'decided_at' => $review?->decided_at?->toIso8601String(),
            'decided_by' => $review?->decided_by, 'self_approval' => (bool) $review?->self_approval,
            'blockers' => array_values(array_unique($blockers)), 'correction' => $correction?->toArray(),
            'attachments' => $attachments->map->only(['id', 'mime', 'size', 'sha256', 'created_at'])->all(),
            'proofs' => $payments->filter(fn ($p) => (bool) $p->manualSubmission?->proof_path)->map(fn ($p) => ['id' => $p->manualSubmission->id, 'payment_id' => $p->id, 'mime' => $p->manualSubmission->proof_mime])->values()->all(),
            'evidence_level' => $attachments->isNotEmpty() || $payments->contains(fn ($p) => (bool) $p->manualSubmission?->proof_path) ? 'Supporting evidence attached' : 'No proof attached',
            'settlement_label' => 'Receiving-account settlement not verified here'];
        $unit['_snapshot'] = $snapshot;

        return $unit;
    }

    public function publicUnit(array $unit): array
    {
        unset($unit['_snapshot']);

        return $unit;
    }

    public function preview(User $actor, array $input): array
    {
        $this->validateDecision($input);
        $eligible = [];
        $blocked = [];
        foreach ($input['units'] as $selected) {
            $u = $this->unit($actor, $selected['type'], (int) $selected['id']);
            $this->version($u, $selected);
            $reasons = $this->decisionBlockers($actor, $u, $input);
            if ($reasons) {
                $blocked[] = ['key' => $u['key'], 'reasons' => $reasons];
            } else {
                $eligible[] = ['type' => $u['type'], 'id' => $u['id'], 'key' => $u['key'], 'amount' => $u['amount'], 'currency' => $u['currency'], 'review_version' => $u['review_version'], 'source_digest' => $u['source_digest']];
            }
        }
        $payload = ['actor_id' => $actor->id, 'expires' => now()->addMinutes(5)->timestamp, 'input' => array_merge($input, ['units' => $eligible])];

        return ['eligible' => $eligible, 'blocked' => $blocked, 'preview_token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))];
    }

    public function commit(User $actor, string $token, string $key): array
    {
        try {
            $p = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, 'Invalid review preview.');
        }
        abort_unless((int) $p['actor_id'] === (int) $actor->id, 403);
        $input = $p['input'];
        $digest = hash('sha256', json_encode($input));

        // Serialize actor retries, including the first insertion of an idempotency key.
        return DB::transaction(function () use ($actor, $key, $p, $input, $digest) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            $units = $input['units'];
            usort($units, fn ($a, $b) => strcmp($a['type'].':'.str_pad($a['id'], 20, '0', STR_PAD_LEFT), $b['type'].':'.str_pad($b['id'], 20, '0', STR_PAD_LEFT)));
            $loaded = [];
            foreach ($units as $selected) {
                $loaded[] = $this->unit($actor, $selected['type'], $selected['id'], true);
            }
            $prior = PaymentReviewOperation::where('actor_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->payload_digest, $digest), 409, 'This retry key belongs to a different decision.');

                return $prior->result_json;
            }
            abort_if($p['expires'] < now()->timestamp, 409, 'The preview expired. Review the current records again.');
            abort_if(! $loaded, 422, 'No eligible receipts were selected.');
            $result = [];
            foreach ($loaded as $i => $u) {
                $this->version($u, $units[$i]);
                $reasons = $this->decisionBlockers($actor, $u, $input);
                abort_if((bool) $reasons, 409, implode('; ', $reasons));
                $review = $this->touch($u);
                $before = $review->toArray();
                if ($u['stale'] && $review->status === 'approved') {
                    $review->status = 'pending';
                }
                if ($u['stale']) {
                    $this->event($review, $actor, 'source_changed', ['previous_digest' => $review->source_digest], ['source_digest' => $u['source_digest']], 'Source changed after previous review.');
                }
                $action = $input['action'];
                $review->source_digest = $u['source_digest'];
                $review->snapshot_json = $u['_snapshot'];
                if ($action === 'flag') {
                    $review->flag_reason = $input['reason'];
                    $review->flagged_by = $actor->id;
                    $review->flagged_at = now();
                    if ($review->status === 'approved') {
                        $review->status = 'pending';
                    }
                } elseif ($action === 'clear_flag') {
                    $review->flag_reason = null;
                    $review->flagged_by = null;
                    $review->flagged_at = null;
                } elseif ($action === 'request_correction') {
                    $existing = PaymentCorrectionRequest::where('open_review_id', $review->id)->lockForUpdate()->first();
                    if ($existing) {
                        $existing->update(['open_review_id' => null, 'status' => 'canceled', 'version' => $existing->version + 1]);
                    }
                    PaymentCorrectionRequest::create(['review_id' => $review->id, 'open_review_id' => $review->id, 'assigned_to' => (int) $input['assigned_to'], 'requested_by' => $actor->id,
                        'reason_code' => $input['reason_code'] ?? 'other', 'required_fields' => $input['required_fields'] ?? [], 'financial_digest' => $u['financial_digest'], 'message' => $input['reason'], 'due_at' => $input['due_at'] ?? null, 'response_revisions_json' => [], 'status' => 'open']);
                    $review->status = 'correction_requested';
                } elseif ($action === 'reopen') {
                    $review->status = 'pending';
                } else {
                    $review->status = $action === 'approve' ? 'approved' : 'denied';
                    $review->decided_by = $actor->id;
                    $review->decided_at = now();
                    $review->decision_reason = $input['reason'] ?? null;
                    $review->self_approval = $action === 'approve' && in_array($actor->id, $u['recorder_ids']);
                    $review->self_approval_reason = $review->self_approval ? ($input['self_approval_reason'] ?? null) : null;
                    PaymentCorrectionRequest::where('open_review_id', $review->id)->update(['open_review_id' => null, 'status' => 'resolved', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                }
                $review->version++;
                $review->save();
                $this->event($review, $actor, $action, $before, $review->toArray(), $input['reason'] ?? null);
                $result[] = ['key' => $u['key'], 'status' => $review->status, 'version' => $review->version];
            }
            $response = ['results' => $result, 'count' => count($result)];
            $operation = PaymentReviewOperation::create(['actor_id' => $actor->id, 'idempotency_key' => $key, 'payload_digest' => $digest, 'result_json' => $response]);
            $response['operation_id'] = $operation->id;
            $operation->update(['result_json' => $response]);

            return $response;
        });
    }

    public function touch(array $u): DailyPaymentReview
    {
        return DailyPaymentReview::firstOrCreate(['unit_type' => $u['type'], 'unit_id' => $u['id']], ['platform_ids_json' => $u['platform_ids'], 'business_date' => $u['business_date'], 'reporting_timezone' => config('reconciliation.timezone'), 'source_digest' => $u['source_digest'], 'snapshot_json' => $u['_snapshot']]);
    }

    public function event(DailyPaymentReview $review, User $actor, string $action, ?array $before, array $after, ?string $reason): void
    {
        DB::table('payment_review_events')->insert(['review_id' => $review->id, 'actor_id' => $actor->id, 'action' => $action, 'before_json' => $before === null ? null : json_encode($before), 'after_json' => json_encode($after), 'reason' => $reason, 'created_at' => now()]);
        abort_unless(app(AuditService::class)->record(['platform_id' => $review->platform_ids_json[0], 'entity_type' => 'daily_payment_review', 'entity_id' => $review->id, 'actor_id' => $actor->id, 'action' => 'daily_review_'.$action, 'before_state' => ['status' => $before['status'] ?? null], 'after_state' => ['status' => $review->status, 'version' => $review->version], 'reason' => $reason]), 500, 'Review audit could not be saved.');
    }

    private function decisionBlockers(User $actor, array $u, array $input): array
    {
        $action = $input['action'];
        $reasons = [];
        if ($u['review_status'] === 'denied' && in_array($action, ['approve', 'request_correction'])) {
            $reasons[] = 'Reopen the denied record first';
        }
        if ($action === 'approve') {
            $reasons = [...$reasons, ...$u['blockers']];
            if ($u['flag_reason']) {
                $reasons[] = 'Resolve the flag before approval';
            }
            if ($u['review_status'] === 'correction_requested') {
                $reasons[] = 'Sales must resubmit the correction first';
            }
            if (in_array($actor->id, $u['recorder_ids']) || ! $u['recorder_ids']) {
                if (! $this->access->admin($actor)) {
                    $reasons[] = $u['recorder_ids'] ? 'A different reviewer must approve this receipt' : 'Recorder provenance is unavailable; admin review required';
                } elseif (trim($input['self_approval_reason'] ?? '') === '') {
                    $reasons[] = 'An admin exception reason is required';
                }
            }
        }
        if ($action === 'request_correction') {
            $this->access->assignee($actor, (int) ($input['assigned_to'] ?? 0), $u['platform_ids']);
        }

        return $reasons;
    }

    private function version(array $u, array $selected): void
    {
        abort_unless((int) $u['review_version'] === (int) ($selected['review_version'] ?? -1) && hash_equals($u['source_digest'], (string) ($selected['source_digest'] ?? '')), 409, 'The receipt changed. Refresh and review it again.');
    }

    private function validateDecision(array $input): void
    {
        abort_unless(in_array($input['action'] ?? '', ['approve', 'deny', 'flag', 'clear_flag', 'request_correction', 'reopen']), 422);
        abort_if(empty($input['units']) || count($input['units']) > 100, 422, 'Select between 1 and 100 receipts.');
        $keys = array_map(fn ($u) => $u['type'].':'.$u['id'], $input['units']);
        abort_if(count(array_unique($keys)) !== count($keys), 422, 'Duplicate receipt selection.');
        if ($input['action'] !== 'approve') {
            abort_if(trim($input['reason'] ?? '') === '', 422, 'A reason is required.');
        }
    }

    private function sum(string $a, string $b): string
    {
        return number_format(round((float) $a * 100) / 100 + round((float) $b * 100) / 100, 2, '.', '');
    }
}
