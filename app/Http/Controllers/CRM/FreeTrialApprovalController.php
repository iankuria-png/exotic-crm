<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\MarketTrialApprovalPolicy;
use App\Models\Platform;
use App\Models\Product;
use App\Models\TrialActivationOperation;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\Trials\TrialActivationService;
use App\Services\Trials\TrialApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FreeTrialApprovalController extends Controller
{
    public function __construct(private readonly TrialApprovalService $approvals, private readonly TrialActivationService $activation) {}

    public function index(Request $request)
    {
        $this->approvals->ready();
        $actor = $request->user();
        abort_unless($actor->isActive(), 403);
        $q = FreeTrialRequest::query();
        app(MarketAuthorizationService::class)->applyPlatformScope($q, $actor);
        if ($actor->role !== 'admin') {
            $q->where('requester_id', $actor->id);
        }
        if ($request->filled('platform_id')) {
            $this->approvals->access($actor, (int) $request->platform_id);
            $q->where('platform_id', $request->platform_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        $rows = $q->orderByDesc('id')->paginate(25);

        return response()->json(['data' => collect($rows->items())->map(fn ($r) => $this->serialize($r))->all(), 'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]]);
    }

    public function catalogue(Request $request, Platform $platform)
    {
        $this->approvals->access($request->user(), $platform->id);
        $v = $request->validate(['search' => 'nullable|string|max:120', 'client_id' => 'nullable|integer']);
        $clients = Client::where('platform_id', $platform->id)->when(! empty($v['search']), fn ($q) => $q->where('name', 'like', '%'.addcslashes($v['search'], '%_').'%'))->orderBy('name')->limit(50)->get(['id', 'name']);
        $deals = collect();
        if (! empty($v['client_id'])) {
            abort_unless(Client::where('platform_id', $platform->id)->whereKey($v['client_id'])->exists(), 404);
            $deals = Deal::where('platform_id', $platform->id)->where('client_id', $v['client_id'])->whereIn('status', ['pending', 'active', 'expired', 'cancelled'])->orderByDesc('id')->limit(50)->get(['id', 'client_id', 'product_id', 'plan_type', 'status', 'duration_days']);
        }

        return response()->json(['clients' => $clients, 'deals' => $deals, 'policy' => $this->approvals->policy($platform->id)]);
    }

    public function show(Request $request, FreeTrialRequest $trial)
    {
        $this->view($request, $trial);

        return response()->json(['request' => $this->serialize($trial), 'operations' => TrialActivationOperation::where('request_id', $trial->id)->get(['id', 'operation_key', 'client_id', 'deal_id', 'state', 'error_code', 'dispatched_at', 'verified_at']),
            'events' => DB::table('free_trial_request_events')->leftJoin('users', 'users.id', '=', 'free_trial_request_events.actor_id')->where('request_id', $trial->id)->orderByDesc('free_trial_request_events.id')->limit(100)->get(['free_trial_request_events.id', 'action', 'reason', 'free_trial_request_events.created_at', 'users.name as actor'])]);
    }

    public function store(Request $request)
    {
        $v = $request->validate(['action' => 'required|in:activate,extend,renew,field_trial,subsidiary,seo_boost', 'deal_id' => 'nullable|integer', 'client_id' => 'nullable|integer', 'platform_id' => 'nullable|integer', 'product_id' => 'nullable|integer', 'duration_days' => 'nullable|integer|min:1|max:365', 'additional_days' => 'nullable|integer|min:1|max:365', 'reason' => 'required|string|max:1000', 'free_trial_pin' => ['nullable', 'regex:/^\d{4,6}$/'], 'request_key' => 'nullable|uuid',
            'selected_client_ids' => 'nullable|array|min:1|max:200', 'selected_client_ids.*' => 'integer|exists:clients,id', 'targets' => 'nullable|array', 'product_price_id' => 'nullable|integer', 'create_confirmed' => 'nullable|boolean',
            'duration_mode' => 'nullable|in:match_primary,custom', 'copy_region_id' => 'nullable|integer|min:1', 'copy_city_id' => 'nullable|integer|min:1', 'copy_currency_id' => 'nullable|integer|min:1']);
        if ($v['action'] === 'subsidiary') {
            $v['request_key'] ??= (string) \Illuminate\Support\Str::uuid();
        }
        $trial = DB::transaction(function () use ($request, $v) {
            $main = null;
            if ($v['action'] === 'subsidiary') {
                $main = Deal::lockForUpdate()->findOrFail($v['deal_id'] ?? 0);
                $this->approvals->access($request->user(), (int) $main->platform_id);
                app(\App\Services\SubsidiaryTrialWorkflow::class)->supersede($main, $request->user(), $v['request_key'] ?? null);
            }
            $terms = $this->terms($request, $v['action'], $v);
            $trial = $this->approvals->create($request->user(), $terms, $v['free_trial_pin'] ?? null, $v['request_key'] ?? null);
            if ($main) $main->update(['pending_subsidiary_trial' => ['status' => 'pending', 'platform_id' => $trial->platform_id, 'trial_request_id' => $trial->id, 'subsidiary_client_id' => $trial->client_id]]);
            return $trial;
        });
        if ($trial->status === 'approved') {
            $trial = $this->activation->execute($trial);
        }

        return $this->outcome($trial);
    }

    public function legacy(Request $request, string $action, ?Deal $deal = null, ?Client $client = null)
    {
        $request->validate(['reason' => 'nullable|string|max:1000', 'notes' => 'nullable|string|max:1000', 'free_trial_pin' => ['nullable', 'regex:/^\d{4,6}$/'], 'pin' => ['nullable', 'regex:/^\d{4,6}$/'],
            'duration_days' => 'nullable|integer|min:1|max:365', 'additional_days' => 'nullable|integer|min:1|max:365', 'request_key' => 'nullable|uuid',
            'selected_client_ids' => 'nullable|array|min:1|max:200', 'selected_client_ids.*' => 'integer|exists:clients,id', 'targets' => 'nullable|array|max:20', 'targets.*.canonical_key' => 'required|string|max:160', 'targets.*.display_city' => 'required|string|max:160', 'targets.*.target_count' => 'required|integer|min:1|max:100']);
        $input = $request->all();
        if ($deal) {
            $input['deal_id'] = $deal->id;
        }if ($client) {
            $input['client_id'] = $client->id;
        }
        if ($action === 'subsidiary_retry') {
            $intent = $deal->pending_subsidiary_trial ?? [];
            if (! empty($intent['trial_request_id'])) {
                $trial = FreeTrialRequest::findOrFail($intent['trial_request_id']);
                $this->view($request, $trial);
                if (in_array($trial->status, ['uncertain', 'activating'])) {
                    abort_unless($request->user()->role === 'admin', 403, 'Only an admin can recover a held trial.');
                    $trial = $this->activation->recover($trial);
                } elseif ($trial->status === 'approved') {
                    $trial = $this->activation->execute($trial);
                }

                return $this->outcome($trial);
            }
            abort(409, 'This historical trial has no saved approval. Review and submit fresh trial details instead of retrying it.');
        }
        $input['reason'] = trim((string) ($input['reason'] ?? $input['notes'] ?? '')) ?: 'Requested '.$action.' free-trial authorization.';
        $terms = $this->terms($request, $action, $input);
        $trial = $this->approvals->create($request->user(), $terms, $input['free_trial_pin'] ?? null, $input['request_key'] ?? null);
        if ($trial->status === 'approved') {
            $trial = $this->activation->execute($trial);
        }

        return $this->outcome($trial, $deal);
    }

    public function createForSubsidiary(Request $request, Deal $main, array $input): FreeTrialRequest
    {
        $key = $input['request_key'] ?? (string) \Illuminate\Support\Str::uuid();
        $data = ['deal_id' => $main->id, 'platform_id' => $input['platform_id'], 'client_id' => $input['client_id'] ?? null, 'duration_days' => $input['duration_days'] ?? 7, 'create_confirmed' => filter_var($input['create_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN), 'reason' => 'Optional subsidiary trial linked to subscription '.$main->id,
            'duration_mode' => $input['duration_mode'] ?? null, 'copy_region_id' => $input['copy_region_id'] ?? null, 'copy_city_id' => $input['copy_city_id'] ?? null, 'copy_currency_id' => $input['copy_currency_id'] ?? null, 'request_key' => $key];
        $terms = $this->terms($request, 'subsidiary', $data);

        return $this->approvals->create($request->user(), $terms, $input['pin'] ?? null, $key);
    }

    public function decision(Request $request, FreeTrialRequest $trial)
    {
        $v = $request->validate(['action' => 'required|in:approve,deny', 'version' => 'required|integer', 'reason' => 'required|string|max:2000']);
        $trial = $this->approvals->decide($request->user(), $trial, $v['action'], $v['version'], $v['reason']);
        if ($trial->status === 'approved') {
            $trial = $this->activation->execute($trial);
        }

        return $this->outcome($trial);
    }

    public function resume(Request $request, FreeTrialRequest $trial)
    {
        $this->view($request, $trial);
        abort_unless($request->user()->role === 'admin', 403);
        $v = $request->validate(['reason' => 'required|string|max:2000', 'version' => 'required|integer']);
        abort_unless((int) $trial->version === (int) $v['version'], 409, 'Trial request changed.');
        abort_unless(in_array($trial->status, ['approved', 'activating']), 409, 'Only undispatched eligible work can resume. Check uncertain operations first.');
        $this->approvals->event($trial, $request->user(), 'resume_requested', [], $v['reason']);

        return $this->outcome($this->activation->execute($trial));
    }

    public function recover(Request $request, FreeTrialRequest $trial)
    {
        $this->view($request, $trial);
        abort_unless($request->user()->role === 'admin', 403);
        $request->validate(['reason' => 'required|string|max:2000']);
        $trial = $this->activation->recover($trial);

        return $this->outcome($trial);
    }

    public function cancel(Request $request, FreeTrialRequest $trial)
    {
        $this->view($request, $trial);
        $v = $request->validate(['version' => 'required|integer', 'reason' => 'required|string|max:2000']);

        return DB::transaction(function () use ($request, $trial, $v) {
            $trial = FreeTrialRequest::lockForUpdate()->findOrFail($trial->id);
            if ($trial->action === 'subsidiary') app(\App\Services\SubsidiaryTrialWorkflow::class)->assertCancelable($trial);
            $copyHeld = $trial->status === 'activating' && $trial->action === 'subsidiary' && app(\App\Services\SubsidiaryProfileCopyService::class)->tagged($trial) && ! TrialActivationOperation::where('request_id', $trial->id)->exists();
            abort_unless($trial->version === (int) $v['version'] && (in_array($trial->status, ['pending', 'approved']) || $copyHeld) && ! TrialActivationOperation::where('request_id', $trial->id)->whereNotNull('dispatched_at')->exists(), 409, 'This trial changed or has already been dispatched.');
            if ($trial->action === 'subsidiary') app(\App\Services\SubsidiaryProfileCopyService::class)->cancelled($trial);
            $trial->update(['status' => 'canceled', 'active_identity' => null, 'version' => $trial->version + 1]);
            $this->approvals->event($trial, $request->user(), 'canceled', [], $v['reason']);

            return $this->outcome($trial);
        });
    }

    public function reauthorizeCopy(Request $request, FreeTrialRequest $trial)
    {
        $this->view($request, $trial);
        $v = $request->validate(['request_version' => 'required|integer', 'run_version' => 'required|integer', 'reason' => 'required|string|max:2000', 'free_trial_pin' => ['nullable', 'regex:/^\d{4,6}$/']]);
        $trial = $this->approvals->reauthorizeCopy($request->user(), $trial, (int) $v['request_version'], (int) $v['run_version'], $v['reason'], $v['free_trial_pin'] ?? null);

        return $this->outcome($trial);
    }

    public function policy(Request $request, Platform $platform)
    {
        $this->approvals->access($request->user(), $platform->id);

        return response()->json($this->approvals->policy($platform->id));
    }

    public function savePolicy(Request $request, Platform $platform)
    {
        $this->approvals->access($request->user(), $platform->id);
        abort_unless($request->user()->role === 'admin' && ! $request->attributes->get('crm_impersonator'), 403);
        $v = $request->validate(['version' => 'required|integer|min:1', 'approval_mode' => 'required|in:admin_only,admin_or_pin', 'reason' => 'required|string|max:2000', 'pin_exception_reason' => 'required_if:approval_mode,admin_or_pin|nullable|string|max:2000', 'review_on' => 'required_if:approval_mode,admin_or_pin|nullable|date_format:Y-m-d']);

        return DB::transaction(function () use ($request, $platform, $v) {
            $p = MarketTrialApprovalPolicy::firstOrCreate(['platform_id' => $platform->id]);
            $p = MarketTrialApprovalPolicy::whereKey($platform->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->version === (int) $v['version'], 409, 'Market policy changed. Reload it before saving.');
            if ($v['approval_mode'] === 'admin_or_pin') {
                abort_unless(app(\App\Services\WalletSettingsService::class)->freeTrialPinIsConfigured(), 422, 'Set the global free-trial PIN before enabling PIN approval.');
            }
            $before = $p->toArray();
            $p->update(['approval_mode' => $v['approval_mode'], 'pin_exception_reason' => $v['pin_exception_reason'] ?? null, 'review_on' => $v['review_on'] ?? null, 'updated_by' => $request->user()->id, 'version' => $p->version + 1]);
            abort_unless(app(\App\Services\AuditService::class)->record(['platform_id' => $platform->id, 'actor_id' => $request->user()->id, 'entity_type' => 'market_trial_policy', 'entity_id' => $platform->id, 'action' => 'trial_policy_changed', 'before_state' => $before, 'after_state' => $p->toArray(), 'reason' => $v['reason']]), 500, 'Policy audit failed.');

            return response()->json($this->approvals->policy($platform->id));
        });
    }

    public function terms(Request $request, string $action, array $input): array
    {
        $actor = $request->user();
        $deal = ! empty($input['deal_id']) ? Deal::with('client.platform', 'product')->findOrFail($input['deal_id']) : null;
        if ($deal) {
            $this->approvals->access($actor, (int) $deal->platform_id);
        }
        $client = $deal?->client ?: (! empty($input['client_id']) ? Client::with('platform')->findOrFail($input['client_id']) : null);
        $market = (int) ($action === 'subsidiary' || $action === 'seo_boost' ? ($input['platform_id'] ?? 0) : ($client?->platform_id ?? 0));
        $this->approvals->access($actor, $market);
        $days = (int) ($input['additional_days'] ?? $input['duration_days'] ?? $deal?->duration_days ?? 7);
        $product = (int) ($deal?->product_id ?? $input['product_id'] ?? 0);
        $clientIds = $client ? [$client->id] : [];
        if (in_array($action, ['activate', 'extend', 'renew'])) {
            abort_unless((bool) $deal, 422, 'Choose a source subscription.');
            abort_unless(match ($action) {
                'activate' => $deal->status === 'pending' && ! $deal->activated_at && ! $deal->payment_id,'extend' => $deal->status === 'active','renew' => in_array($deal->status, ['expired', 'cancelled'])
            }, 422, 'This subscription is not eligible for the requested trial action.');
            $trialRule = app(\App\Billing\Repositories\BillingConfigurationRepository::class)->subscriptionRuleForMarket($market);
            abort_if($trialRule && ! data_get($trialRule->free_trial_json, 'enabled', false), 422, 'Free trials are disabled for this market.');
        } elseif ($action === 'field_trial') {
            abort_unless($client && in_array($actor->role, ['admin', 'field_sales']), 403);
            $product = (int) $client->platform->field_trial_product_id;
            $days = max(1, (int) ($client->platform->field_trial_duration_days ?: 7));
            abort_if($product <= 0, 422, 'Field trial product is not configured.');
            $deposit = app(FieldSalesController::class)->depositStatus($request, $client)->getData(true);
            abort_unless(! empty($deposit['received']), 422, 'Activation deposit has not been received yet.');
        } elseif ($action === 'subsidiary') {
            abort_unless($deal && $deal->platform_id !== $market, 422, 'Choose a separate subsidiary market.');
            $preview = app(\App\Services\SubsidiaryTrialService::class)->previewForDeal($deal, Platform::findOrFail($market), ! empty($input['client_id']) ? (int) $input['client_id'] : null,
                ['duration_mode' => $input['duration_mode'] ?? null, 'duration_days' => $input['duration_days'] ?? null, 'copy' => empty($input['client_id']),
                    'copy_region_id' => $input['copy_region_id'] ?? null, 'copy_city_id' => $input['copy_city_id'] ?? null, 'copy_currency_id' => $input['copy_currency_id'] ?? null, 'collection_obligation_id' => $input['collection_obligation_id'] ?? null]);
            abort_if((bool) $preview['config_errors'], 422, implode('; ', $preview['config_errors']));
            abort_unless(in_array($preview['resolution']['kind'], ['existing', 'create_if_needed']), 409, 'Resolve the target profile or existing coverage before requesting a trial.');
            abort_if((bool) $preview['duration']['error'], 422, $preview['duration']['message'] ?? 'Review the subscription period.');
            $days = (int) $preview['duration']['days'];
            $promotion = app(\App\Services\SubsidiaryPromotionPolicyService::class)->terms($deal, $preview['duration']);
            $copyStatus = $preview['copy_preview']['status'] ?? 'off';
            $input['subsidiary_identity'] = $preview['resolution']['identity'];
            $input['subsidiary_manual'] = ! empty($input['client_id']);
            $product = (int) $preview['target_platform']['trial_product_id'];
            $target = ! empty($input['client_id']) ? Client::where('platform_id', $market)->findOrFail($input['client_id']) : null;
            $clientIds = $target ? [$target->id] : [];
            abort_unless($target || ! empty($input['create_confirmed']), 422, 'Confirm creation of a WordPress subsidiary profile.');
            $input['subsidiary_seed'] = $preview['will_create'] ?? ['name' => $deal->client->name, 'phone_normalized' => app(\App\Services\SubsidiaryClientResolver::class)->targetPhone($deal->client, Platform::findOrFail($market)), 'email' => $deal->client->email, 'city' => $deal->client->city];
            if (! $target && $copyStatus !== 'off') {
                // A paired new profile is only created with its complete public copy.
                $promotion += app(\App\Services\SubsidiaryProfileCopyService::class)->terms($preview['copy_preview'], $input['subsidiary_seed'], (string) ($input['request_key'] ?? \Illuminate\Support\Str::uuid()));
            }
        } elseif ($action === 'seo_boost') {
            abort_unless(! empty($input['selected_client_ids']) && ! empty($input['targets']), 422, 'Choose profiles and target cities.');
            $preview = app(\App\Services\SeoBoostService::class)->preview($market, $input['targets'], 200, $input['selected_client_ids']);
            $clientIds = array_values(array_unique(array_map('intval', $input['selected_client_ids'])));
            abort_if((bool) array_diff($clientIds, array_column($preview['candidates'], 'client_id')), 409, 'Some SEO Boost profiles are no longer eligible. Refresh the preview.');
            $product = (int) ($input['product_id'] ?? 0);
        }

        return array_filter(($promotion ?? []) + ['collection_obligation_id' => $input['collection_obligation_id'] ?? null, 'action' => $action, 'platform_id' => $market, 'source_deal_id' => $deal?->id, 'client_ids' => $clientIds, 'product_id' => $product, 'duration_days' => $days,
            'product_price_id' => $input['product_price_id'] ?? $deal?->product_price_id, 'targets' => $action === 'seo_boost' ? $input['targets'] : null,
            'subsidiary_basis' => $action === 'subsidiary' ? app(\App\Services\SubsidiaryTrialWorkflow::class)->basis(['source_deal_id' => $deal->id, 'product_id' => $product]) : null,
            'subsidiary_identity' => $action === 'subsidiary' ? ($input['subsidiary_identity'] ?? null) : null, 'subsidiary_manual' => $action === 'subsidiary' ? ($input['subsidiary_manual'] ?? false) : null,
            'subsidiary_seed' => $action === 'subsidiary' ? ($input['subsidiary_seed'] ?? null) : null, 'create_confirmed' => $action === 'subsidiary' ? ! empty($input['create_confirmed']) : null,
            'reason' => trim((string) ($input['reason'] ?? '')) ?: 'Requested '.$action.' free-trial authorization.'], fn ($v) => $v !== null);
    }

    public function outcome(FreeTrialRequest $r, ?Deal $deal = null)
    {
        $message = match ($r->status) {
            'active' => 'Free trial activated and verified on the site.','pending' => 'Free-trial request sent. An admin must approve before activation.','uncertain' => 'Approval saved. Activation is being checked; do not submit another trial.','approved' => 'Trial approved; waiting for its primary subscription or activation.',default => 'Trial request saved.'
        };

        return response()->json(['trial_request' => $this->serialize($r), 'message' => $message, 'deal' => $deal?->fresh(), 'activation_status' => $r->status], $r->status === 'active' ? 200 : 202);
    }

    private function view(Request $request, FreeTrialRequest $r): void
    {
        abort_if($request->attributes->get('crm_impersonator'), 403);
        $this->approvals->access($request->user(), $r->platform_id);
        if (! empty($r->terms_json['collection_obligation_id'])) {
            $o = app(\App\Services\Collections\CollectionSubsidiaryFulfillmentService::class)->forRequest($r);
            app(\App\Services\Collections\CollectionAccessService::class)->receipt($request->user(), $o->allocation->receipt_id);
        } else {
        abort_unless($request->user()->role === 'admin' || (int) $r->requester_id === (int) $request->user()->id, 403);
        }
    }

    private function serialize(FreeTrialRequest $r): array
    {
        return array_merge($r->toArray(), ['market' => Platform::find($r->platform_id)?->name, 'requester' => User::find($r->requester_id)?->name, 'approver' => User::find($r->authorized_actor_id)?->name,
            'clients' => Client::whereIn('id', $r->terms_json['client_ids'])->get(['id', 'name'])->toArray(), 'package' => Product::find($r->terms_json['product_id'])?->name]);
    }
}
