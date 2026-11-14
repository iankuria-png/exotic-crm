<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionCorrection;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\ProductPrice;
use App\Services\Collections\CollectionAccessService;
use App\Services\Collections\CollectionApprovalService;
use App\Services\Collections\CollectionCorrectionService;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionDeviceHealthService;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionPayerEvidenceService;
use App\Services\Collections\CollectionPolicyService;
use App\Services\Collections\CollectionPrivacy;
use App\Services\Collections\CollectionPurchaseService;
use App\Services\Collections\CollectionReceiptStatusService;
use App\Services\Collections\CollectionReviewContextService;
use App\Services\Collections\CollectionReviewService;
use App\Services\Collections\CollectionSettlementService;
use App\Services\MarketAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectionPaymentController extends Controller
{
    public function __construct(private readonly CollectionAccessService $access) {}

    public function capabilities(Request $request)
    {
        return response()->json(['enabled' => (bool) config('collection.workspace_enabled') && Schema::hasTable('collection_accounts'),
            'sales_storage_ready' => (bool) config('collection.workspace_enabled') && Schema::hasTable('collection_sales_sessions'),
            'entitlement_writes_enabled' => count(config('collection.entitlement_platforms')) > 0]);
    }

    private function enabled(): void
    {
        abort_unless(config('collection.workspace_enabled') && Schema::hasTable('collection_accounts'), 404);
    }

    public function index(Request $request)
    {
        $this->enabled();
        $v = $request->validate(['state' => 'nullable|string|max:32', 'account_id' => 'nullable|uuid', 'client_id' => 'nullable|integer|min:1',
            'platform_id' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:128', 'page' => 'nullable|integer|min:1', 'export' => 'nullable|boolean',
            'sort' => 'nullable|in:newest,oldest,amount_high,amount_low', 'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d'.($request->filled('date_from') ? '|after_or_equal:date_from' : '')]);
        $q = CollectionReceipt::select('collection_receipts.*')->with(['account', 'allocation.effects', 'sale.items'])->whereIn('account_id', $this->access->accountIds($request->user()));
        if (! empty($v['state'])) {
            if (in_array($v['state'], ['needs_action', 'already_recorded'], true)) {
                app(CollectionReceiptStatusService::class)->filter($q, $v['state']);
            } elseif ($v['state'] === 'reversed') {
                $q->whereIn('funds_state', ['reversed', 'disputed']);
            } elseif ($v['state'] === 'purchase_saved') {
                $q->whereDoesntHave('allocation')->where('historical_only', false)->whereIn('funds_state', ['observed', 'statement_verified'])
                    ->where(fn ($q) => $q->whereNull('conflicts')->orWhereJsonLength('conflicts', 0))
                    ->whereExists(fn ($intent) => $intent->selectRaw('1')->from('collection_purchase_intents')
                        ->whereColumn('collection_purchase_intents.account_id', 'collection_receipts.account_id')
                        ->whereColumn('collection_purchase_intents.provider_reference', 'collection_receipts.reference_norm')
                        ->where('authority', 'reviewed_quote')->whereNull('revoked_at'));
                if (Schema::hasTable('collection_sales_sessions')) {
                    $q->whereDoesntHave('sale');
                }
            } elseif (str_starts_with($v['state'], 'sales_') && Schema::hasTable('collection_sales_sessions')) {
                $q->whereHas('sale', fn ($sale) => $sale->where('state', substr($v['state'], 6)));
            } else {
                $q->where('decision_state', $v['state']);
            }
        }
        if (! empty($v['account_id'])) {
            $q->where('account_id', $v['account_id']);
        }
        if (! empty($v['platform_id'])) {
            $q->whereIn('account_id', DB::table('collection_account_platforms')->where('platform_id', $v['platform_id'])->select('account_id'));
        }
        if (! empty($v['client_id'])) {
            $client = Client::findOrFail($v['client_id']);
            app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($request->user(), (int) $client->platform_id);
            $q->where(function ($q) use ($client) {
                $q->whereHas('allocation', fn ($a) => $a->where('purchase_snapshot->client_id', $client->id)->orWhere('purchase_snapshot->subsidiary->client_id', $client->id))
                    ->orWhereIn('payer_fingerprint', CollectionPayerLink::where('client_id', $client->id)->whereNull('revoked_at')->select('payer_fingerprint'));
                if (Schema::hasTable('collection_sales_sessions')) {
                    $q->orWhereHas('sale.items', fn ($item) => $item->where('client_id', $client->id));
                }
            });
        }
        if (! empty($v['search'])) {
            $phone = app(CollectionPrivacy::class)->tzPhone($v['search']);
            $q->where(fn ($search) => $search->where('reference_norm', 'like', '%'.strtoupper($v['search']).'%')
                ->when($phone, fn ($search) => $search->orWhere('payer_fingerprint', app(CollectionPrivacy::class)->fingerprint($phone))));
        }
        $effective = 'COALESCE(provider_at, first_received_at, collection_receipts.created_at)';
        if (! empty($v['date_from'])) {
            $q->whereRaw($effective.' >= ?', [CarbonImmutable::parse($v['date_from'], 'Africa/Dar_es_Salaam')->startOfDay()->utc()]);
        }
        if (! empty($v['date_to'])) {
            $q->whereRaw($effective.' < ?', [CarbonImmutable::parse($v['date_to'], 'Africa/Dar_es_Salaam')->startOfDay()->addDay()->utc()]);
        }
        $sort = $v['sort'] ?? 'newest';
        if (str_starts_with($sort, 'amount_')) {
            // Currencies are grouped explicitly before amount comparisons.
            $q->orderBy('currency')->orderBy('principal_minor', $sort === 'amount_high' ? 'desc' : 'asc');
        }
        $direction = $sort === 'oldest' ? 'asc' : 'desc';
        $q->orderByRaw($effective.' '.$direction)->orderBy('collection_receipts.id', $direction);
        if ($v['export'] ?? false) {
            return response()->streamDownload(function () use ($q) {
                $f = fopen('php://output', 'w');
                fputcsv($f, ['Reference', 'Account', 'Currency', 'Principal minor', 'Paid UTC', 'Phone arrival UTC', 'Funds', 'Subscription/review', 'Payment code status', 'Historical only']);
                $q->chunk(100, function ($receipts) use ($f) {
                    $usage = app(CollectionReceiptStatusService::class)->forReceipts($receipts);
                    foreach ($receipts as $r) {
                        // Provider references are opaque; prevent formula injection in spreadsheet clients.
                        fputcsv($f, ["'".$r->reference_norm, "'".$r->account->label, $r->currency, $r->principal_minor, $r->provider_at, $r->first_received_at, $r->funds_state, $r->decision_state, $usage[$r->id]['state'], $r->historical_only ? 'Yes' : 'No']);
                    }
                });
                fclose($f);
            }, 'collection-receipts.csv', ['Content-Type' => 'text/csv']);
        }
        $rows = $q->paginate(25);
        $usage = app(CollectionReceiptStatusService::class)->forReceipts($rows->getCollection());
        $evidence = app(CollectionPayerEvidenceService::class)->forReceipts($rows->getCollection());
        $counts = DB::table('collection_receipts')->whereIn('account_id', $rows->pluck('account_id')->unique())
            ->whereIn('payer_fingerprint', $rows->pluck('payer_fingerprint')->filter()->unique())
            ->selectRaw('account_id, payer_fingerprint, COUNT(*) AS total')->groupBy('account_id', 'payer_fingerprint')->get()
            ->keyBy(fn ($c) => $c->account_id.':'.$c->payer_fingerprint);
        $activations = app(\App\Services\Collections\CollectionActivationAttribution::class)->forReceipts($rows->getCollection(), $usage);
        $balances = app(\App\Services\Collections\CollectionWalletBalanceService::class)->forReceipts($rows->getCollection());
        $rows->getCollection()->transform(fn ($r) => $this->present($r, $evidence[$r->id], $r->payer_fingerprint ? (int) ($counts[$r->account_id.':'.$r->payer_fingerprint]->total ?? 0) : null, $usage[$r->id], $activations[$r->id] ?? null));

        $rows->getCollection()->transform(fn ($row) => $row + ['wallet_balance' => $balances[$row['id']] ?? null]);
        $needsAction = null;
        $wallet = [];
        if (empty($v['client_id'])) {
            $wallet = app(\App\Services\Collections\CollectionWalletBalanceService::class)->latest($this->access->accountIds($request->user()));
            $pending = CollectionReceipt::whereIn('account_id', $this->access->accountIds($request->user()))
                ->when(! empty($v['account_id']), fn ($q) => $q->where('account_id', $v['account_id']))
                ->when(! empty($v['platform_id']), fn ($q) => $q->whereIn('account_id', DB::table('collection_account_platforms')->where('platform_id', $v['platform_id'])->select('account_id')));
            app(CollectionReceiptStatusService::class)->filter($pending, 'needs_action');
            $needsAction = $pending->count();
        }

        $reversals = empty($v['client_id']) ? $this->reversalAlerts($this->access->accountIds($request->user())) : [];

        return response()->json($rows->toArray() + ['counts' => ['needs_action' => $needsAction], 'wallet_balances' => $wallet, 'reversals' => $reversals]);
    }

    public function packageRuleEvidence(Request $request, string $account)
    {
        $this->enabled();
        app(\App\Services\MarketAuthorizationService::class)->ensureManager($request->user());
        $a = $this->access->account($request->user(), $account);

        return response()->json(app(\App\Services\Collections\CollectionPackageHistoryService::class)->evidence($a));
    }

    public function show(Request $request, int $receipt)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $r->load('account', 'allocation.effects', 'sale.items');
        $usage = app(CollectionReceiptStatusService::class)->forReceipts(collect([$r]), true)[$r->id];
        $payload = $this->present($r, null, null, $usage, app(\App\Services\Collections\CollectionActivationAttribution::class)->forReceipts(collect([$r]), [$r->id => $usage])[$r->id] ?? null);
        $payload['wallet_balance'] = app(\App\Services\Collections\CollectionWalletBalanceService::class)->forReceipts(collect([$r]))[$r->id] ?? null;
        $candidates = app(CollectionDecisionService::class)->candidates($r);
        $payload['candidates'] = $candidates;
        $payload['payer_links'] = CollectionPayerLink::where('account_id', $r->account_id)->where('payer_fingerprint', $r->payer_fingerprint)->get()->toArray();
        $timelineInput = $request->validate(['timeline_before' => 'sometimes|integer|min:1']);
        $payload['timeline'] = app(\App\Services\Collections\CollectionAuditTimeline::class)->forReceipt($r, isset($timelineInput['timeline_before']) ? (int) $timelineInput['timeline_before'] : null);
        $payload['history'] = DB::table('collection_audit')->where('receipt_id', $r->id)->orderByDesc('id')->limit(50)->get();
        $payload['corrections'] = $r->allocation ? CollectionCorrection::where('allocation_id', $r->allocation->id)->orderByDesc('created_at')->get() : [];
        $payload['related_payments'] = $r->allocation ? app(CollectionReceiptStatusService::class)->relatedPayments($r) : null;
        $review = DB::table('collection_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->where('a.receipt_id', $r->id)
            ->where('a.action', 'like', 'reversal_reviewed%')->orderByDesc('a.id')->first(['a.context', 'a.created_at', 'u.name']);
        $payload['reversal_review'] = $review ? (json_decode((string) $review->context, true) ?: []) + ['at' => \Illuminate\Support\Carbon::parse($review->created_at)->toIso8601String(), 'by' => $review->name] : null;
        $payload['field_provenance'] = $r->field_provenance;
        $payload['conflicts'] = $r->conflicts;

        return response()->json($payload);
    }

    private function present(CollectionReceipt $r, ?array $payer = null, ?int $capturedCount = null, ?array $usage = null, ?array $activation = null): array
    {
        $allocation = $r->allocation;
        $p = $allocation?->purchase_snapshot;
        $obligation = $allocation && Schema::hasTable('collection_subsidiary_obligations') ? \App\Models\Collections\CollectionSubsidiaryObligation::where('allocation_id', $allocation->id)->orderByDesc('source_generation')->first() : null;
        $savedChoice = ! $allocation ? \App\Models\Collections\CollectionPurchaseIntent::where('account_id', $r->account_id)
            ->where('provider_reference', $r->reference_norm)->where('authority', 'reviewed_quote')->whereNull('revoked_at')->latest('created_at')->first() : null;
        $p ??= $savedChoice?->purchase_snapshot;
        $clientIds = array_filter([$p['client_id'] ?? null, data_get($p, 'subsidiary.client_id')]);
        $sale = $r->relationLoaded('sale') ? $r->sale : (Schema::hasTable('collection_sales_sessions') ? $r->sale()->with('items')->first() : null);
        if ($sale) {
            $clientIds = array_unique(array_merge($clientIds, $sale->items->pluck('client_id')->all()));
        }
        $matching = data_get($r->candidate_snapshot, 'matching', []);
        $suggestions = $matching['suggestions'] ?? [];
        $profiles = Client::whereIn('id', array_unique(array_merge($clientIds, array_column($suggestions, 'id'))))->get(['id', 'name', 'platform_id'])->keyBy('id');
        $clients = $profiles->only($clientIds)->values();
        $suggestions = array_values(array_filter(array_map(fn ($candidate) => isset($profiles[$candidate['id']])
            ? ['id' => $candidate['id'], 'name' => $profiles[$candidate['id']]->name, 'score' => $candidate['score']] : null, $suggestions)));

        return ['id' => $r->id, 'receipt_link' => $p['receipt_link'] ?? null, 'subsidiary_obligation' => $obligation && $allocation->state !== 'unassigned' && empty($p['receipt_link']) ? ['state' => $obligation->state, 'version' => $obligation->version, 'last_error_code' => $obligation->last_error_code] : null, 'account_id' => $r->account_id, 'account_label' => $r->account->label, 'account_mode' => $r->account->mode,
            'reference' => $r->reference_norm, 'principal_minor' => $r->principal_minor, 'currency' => $r->currency,
            'payer' => $payer ?? app(CollectionPayerEvidenceService::class)->forReceipt($r), 'captured_payer_receipts' => $capturedCount,
            'usage' => $usage, 'activation' => $activation, 'payer_masked' => $r->payer_phone ? '••••'.substr($r->payer_phone, -4) : 'Not supplied by receiving message',
            'provider_at' => $r->provider_at, 'received_at' => $r->first_received_at, 'created_at' => $r->created_at, 'historical_only' => $r->historical_only,
            'funds_state' => $r->funds_state, 'evidence_level' => $r->evidence_level, 'decision_state' => $sale ? 'sales_'.$sale->state : ($savedChoice && ! $r->historical_only && ! $r->conflicts && in_array($r->funds_state, ['observed', 'statement_verified']) ? 'purchase_saved' : $r->decision_state),
            'sale' => $sale ? ['id' => $sale->id, 'state' => $sale->state, 'items_count' => $sale->items->count(), 'confirmed_count' => $sale->items->where('state', 'confirmed')->count()] : null,
            'match_decision' => $allocation && $allocation->decision_source === 'automatic' ? data_get($r->candidate_snapshot, 'matching') : null,
            'match_suggestions' => $suggestions, 'match_needs_choice' => (bool) ($matching['needs_choice'] ?? false),
            'reviewed_at' => $r->reviewed_at, 'version' => $r->version, 'clients' => $clients, 'purchase' => $p,
            'allocation' => $allocation ? ['id' => $allocation->id, 'state' => $allocation->state, 'version' => $allocation->version, 'payment_id' => $allocation->payment_id,
                'decision_source' => $allocation->decision_source, 'effects' => $allocation->effects->map(fn ($e) => ['id' => $e->id, 'client_id' => $e->client_id, 'correction_id' => $e->correction_id,
                    'kind' => $e->effect_kind, 'state' => $e->state, 'platform_id' => $e->platform_id, 'interval' => $e->contribution,
                    'superseded_at' => $e->superseded_at, 'last_error_code' => $e->last_error_code])] : null,
            'reasons' => empty($p['receipt_link']) ? array_merge(data_get($r->candidate_snapshot, 'reasons', []), $r->conflicts ?? []) : []];
    }

    public function reviewContext(Request $request, int $receipt, CollectionReviewContextService $context)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $input = $request->validate(['cursor' => 'nullable|string|max:4096', 'selected_client_id' => 'nullable|integer|min:1',
            'product_price_id' => 'nullable|integer|min:1', 'deal_id' => 'nullable|integer|min:1', 'subsidiary_client_id' => 'nullable|integer|min:1', 'intent_id' => 'nullable|uuid', 'workflow' => 'nullable|in:manual']);

        return response()->json($context->context($r, $request->user(), $input))->header('Cache-Control', 'private, no-store');
    }

    public function messages(Request $request, int $receipt, \App\Services\Collections\CollectionMessageService $messages)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);

        return response()->json($messages->forReceipt($r))->header('Cache-Control', 'private, no-store');
    }

    public function candidates(Request $request, int $receipt)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $v = $request->validate(['search' => 'required_without:selected_client_id|string|min:2|max:128',
            'selected_client_id' => 'nullable|integer|min:1', 'subsidiary_for_client_id' => 'nullable|integer|min:1', 'workflow' => 'nullable|in:manual']);
        $context = app(CollectionReviewContextService::class);
        if (! empty($v['selected_client_id'])) {
            $client = $context->selectable()->findOrFail($v['selected_client_id']);

            return response()->json(['clients' => [$context->clientData($client)]] + $context->options($r, $request->user(), $client, ($v['workflow'] ?? null) !== 'manual'))->header('Cache-Control', 'private, no-store');
        }
        if (! empty($v['subsidiary_for_client_id'])) {
            $primary = $context->selectable()->findOrFail($v['subsidiary_for_client_id']);
            app(CollectionPurchaseService::class)->assertScope($r->account, $primary, $request->user());
            $secondary = (int) config('collection.subsidiary_platforms.'.$primary->platform_id);
            abort_unless($secondary > 0, 422);
            app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($request->user(), $secondary);
            $phone = app(CollectionPrivacy::class)->tzPhone($v['search']);
            $clients = $context->selectable()->where('platform_id', $secondary)->where(fn ($q) => $q->where('name', 'like', '%'.$v['search'].'%')
                ->orWhere('wp_post_id', $v['search'])->orWhere('phone_normalized', $phone ?? $v['search']))->orderBy('name')->limit(20)->get();

            return response()->json(['clients' => $context->clientRows($clients)])->header('Cache-Control', 'private, no-store');
        }
        $term = $v['search'];
        $platforms = DB::table('collection_account_platforms')->where('account_id', $r->account_id)->whereNull('valid_until')->pluck('platform_id');
        $phone = app(CollectionPrivacy::class)->tzPhone($term);
        $payerClients = $phone ? CollectionPayerLink::where('account_id', $r->account_id)->where('payer_fingerprint', app(CollectionPrivacy::class)->fingerprint($phone))->whereNull('revoked_at')->where('valid_until', '>', now())->pluck('client_id')->all() : [];
        $clients = $context->selectable()->whereIn('platform_id', $platforms)->where(function ($q) use ($term, $payerClients, $phone) {
            $q->where('name', 'like', '%'.$term.'%')->orWhere('wp_profile_permalink', $term)->orWhere('wp_profile_permalink', rtrim($term, '/').'/')->orWhere('phone_normalized', 'like', '%'.($phone ?? $term).'%')->orWhere('wp_post_id', $term)->orWhereIn('id', $payerClients);
        })->orderBy('name')->limit(20)->get();

        return response()->json(['clients' => $context->clientRows($clients), 'prices' => ProductPrice::with('product:id,name,tier,platform_id')->where('is_active', true)->where('currency', $r->currency)
            ->whereHas('product', fn ($q) => $q->whereIn('platform_id', $platforms)->where('is_active', true)->where(fn ($q) => $q->whereNull('is_archived')->orWhere('is_archived', false)))->get(),
            'quotes' => Deal::whereIn('client_id', $clients->pluck('id'))->whereIn('status', ['pending', 'active'])->where('currency', $r->currency)->orderByDesc('id')->limit(60)->get(['id', 'client_id', 'product_price_id', 'plan_type', 'duration_days', 'amount', 'currency', 'discount_source', 'discount_approved_by']),
            'recent_subscriptions' => Deal::whereIn('client_id', $clients->pluck('id'))->whereNotNull('activated_at')->where('amount', '>', 0)
                ->where('status', '!=', 'cancelled')->orderByDesc('activated_at')->limit(40)->get(['id', 'client_id', 'plan_type', 'duration_days', 'amount', 'currency', 'product_price_id', 'origin', 'discount_source', 'discount_approved_by', 'activated_at'])]);
    }

    public function createIntent(Request $request, int $receipt, CollectionPurchaseService $purchases)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $v = $request->validate(['client_id' => 'required|integer', 'product_price_id' => 'required|integer', 'deal_id' => 'nullable|integer',
            'payer_link_id' => 'nullable|uuid', 'request_key' => 'nullable|uuid', 'expected_version' => 'required_with:request_key|integer|min:1',
            'remember_payer' => 'nullable|boolean', 'replace_saved_choice' => 'nullable|boolean', 'authority' => 'required_without:request_key|in:reviewed_quote,standing_agreement', 'max_renewals' => 'nullable|integer|min:1|max:100',
            'valid_until' => 'required_without:request_key|date|after:now', 'subsidiary_client_id' => 'nullable|integer']);
        if (! empty($v['request_key'])) {
            $input = array_intersect_key($v, array_flip(['client_id', 'product_price_id', 'deal_id', 'subsidiary_client_id', 'request_key', 'expected_version', 'remember_payer', 'replace_saved_choice']));

            return response()->json($purchases->saveChoice($r, $request->user(), $input), 201);
        }
        $intent = $purchases->create($r->account, Client::findOrFail($v['client_id']), $request->user(), $v + ['provider_reference' => $r->reference_norm]);

        return response()->json($intent, 201);
    }

    public function reviewPayer(Request $request, int $receipt, CollectionPurchaseService $purchases)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $v = $request->validate(['client_id' => 'required|integer', 'auto_allowed' => 'required|boolean', 'valid_until' => 'required|date|after:now']);

        return response()->json($purchases->reviewPayer($r, Client::findOrFail($v['client_id']), $request->user(), $v['auto_allowed'], $v['valid_until']), 201);
    }

    public function approve(Request $request, int $receipt, CollectionApprovalService $approval)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $v = $request->validate(['intent_id' => 'required|uuid', 'expected_version' => 'required|integer']);

        return response()->json($approval->reserve($r->id, $v['intent_id'], $v['expected_version'], $request->user()), 202);
    }

    public function confirm(Request $request, int $receipt, CollectionReviewService $review)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $v = $request->validate(['expected_version' => 'required|integer']);

        return response()->json($review->confirm($r, $request->user(), $v['expected_version']));
    }

    public function correctionPreview(Request $request, int $receipt, CollectionCorrectionService $corrections)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        abort_unless($r->allocation, 422);
        $v = $request->validate(['target_intent_id' => 'nullable|uuid', 'action' => 'required|in:reassign,change_package,undo_effect',
            'expected_version' => 'required|integer|min:1', 'reason' => 'nullable|string|min:3|max:1000',
            'client_id' => 'nullable|integer|min:1', 'product_price_id' => 'nullable|integer|min:1']);
        $reason = trim((string) ($v['reason'] ?? '')) ?: ($v['action'] === 'undo_effect' ? 'Staff removed this payment activation.' : 'Staff corrected the profile or package for this payment.');
        if ($v['action'] !== 'undo_effect' && empty($v['target_intent_id'])) {
            abort_unless(! empty($v['client_id']) && ! empty($v['product_price_id']), 422, 'Choose the intended profile and package.');

            return response()->json($corrections->previewPurchase($r->allocation, $request->user(), (int) $v['client_id'],
                (int) $v['product_price_id'], (int) $v['expected_version'], $reason));
        }

        return response()->json($corrections->preview($r->allocation, $request->user(), $v['action'], $v['target_intent_id'] ?? null, $v['expected_version'], $reason));
    }

    public function executeCorrection(Request $request, string $correction, CollectionCorrectionService $corrections)
    {
        $this->enabled();
        $c = CollectionCorrection::findOrFail($correction);
        $a = CollectionAllocation::findOrFail($c->allocation_id);
        $this->access->receipt($request->user(), $a->receipt_id);
        $v = $request->validate(['preview_version' => 'required|integer', 'idempotency_key' => 'required|uuid']);

        return response()->json($corrections->execute($c, $request->user(), $v['preview_version'], $v['idempotency_key']), 202);
    }

    public function arrivals(Request $request)
    {
        $this->enabled();
        $accounts = $this->access->accountIds($request->user());
        $cursor = (int) (DB::table('collection_read_cursors')->where('user_id', $request->user()->id)->value('last_receipt_id') ?? 0);
        $events = DB::table('collection_receipts')->whereIn('account_id', $accounts)->where('historical_only', false)->where('id', '>', $cursor);
        $max = (int) ((clone $events)->max('id') ?? $cursor);
        $health = app(CollectionDeviceHealthService::class)->forActor($request->user());

        return response()->json(['unread_receipts' => (clone $events)->count('id'), 'cursor' => $max,
            'device_needs_attention' => $health['needs_attention'],
            'device_attention' => collect($health['devices'])->contains(fn ($d) => ($d['state'] ?? null) === 'account_review') ? 'wallet' : ($health['needs_attention'] ? 'phone' : null),
            'last_contact_at' => collect($health['devices'])->pluck('last_contact_at')->filter()->max()]);
    }

    public function deviceHealth(Request $request, CollectionDeviceHealthService $health)
    {
        $this->enabled();
        $v = $request->validate(['platform_id' => 'nullable|integer|min:1']);

        return response()->json($health->forActor($request->user(), isset($v['platform_id']) ? (int) $v['platform_id'] : null));
    }

    public function policies(Request $request, CollectionPolicyService $policies)
    {
        $this->enabled();

        return response()->json(['accounts' => CollectionAccount::whereIn('id', $this->access->accountIds($request->user()))
            ->orderBy('label')->get()->map(fn ($account) => $policies->present($account))]);
    }

    public function updatePolicy(Request $request, string $account, CollectionPolicyService $policies)
    {
        $this->enabled();

        return response()->json($policies->update($request->user(), $account, $request->all()));
    }

    public function recordSignoff(Request $request, string $account, \App\Services\Collections\CollectionAutomationPolicy $automation)
    {
        $this->enabled();

        return response()->json($automation->recordSignoff($request->user(), $account, $request->all()));
    }

    /** Payments the provider reversed or put on hold in the last 30 days, with whether service is still running and whether staff reviewed it. */
    private function reversalAlerts(array $accountIds): array
    {
        $rows = CollectionReceipt::with(['allocation.effects', 'sale.items'])->whereIn('account_id', $accountIds)->whereIn('funds_state', ['reversed', 'disputed'])
            ->where('updated_at', '>=', now()->subDays(30))->orderByDesc('updated_at')->limit(20)->get();
        $reviewed = DB::table('collection_audit')->whereIn('receipt_id', $rows->pluck('id'))->where('action', 'like', 'reversal_reviewed%')
            ->orderBy('id')->get(['receipt_id', 'action', 'context', 'created_at'])->keyBy('receipt_id');

        return $rows->map(function (CollectionReceipt $r) use ($reviewed) {
            $active = (bool) $r->allocation?->effects?->contains(fn ($e) => ! $e->superseded_at && $e->state === 'succeeded' && in_array($e->effect_kind ?? $e->kind ?? '', ['primary_paid', 'target_paid'], true))
                || in_array($r->sale?->state, ['confirmed', 'partial'], true);
            $review = $reviewed[$r->id] ?? null;

            return ['id' => $r->id, 'reference' => $r->reference_norm, 'principal_minor' => $r->principal_minor, 'currency' => $r->currency,
                'funds_state' => $r->funds_state, 'flagged_at' => $r->updated_at?->toIso8601String(), 'service_active' => $active,
                'reviewed' => $review ? ['decision' => json_decode((string) $review->context, true)['decision'] ?? null, 'at' => \Illuminate\Support\Carbon::parse($review->created_at)->toIso8601String()] : null];
        })->values()->all();
    }

    public function reviewReversal(Request $request, int $receipt)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $v = $request->validate(['decision' => 'required|in:keep_service', 'note' => 'required|string|min:3|max:500']);
        if (! in_array($r->funds_state, ['reversed', 'disputed'], true)) {
            throw new \App\Services\Collections\CollectionException('receipt_not_reversed', 422);
        }
        DB::table('collection_audit')->insertOrIgnore(['receipt_id' => $r->id, 'account_id' => $r->account_id, 'actor_id' => $request->user()->id,
            'action' => 'reversal_reviewed_service_kept', 'context' => json_encode(['decision' => 'Keep service active', 'note' => $v['note'], 'actor' => $request->user()->name]),
            'dedupe_key' => 'reversal-review:'.$r->id, 'created_at' => now()]);

        return response()->json(['reviewed' => true]);
    }

    public function readArrivals(Request $request)
    {
        $this->enabled();
        $v = $request->validate(['cursor' => 'required|integer|min:0']);
        $max = (int) (DB::table('collection_receipts')->whereIn('account_id', $this->access->accountIds($request->user()))->where('historical_only', false)->max('id') ?? 0);
        if ($v['cursor'] > $max) {
            throw new CollectionException('arrival_cursor_outside_scope', 422);
        }
        DB::transaction(function () use ($request, $v) {
            DB::table('collection_read_cursors')->insertOrIgnore(['user_id' => $request->user()->id, 'last_receipt_id' => 0, 'updated_at' => now()]);
            $row = DB::table('collection_read_cursors')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            DB::table('collection_read_cursors')->where('user_id', $row->user_id)->update(['last_receipt_id' => max($row->last_receipt_id, $v['cursor']), 'updated_at' => now()]);
        });

        return response()->json(['saved' => true]);
    }

    public function settlement(Request $request, string $account, CollectionSettlementService $settlements)
    {
        $this->enabled();

        return response()->json($settlements->summary($this->access->account($request->user(), $account)));
    }

    public function statement(Request $request, string $account, CollectionSettlementService $settlements)
    {
        $this->enabled();
        $a = $this->access->account($request->user(), $account);
        $v = $request->validate(['reference' => 'required|string|regex:/^[A-Z0-9]{10,11}$/', 'amount_minor' => 'required|integer|min:1', 'currency' => 'required|string|size:3',
            'provider_at' => 'required|date', 'artifact_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/', 'evidence_reference' => 'required|string|max:191']);

        return response()->json($settlements->statement($a, $request->user(), $v));
    }

    public function alias(Request $request, int $receipt, CollectionSettlementService $settlements)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $v = $request->validate(['reference' => 'required|string|max:128', 'evidence_reference' => 'required|string|max:191']);
        $settlements->alias($r, $request->user(), $v['reference'], $v['evidence_reference']);

        return response()->json(['saved' => true]);
    }

    public function adjustment(Request $request, string $account, CollectionSettlementService $settlements)
    {
        $this->enabled();
        $a = $this->access->account($request->user(), $account);
        $v = $request->validate(['kind' => 'required|in:opening,fee,reversal,remittance', 'receipt_id' => 'nullable|integer|min:1', 'amount_minor' => 'required|integer|min:0',
            'currency' => 'required|string|size:3', 'artifact_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/', 'evidence_reference' => 'required|string|max:191',
            'idempotency_key' => 'required|uuid', 'posted_at' => 'required|date']);
        $settlements->adjustment($a, $request->user(), $v);

        return response()->json(['saved' => true]);
    }
}
