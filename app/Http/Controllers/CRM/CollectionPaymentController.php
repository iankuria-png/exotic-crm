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
use App\Services\Collections\CollectionPolicyService;
use App\Services\Collections\CollectionPrivacy;
use App\Services\Collections\CollectionPurchaseService;
use App\Services\Collections\CollectionReviewService;
use App\Services\Collections\CollectionSettlementService;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectionPaymentController extends Controller
{
    public function __construct(private readonly CollectionAccessService $access) {}

    public function capabilities(Request $request)
    {
        return response()->json(['enabled' => (bool) config('collection.workspace_enabled') && Schema::hasTable('collection_accounts'),
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
            'platform_id' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:128', 'page' => 'nullable|integer|min:1', 'export' => 'nullable|boolean']);
        $q = CollectionReceipt::with(['account', 'allocation.effects'])->whereIn('account_id', $this->access->accountIds($request->user()));
        if (! empty($v['state'])) {
            $q->where('decision_state', $v['state']);
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
            });
        }
        if (! empty($v['search'])) {
            $q->where('reference_norm', 'like', '%'.strtoupper($v['search']).'%');
        }
        if ($v['export'] ?? false) {
            return response()->streamDownload(function () use ($q) {
                $f = fopen('php://output', 'w');
                fputcsv($f, ['Reference', 'Account', 'Currency', 'Principal minor', 'Received UTC', 'Funds', 'Subscription/review', 'Historical only']);
                foreach ($q->orderBy('id')->cursor() as $r) {
                    // Provider references are opaque; prevent formula injection in spreadsheet clients.
                    fputcsv($f, ["'".$r->reference_norm, "'".$r->account->label, $r->currency, $r->principal_minor, $r->provider_at, $r->funds_state, $r->decision_state, $r->historical_only ? 'Yes' : 'No']);
                }
                fclose($f);
            }, 'collection-receipts.csv', ['Content-Type' => 'text/csv']);
        }
        $rows = $q->orderByDesc('id')->paginate(25);
        $rows->getCollection()->transform(fn ($r) => $this->present($r));

        return response()->json($rows);
    }

    public function show(Request $request, int $receipt)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $r->load('account', 'allocation.effects');
        $payload = $this->present($r);
        $candidates = app(CollectionDecisionService::class)->candidates($r);
        $payload['candidates'] = $candidates;
        $payload['payer_links'] = CollectionPayerLink::where('account_id', $r->account_id)->where('payer_fingerprint', $r->payer_fingerprint)->get()->toArray();
        $payload['history'] = DB::table('collection_audit')->where('receipt_id', $r->id)->orderByDesc('id')->limit(50)->get();
        $payload['corrections'] = $r->allocation ? CollectionCorrection::where('allocation_id', $r->allocation->id)->orderByDesc('created_at')->get() : [];
        $payload['field_provenance'] = $r->field_provenance;
        $payload['conflicts'] = $r->conflicts;

        return response()->json($payload);
    }

    private function present(CollectionReceipt $r): array
    {
        $allocation = $r->allocation;
        $p = $allocation?->purchase_snapshot;
        $clientIds = array_filter([$p['client_id'] ?? null, data_get($p, 'subsidiary.client_id')]);
        $clients = Client::whereIn('id', $clientIds)->get(['id', 'name', 'platform_id']);

        return ['id' => $r->id, 'account_id' => $r->account_id, 'account_label' => $r->account->label, 'account_mode' => $r->account->mode,
            'reference' => $r->reference_norm, 'principal_minor' => $r->principal_minor, 'currency' => $r->currency,
            'payer_masked' => $r->payer_phone ? '••••'.substr($r->payer_phone, -4) : 'Not supplied by receiving message',
            'provider_at' => $r->provider_at, 'received_at' => $r->first_received_at, 'historical_only' => $r->historical_only,
            'funds_state' => $r->funds_state, 'evidence_level' => $r->evidence_level, 'decision_state' => $r->decision_state,
            'reviewed_at' => $r->reviewed_at, 'version' => $r->version, 'clients' => $clients, 'purchase' => $p,
            'allocation' => $allocation ? ['id' => $allocation->id, 'state' => $allocation->state, 'version' => $allocation->version, 'payment_id' => $allocation->payment_id,
                'decision_source' => $allocation->decision_source, 'effects' => $allocation->effects->map(fn ($e) => ['id' => $e->id, 'client_id' => $e->client_id,
                    'kind' => $e->effect_kind, 'state' => $e->state, 'platform_id' => $e->platform_id, 'interval' => $e->contribution,
                    'superseded_at' => $e->superseded_at, 'last_error_code' => $e->last_error_code])] : null,
            'reasons' => array_merge(data_get($r->candidate_snapshot, 'reasons', []), $r->conflicts ?? [])];
    }

    public function candidates(Request $request, int $receipt)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $v = $request->validate(['search' => 'required|string|min:2|max:128']);
        $term = $v['search'];
        $platforms = DB::table('collection_account_platforms')->where('account_id', $r->account_id)->whereNull('valid_until')->pluck('platform_id');
        $phone = app(CollectionPrivacy::class)->tzPhone($term);
        $payerClients = $phone ? CollectionPayerLink::where('account_id', $r->account_id)->where('payer_fingerprint', app(CollectionPrivacy::class)->fingerprint($phone))->pluck('client_id')->all() : [];
        $clients = Client::whereIn('platform_id', $platforms)->where(function ($q) use ($term, $payerClients) {
            $q->where('name', 'like', '%'.$term.'%')->orWhere('phone_normalized', 'like', '%'.$term.'%')->orWhere('wp_post_id', $term)->orWhereIn('id', $payerClients);
        })->orderBy('name')->limit(20)->get(['id', 'name', 'platform_id', 'wp_post_id']);

        return response()->json(['clients' => $clients, 'prices' => ProductPrice::with('product:id,name,tier,platform_id')->where('is_active', true)->where('currency', $r->currency)
            ->whereHas('product', fn ($q) => $q->whereIn('platform_id', $platforms)->where('is_active', true)->where(fn ($q) => $q->whereNull('is_archived')->orWhere('is_archived', false)))->get(),
            'quotes' => Deal::whereIn('client_id', $clients->pluck('id'))->whereIn('status', ['pending', 'active'])->where('currency', $r->currency)->orderByDesc('id')->limit(60)->get(),
            'recent_subscriptions' => Deal::whereIn('client_id', $clients->pluck('id'))->whereNotNull('activated_at')->where('amount', '>', 0)
                ->where('status', '!=', 'cancelled')->orderByDesc('activated_at')->limit(40)->get(['id', 'client_id', 'plan_type', 'duration_days', 'amount', 'currency', 'product_price_id', 'origin', 'discount_source', 'discount_approved_by', 'activated_at'])]);
    }

    public function createIntent(Request $request, int $receipt, CollectionPurchaseService $purchases)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $v = $request->validate(['client_id' => 'required|integer', 'product_price_id' => 'required|integer', 'deal_id' => 'nullable|integer',
            'payer_link_id' => 'nullable|uuid', 'authority' => 'required|in:reviewed_quote,standing_agreement', 'max_renewals' => 'nullable|integer|min:1|max:100',
            'valid_until' => 'required|date|after:now', 'subsidiary_client_id' => 'nullable|integer']);
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
        $v = $request->validate(['target_intent_id' => 'nullable|uuid', 'action' => 'required|in:reassign,change_package,undo_effect', 'expected_version' => 'required|integer', 'reason' => 'required|string|min:3|max:1000']);

        return response()->json($corrections->preview($r->allocation, $request->user(), $v['action'], $v['target_intent_id'] ?? null, $v['expected_version'], $v['reason']));
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
