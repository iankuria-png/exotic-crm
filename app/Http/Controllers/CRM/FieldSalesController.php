<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CommissionService;
use App\Services\DealPaymentService;
use App\Services\FeatureSettingsService;
use App\Services\MarketAuthorizationService;
use App\Services\SubscriptionProvisioningService;
use App\Services\WalletService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FieldSalesController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly WalletService $walletService,
        private readonly DealPaymentService $dealPaymentService,
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService,
        private readonly CommissionService $commissionService,
        private readonly FeatureSettingsService $featureSettingsService,
        private readonly AuditService $auditService
    ) {
    }

    public function home(Request $request)
    {
        $user = $request->user();

        // Source of truth for "clients I created" is created_by, not signup_source.
        // signup_source can drift if a downstream WP re-sync clobbers the 'field' tag;
        // the agent must still see their work even when tagging breaks.
        $createdByQuery = Client::query()
            ->with(['platform:id,name,currency_code', 'activeDeal.product:id,name,display_name'])
            ->where('created_by', (int) $user->id);
        $this->marketAuthorizationService->applyPlatformScope($createdByQuery, $user);

        $fieldTaggedCount = (clone $createdByQuery)->where('signup_source', 'field')->count();
        $createdByCount = (clone $createdByQuery)->count();
        $untaggedCount = max(0, $createdByCount - $fieldTaggedCount);

        $commissionQuery = Commission::query()->where('agent_user_id', (int) $user->id);

        $recentClients = (clone $createdByQuery)->latest('id')->limit(10)->get();
        $trialsActivated = Deal::query()
            ->where('activated_by_field_agent', (int) $user->id)
            ->where('is_free_trial', true)
            ->where('status', 'active')
            ->count();
        $paidConversions = Deal::query()
            ->where('activated_by_field_agent', (int) $user->id)
            ->where('is_free_trial', false)
            ->whereIn('status', ['active', 'expired', 'renewed'])
            ->count();
        $commissionEarned = (clone $commissionQuery)->whereIn('status', ['pending', 'earned'])->sum('amount');
        $currency = strtoupper((string) (Commission::query()->where('agent_user_id', (int) $user->id)->latest('id')->value('currency') ?: 'KES'));

        return response()->json([
            'summary' => [
                'clients_created' => $createdByCount,
                'clients_field_tagged' => $fieldTaggedCount,
                'clients_untagged' => $untaggedCount,
                'trials_active' => $trialsActivated,
                'trials_activated' => $trialsActivated,
                'paid_conversions' => $paidConversions,
                'commission_earned' => number_format((float) $commissionEarned, 2, '.', ''),
                'commission_accrued' => number_format((float) $commissionEarned, 2, '.', ''),
                'commission_paid' => number_format((float) (clone $commissionQuery)->where('status', 'paid')->sum('amount'), 2, '.', ''),
                'this_month_earnings' => number_format((float) (clone $commissionQuery)->where('earned_at', '>=', now()->startOfMonth())->sum('amount'), 2, '.', ''),
                'currency' => $currency,
            ],
            'clients' => $recentClients,
            'recent_clients' => $recentClients,
            'markets' => $this->accessibleMarkets($user),
        ]);
    }

    public function depositStatus(Request $request, Client $client)
    {
        $this->authorizeFieldClient($request, $client);
        $client->loadMissing('platform');

        $thresholdMinor = (int) ($client->platform?->field_activation_deposit_minor ?? 5000);
        $threshold = $thresholdMinor / 100;
        $summary = $this->walletService->summary($client, 10);
        $currency = strtoupper((string) ($summary['currency'] ?? $client->platform?->currency_code ?? 'KES'));
        $balance = (float) ($summary['balance'] ?? 0);
        $matchingTransaction = collect($summary['transactions'] ?? [])
            ->first(fn ($transaction) => ($transaction['type'] ?? null) === 'credit'
                && strtoupper((string) ($transaction['currency'] ?? $currency)) === $currency
                && (float) ($transaction['amount'] ?? 0) >= $threshold);

        return response()->json([
            'client_id' => (int) $client->id,
            'threshold' => number_format($threshold, 2, '.', ''),
            'threshold_minor' => $thresholdMinor,
            'currency' => $currency,
            'balance' => number_format($balance, 2, '.', ''),
            'received' => $balance >= $threshold,
            'matching_transaction' => $matchingTransaction,
            'wallet' => $summary,
            'timeout_minutes' => $this->featureSettingsService->integer('field.deposit_poll_timeout_minutes', 10),
        ]);
    }

    public function activateTrial(Request $request, Client $client)
    {
        $this->authorizeFieldClient($request, $client, allowAdmin: true);
        $user = $request->user();
        $client->loadMissing('platform');
        $platform = $client->platform;

        if (!$platform) {
            return response()->json(['message' => 'Client market is missing.'], 422);
        }

        if ((int) ($client->wp_post_id ?? 0) <= 0) {
            return response()->json(['message' => "This client doesn't have a WordPress profile yet — re-run provisioning."], 422);
        }

        $deposit = $this->depositStatus($request, $client)->getData(true);
        if (empty($deposit['received'])) {
            return response()->json(['message' => 'Activation deposit has not been received yet.'], 422);
        }

        $productId = (int) ($platform->field_trial_product_id ?? 0);
        if ($productId <= 0) {
            return response()->json(['message' => 'Field trial product is not configured for this market.'], 422);
        }

        $product = Product::query()
            ->where('platform_id', (int) $platform->id)
            ->where('is_active', true)
            ->find($productId);
        if (!$product) {
            return response()->json(['message' => 'Configured field trial product is unavailable.'], 422);
        }

        $existingTrial = Deal::query()
            ->where('client_id', (int) $client->id)
            ->where('is_free_trial', true)
            ->where('status', 'active')
            ->latest('id')
            ->first();
        if ($existingTrial) {
            return response()->json([
                'message' => 'Client already has an active free trial.',
                'deal' => $existingTrial->load(['client', 'product', 'platform']),
            ], 422);
        }

        $durationDays = max(1, (int) ($platform->field_trial_duration_days ?? 7));

        return DB::transaction(function () use ($request, $client, $product, $durationDays, $user) {
            $deal = $this->dealPaymentService->createPendingDealFromCatalog(
                $client,
                (int) $product->id,
                null,
                'weekly',
                (int) $user->id,
                null
            );

            $deal->forceFill([
                'activated_by_field_agent' => (int) $user->id,
            ])->save();

            $activated = $this->subscriptionProvisioningService->activateDeal($deal, [
                'payment_method' => 'free_trial',
                'duration_days' => $durationDays,
                'is_free_trial' => true,
                'free_trial_approved_by' => (string) ($user->name ?: $user->email),
                'actor_id' => (int) $user->id,
                'activated_by_field_agent' => (int) $user->id,
                'timeline_context' => [
                    'source' => 'field_sales_trial_activation',
                ],
            ]);

            TimelineEvent::create([
                'platform_id' => (int) $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'event_type' => 'field_sales_trial_activated',
                'actor_id' => (int) $user->id,
                'content' => [
                    'deal_id' => (int) $activated->id,
                    'duration_days' => $durationDays,
                    'field_agent_id' => (int) $user->id,
                ],
                'created_at' => now(),
            ]);

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::FIELD_SALES_TRIAL_ACTIVATE,
                'deal',
                (int) $activated->id,
                null,
                [
                    'client_id' => (int) $client->id,
                    'duration_days' => $durationDays,
                    'field_agent_id' => (int) $user->id,
                ],
                'Field sales free trial activation'
            );

            return response()->json([
                'message' => 'Free trial activated.',
                'deal' => $activated->fresh(['client.platform', 'product', 'platform']),
            ], 201);
        });
    }

    public function commissions(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'earned', 'paid', 'void'])],
        ]);

        $query = Commission::query()
            ->with(['client:id,name,platform_id,phone_normalized', 'deal:id,activated_at,expires_at'])
            ->where('agent_user_id', (int) $request->user()->id)
            ->latest('earned_at')
            ->latest('id');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $base = Commission::query()->where('agent_user_id', (int) $request->user()->id);

        return response()->json([
            'summary' => [
                'accrued' => number_format((float) (clone $base)->whereIn('status', ['pending', 'earned'])->sum('amount'), 2, '.', ''),
                'paid' => number_format((float) (clone $base)->where('status', 'paid')->sum('amount'), 2, '.', ''),
                'this_month' => number_format((float) (clone $base)->where('earned_at', '>=', now()->startOfMonth())->sum('amount'), 2, '.', ''),
            ],
            'data' => $query->paginate((int) $request->input('per_page', 25)),
        ]);
    }

    public function adminCommissions(Request $request)
    {
        $validated = $this->validateCommissionFilters($request);
        $query = $this->buildCommissionsQuery($validated);

        return response()->json([
            'data' => $query->paginate((int) $request->input('per_page', 50)),
            'agents' => User::query()->where('role', MarketAuthorizationService::ROLE_FIELD_SALES)->orderBy('name')->get(['id', 'name', 'email']),
            'markets' => Platform::query()->orderBy('name')->get(['id', 'name', 'currency_code']),
            'summary' => $this->buildCommissionSummary($validated),
        ]);
    }

    public function exportCommissions(Request $request): StreamedResponse
    {
        $validated = $this->validateCommissionFilters($request);
        $query = $this->buildCommissionsQuery($validated);

        $filename = 'field-commissions-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'earned_at', 'agent_name', 'agent_email', 'market', 'client_name', 'client_phone',
                'deal_id', 'payment_reference', 'type', 'basis_amount', 'rate', 'amount', 'currency',
                'status', 'paid_at', 'payout_reference',
            ]);
            $query->chunkById(200, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        optional($row->earned_at)->toIso8601String(),
                        $row->agent?->name,
                        $row->agent?->email,
                        $row->client?->platform?->name,
                        $row->client?->name,
                        $row->client?->phone_normalized,
                        $row->deal_id,
                        $row->deal?->payment_reference,
                        $row->type,
                        $row->basis_amount,
                        $row->rate,
                        $row->amount,
                        $row->currency,
                        $row->status,
                        optional($row->paid_at)->toIso8601String(),
                        $row->payout?->external_reference,
                    ]);
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function validateCommissionFilters(Request $request): array
    {
        return $request->validate([
            'agent_user_id' => 'nullable|integer|exists:users,id',
            'market_id' => 'nullable|integer|exists:platforms,id',
            'status' => ['nullable', Rule::in(['pending', 'earned', 'paid', 'void'])],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);
    }

    private function buildCommissionsQuery(array $validated)
    {
        $query = Commission::query()
            ->with([
                'agent:id,name,email,role',
                'client:id,name,platform_id,phone_normalized,display_image_url,main_image_url,city',
                'client.platform:id,name,currency_code',
                'client.retentionInsight:client_id,score,band,primary_tag,computed_at',
                'deal:id,client_id,product_id,activated_at,expires_at,payment_reference,amount,currency',
                'deal.product:id,name,display_name',
                'payout:id,external_reference,paid_at,paid_by,notes',
                'payout.paidBy:id,name,email',
            ])
            ->latest('earned_at')
            ->latest('id');

        if (!empty($validated['agent_user_id'])) {
            $query->where('agent_user_id', (int) $validated['agent_user_id']);
        }
        if (!empty($validated['market_id'])) {
            $query->whereHas('client', fn ($clientQuery) => $clientQuery->where('platform_id', (int) $validated['market_id']));
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['date_from'])) {
            $query->where('earned_at', '>=', $validated['date_from']);
        }
        if (!empty($validated['date_to'])) {
            $query->where('earned_at', '<=', Carbon::parse($validated['date_to'])->endOfDay());
        }

        return $query;
    }

    private function buildCommissionSummary(array $validated): array
    {
        $scope = function () use ($validated) {
            $q = Commission::query();
            if (!empty($validated['agent_user_id'])) {
                $q->where('agent_user_id', (int) $validated['agent_user_id']);
            }
            if (!empty($validated['market_id'])) {
                $q->whereHas('client', fn ($cq) => $cq->where('platform_id', (int) $validated['market_id']));
            }
            if (!empty($validated['date_from'])) {
                $q->where('earned_at', '>=', $validated['date_from']);
            }
            if (!empty($validated['date_to'])) {
                $q->where('earned_at', '<=', Carbon::parse($validated['date_to'])->endOfDay());
            }
            return $q;
        };

        $startOfMonth = now()->startOfMonth();
        $thirtyDaysAgo = now()->subDays(30);

        $earnedByCurrency = (clone $scope())
            ->where('status', 'earned')
            ->groupBy('currency')
            ->selectRaw('currency, SUM(amount) as total, COUNT(*) as entries')
            ->get()
            ->map(fn ($r) => ['currency' => (string) $r->currency, 'total' => (float) $r->total, 'entries' => (int) $r->entries])
            ->values();

        $paidThisMonthByCurrency = (clone $scope())
            ->where('status', 'paid')
            ->where('paid_at', '>=', $startOfMonth)
            ->groupBy('currency')
            ->selectRaw('currency, SUM(amount) as total, COUNT(*) as entries')
            ->get()
            ->map(fn ($r) => ['currency' => (string) $r->currency, 'total' => (float) $r->total, 'entries' => (int) $r->entries])
            ->values();

        $thisMonthCount = (clone $scope())
            ->where('earned_at', '>=', $startOfMonth)
            ->count();

        $activeAgents = (clone $scope())
            ->whereIn('status', ['earned', 'paid'])
            ->where('earned_at', '>=', $thirtyDaysAgo)
            ->distinct('agent_user_id')
            ->count('agent_user_id');

        return [
            'earned_by_currency' => $earnedByCurrency,
            'paid_this_month_by_currency' => $paidThisMonthByCurrency,
            'this_month_count' => (int) $thisMonthCount,
            'active_agents_30d' => (int) $activeAgents,
            'funnel' => $this->buildAcquisitionFunnel($validated),
        ];
    }

    private function buildAcquisitionFunnel(array $validated): array
    {
        $fieldSalesIds = User::query()
            ->where('role', MarketAuthorizationService::ROLE_FIELD_SALES)
            ->pluck('id')
            ->all();

        if ($fieldSalesIds === []) {
            return ['acquired' => 0, 'trialed' => 0, 'converted' => 0];
        }

        $agentFilter = !empty($validated['agent_user_id']) ? (int) $validated['agent_user_id'] : null;
        if ($agentFilter && !in_array($agentFilter, $fieldSalesIds, true)) {
            return ['acquired' => 0, 'trialed' => 0, 'converted' => 0];
        }

        $base = Client::query()
            ->whereIn('created_by', $agentFilter ? [$agentFilter] : $fieldSalesIds);

        if (!empty($validated['market_id'])) {
            $base->where('platform_id', (int) $validated['market_id']);
        }
        if (!empty($validated['date_from'])) {
            $base->where('created_at', '>=', $validated['date_from']);
        }
        if (!empty($validated['date_to'])) {
            $base->where('created_at', '<=', Carbon::parse($validated['date_to'])->endOfDay());
        }

        $acquired = (clone $base)->count();

        $trialed = (clone $base)
            ->whereHas('deals', fn ($q) => $q->where('is_free_trial', true))
            ->count();

        $converted = (clone $base)
            ->whereHas('deals', fn ($q) => $q
                ->where('is_free_trial', false)
                ->whereIn('status', ['active', 'expired', 'renewed'])
                ->whereNotNull('activated_at'))
            ->count();

        return [
            'acquired' => (int) $acquired,
            'trialed' => (int) $trialed,
            'converted' => (int) $converted,
        ];
    }

    public function markPaid(Request $request)
    {
        $validated = $request->validate([
            'commission_ids' => 'required|array|min:1',
            'commission_ids.*' => 'integer|exists:commissions,id',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date',
            'external_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $payout = $this->commissionService->markPaid($validated['commission_ids'], [
                'period_start' => $validated['period_start'] ?? null,
                'period_end' => $validated['period_end'] ?? null,
                'external_reference' => $validated['external_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'paid_by' => (int) $request->user()->id,
                'paid_at' => now(),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $auditPlatformId = (int) Commission::query()
            ->whereIn('id', $validated['commission_ids'])
            ->join('clients', 'commissions.client_id', '=', 'clients.id')
            ->value('clients.platform_id');

        $this->auditService->fromRequest(
            $request,
            $auditPlatformId,
            CrmAuditAction::COMMISSION_MARK_PAID,
            'commission_payout',
            (int) $payout->id,
            null,
            [
                'commission_ids' => array_values(array_map('intval', $validated['commission_ids'])),
                'total_amount' => $payout->total_amount,
                'currency' => $payout->currency,
                'external_reference' => $payout->external_reference,
            ],
            'Commission payout marked paid'
        );

        return response()->json($payout->load(['commissions', 'agent:id,name,email']));
    }

    public function settings(Request $request)
    {
        return response()->json($this->settingsPayload());
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'platforms' => 'nullable|array',
            'platforms.*.id' => 'required_with:platforms|integer|exists:platforms,id',
            'platforms.*.field_activation_deposit_minor' => 'nullable|integer|min:0',
            'platforms.*.field_trial_duration_days' => 'nullable|integer|min:1|max:365',
            'platforms.*.field_trial_product_id' => 'nullable|integer|exists:products,id',
            'platforms.*.field_activation_commission_rate' => 'nullable|numeric|min:0|max:1',
            'platforms.*.field_renewal_commission_rate' => 'nullable|numeric|min:0|max:1',
            'platforms.*.field_renewal_commission_months' => 'nullable|integer|min:0|max:60',
            'globals' => 'nullable|array',
            'globals.deposit_poll_timeout_minutes' => 'nullable|integer|min:1|max:60',
            'globals.min_payout_amount_minor' => 'nullable|integer|min:0',
            'globals.clawback_policy_note' => 'nullable|string|max:2000',
        ]);

        foreach ($validated['platforms'] ?? [] as $platformPayload) {
            $platform = Platform::query()->findOrFail((int) $platformPayload['id']);
            $platform->fill(collect($platformPayload)->except('id')->all());
            $platform->save();
        }

        foreach (($validated['globals'] ?? []) as $key => $value) {
            $this->featureSettingsService->set('field.' . $key, $value, (int) $request->user()->id);
        }

        $settingsPlatformId = (int) (($validated['platforms'][0]['id'] ?? null) ?: Platform::query()->value('id'));

        $this->auditService->fromRequest(
            $request,
            $settingsPlatformId,
            CrmAuditAction::FIELD_SALES_SETTINGS_UPDATE,
            'field_sales_settings',
            $settingsPlatformId,
            null,
            $validated,
            'Field sales settings updated'
        );

        return response()->json($this->settingsPayload());
    }

    public function report(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'market_id' => 'nullable|integer|exists:platforms,id',
        ]);

        $from = !empty($validated['date_from']) ? \Illuminate\Support\Carbon::parse($validated['date_from'])->startOfDay() : now()->startOfMonth();
        $to = !empty($validated['date_to']) ? \Illuminate\Support\Carbon::parse($validated['date_to'])->endOfDay() : now()->endOfDay();

        $agents = User::query()
            ->where('role', MarketAuthorizationService::ROLE_FIELD_SALES)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $rows = $agents->map(function (User $agent) use ($from, $to, $validated) {
            // created_by is the durable attribution; signup_source can drift on WP re-sync.
            $clients = Client::query()
                ->where('created_by', (int) $agent->id)
                ->whereBetween('created_at', [$from, $to])
                ->when(!empty($validated['market_id']), fn ($query) => $query->where('platform_id', (int) $validated['market_id']));

            $clientIds = (clone $clients)->pluck('id');
            $trialCount = Deal::query()
                ->whereIn('client_id', $clientIds)
                ->where('is_free_trial', true)
                ->where('activated_by_field_agent', (int) $agent->id)
                ->count();
            $paidCount = Deal::query()
                ->whereIn('client_id', $clientIds)
                ->where('is_free_trial', false)
                ->where('activated_by_field_agent', (int) $agent->id)
                ->whereIn('status', ['active', 'expired', 'renewed'])
                ->count();
            $commissionTotal = Commission::query()
                ->where('agent_user_id', (int) $agent->id)
                ->whereBetween('earned_at', [$from, $to])
                ->sum('amount');

            return [
                'agent' => $agent,
                'clients_created' => (clone $clients)->count(),
                'trials_activated' => $trialCount,
                'paid_conversions' => $paidCount,
                'conversion_rate' => (clone $clients)->count() > 0 ? round($paidCount / max(1, (clone $clients)->count()), 4) : 0,
                'commission_total' => number_format((float) $commissionTotal, 2, '.', ''),
            ];
        });

        return response()->json([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'data' => $rows,
        ]);
    }

    private function authorizeFieldClient(Request $request, Client $client, bool $allowAdmin = false): void
    {
        $role = (string) ($request->user()?->role ?? '');
        if (!in_array($role, [MarketAuthorizationService::ROLE_FIELD_SALES, MarketAuthorizationService::ROLE_ADMIN], true)
            && !($allowAdmin && in_array($role, [MarketAuthorizationService::ROLE_SUB_ADMIN], true))) {
            abort(403, 'Only field sales users can perform this action.');
        }

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $client->platform_id,
            'You do not have access to this client market.'
        );
    }

    private function accessibleMarkets(User $user)
    {
        $ids = $this->marketAuthorizationService->resolveAccessiblePlatformIds($user);

        return Platform::query()
            ->when(is_array($ids), fn ($query) => $query->whereIn('id', $ids ?: [-1]))
            ->orderBy('name')
            ->get(['id', 'name', 'country', 'currency_code', 'field_activation_deposit_minor']);
    }

    private function settingsPayload(): array
    {
        return [
            'platforms' => Platform::query()
                ->with(['product:id,name,display_name'])
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'country',
                    'currency_code',
                    'field_activation_deposit_minor',
                    'field_trial_duration_days',
                    'field_trial_product_id',
                    'field_activation_commission_rate',
                    'field_renewal_commission_rate',
                    'field_renewal_commission_months',
                ]),
            'products' => Product::query()
                ->where('is_active', true)
                ->orderBy('platform_id')
                ->orderBy('display_name')
                ->get(['id', 'platform_id', 'name', 'display_name']),
            'agents' => User::query()
                ->where('role', MarketAuthorizationService::ROLE_FIELD_SALES)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'status', 'assigned_market_ids']),
            'globals' => [
                'deposit_poll_timeout_minutes' => $this->featureSettingsService->integer('field.deposit_poll_timeout_minutes', 10),
                'min_payout_amount_minor' => $this->featureSettingsService->integer('field.min_payout_amount_minor', 0),
                'clawback_policy_note' => (string) $this->featureSettingsService->get('field.clawback_policy_note', ''),
            ],
        ];
    }
}
