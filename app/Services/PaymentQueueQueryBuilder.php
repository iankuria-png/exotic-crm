<?php

namespace App\Services;

use App\Models\BillingProviderTransaction;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PaymentQueueQueryBuilder
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function validationRules(): array
    {
        return [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
            'search' => 'nullable|string|max:120',
            'status' => 'nullable|string|max:60',
            'matched' => 'nullable|in:matched,unmatched',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'source' => 'nullable|string|max:80',
            'environment' => 'nullable|in:production,sandbox',
            'test_visibility' => 'nullable|in:hide,include,only',
            'has_discount' => 'nullable|in:0,1',
            'match_confidence' => 'nullable|string|max:40',
            'review_state' => 'nullable|string|max:40',
            'resolution_code' => 'nullable|in:reversed,invalid_reference',
            'customer_mix_segment' => 'nullable|in:new_active,existing_active,unattributed,other_matched',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'currency_mode' => 'nullable|in:native,flat',
            'reporting_currency' => 'nullable|string|min:3|max:8',
        ];
    }

    public function resolveContext(Request $request, array $validated): array
    {
        $environmentFilter = strtolower(trim((string) ($validated['environment'] ?? $request->input('environment', ''))));
        $testVisibility = strtolower(trim((string) ($validated['test_visibility'] ?? 'hide')));
        $testVisibility = in_array($testVisibility, ['hide', 'include', 'only'], true) ? $testVisibility : 'hide';
        $canViewTests = $this->canViewTests($request);

        if (($testVisibility !== 'hide' || $environmentFilter === 'sandbox') && !$canViewTests) {
            abort(403, 'Only admin users can view test payments.');
        }

        $baselineCutoff = $this->resolveBaselineCutoff();
        $from = !empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : ($baselineCutoff ? $baselineCutoff->copy() : null);
        $to = !empty($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfDay();

        return [
            'environment_filter' => $environmentFilter,
            'test_visibility' => $testVisibility,
            'can_view_tests' => $canViewTests,
            'baseline_cutoff' => $baselineCutoff,
            'from' => $from,
            'to' => $to,
            'successful_statuses' => $this->successfulPaymentStatuses(),
        ];
    }

    public function buildRowsQuery(
        Request $request,
        array $validated,
        array $with = [],
        bool $includeProviderLookups = false,
        ?array $context = null
    ): Builder {
        $context ??= $this->resolveContext($request, $validated);

        $query = $this->buildBaseQuery($request, $with, $includeProviderLookups);
        $this->applyPaymentWorkspaceVisibility(
            $query,
            $context['test_visibility'],
            $context['environment_filter']
        );

        $statusFilter = trim((string) ($validated['status'] ?? $request->input('status', '')));
        $successfulStatuses = $context['successful_statuses'];

        if ($statusFilter !== '') {
            if ($statusFilter === 'awaiting_payment') {
                $query->whereIn('status', ['initiated', 'pending']);
            } elseif ($statusFilter === 'completed') {
                $query->whereIn('status', $successfulStatuses);
            } elseif ($statusFilter === 'recovery_queue') {
                $query->where(function (Builder $builder) use ($successfulStatuses) {
                    $builder->whereIn('status', ['initiated', 'pending', 'failed'])
                        ->orWhere(function (Builder $unmatchedCompleted) use ($successfulStatuses) {
                            $unmatchedCompleted->whereIn('status', $successfulStatuses)
                                ->whereNull('client_id');
                        });
                });
            } elseif ($statusFilter === 'reversed') {
                $query->where(function (Builder $builder) use ($successfulStatuses) {
                    $builder->where('status', 'reversed')
                        ->orWhere(function (Builder $inner) use ($successfulStatuses) {
                            $inner->whereIn('status', $successfulStatuses)
                                ->where('resolution_code', Payment::RESOLUTION_REVERSED);
                        });
                });
            } else {
                $query->where('status', $statusFilter);
            }
        }

        $matchedFilter = trim((string) ($validated['matched'] ?? $request->input('matched', '')));
        if ($matchedFilter !== '') {
            if ($matchedFilter === 'unmatched') {
                $query->whereNull('client_id');
            } elseif ($matchedFilter === 'matched') {
                $query->whereNotNull('client_id');
            }
        }

        $sourceFilter = trim((string) ($validated['source'] ?? $request->input('source', '')));
        if ($sourceFilter !== '') {
            $query->where('source', $sourceFilter);
        }

        $hasDiscountFilter = trim((string) ($validated['has_discount'] ?? ''));
        if ($hasDiscountFilter !== '') {
            if ($hasDiscountFilter === '1') {
                $query->whereHas('deal', function (Builder $dealQuery) {
                    $dealQuery->whereNotNull('discount_percentage');
                });
            } else {
                $query->where(function (Builder $builder) {
                    $builder->whereNull('deal_id')
                        ->orWhereHas('deal', function (Builder $dealQuery) {
                            $dealQuery->whereNull('discount_percentage');
                        });
                });
            }
        }

        $confidenceFilter = trim((string) ($validated['match_confidence'] ?? $request->input('match_confidence', '')));
        if ($confidenceFilter !== '') {
            if (in_array($confidenceFilter, ['high', 'medium', 'low'], true)) {
                $query->where('reconciliation_confidence', $confidenceFilter);
            } else {
                $query->where('match_confidence', $confidenceFilter);
            }
        }

        $reviewState = trim((string) ($validated['review_state'] ?? $request->input('review_state', '')));
        if ($reviewState !== '') {
            $query->where('reconciliation_state', $reviewState);
        }

        $resolutionCode = trim((string) ($validated['resolution_code'] ?? ''));
        if ($resolutionCode !== '') {
            $query->where('resolution_code', $resolutionCode);
        }

        if ($context['from']) {
            $query->where('created_at', '>=', $context['from']);
        }
        $query->where('created_at', '<=', $context['to']);

        $customerMixSegment = trim((string) ($validated['customer_mix_segment'] ?? ''));
        if ($customerMixSegment !== '') {
            $this->applyCustomerMixSegmentFilter(
                $query,
                $customerMixSegment,
                $context['from'],
                $context['to'],
                $successfulStatuses
            );
        }

        return $query;
    }

    public function buildStatsQuery(
        Request $request,
        array $validated,
        array $with = [],
        bool $includeProviderLookups = false,
        ?array $context = null
    ): Builder {
        $context ??= $this->resolveContext($request, $validated);
        $statsVisibility = $context['test_visibility'] === 'include' && $context['environment_filter'] === ''
            ? 'hide'
            : $context['test_visibility'];

        $query = $this->buildBaseQuery($request, $with, $includeProviderLookups);
        $this->applyPaymentWorkspaceVisibility($query, $statsVisibility, $context['environment_filter']);

        if ($context['from']) {
            $query->where('created_at', '>=', $context['from']);
        }
        $query->where('created_at', '<=', $context['to']);

        return $query;
    }

    public function applyPaymentWorkspaceVisibility(Builder $query, string $testVisibility, string $environmentFilter): Builder
    {
        $query->workspaceVisible($testVisibility);

        if ($environmentFilter === 'production') {
            $query->where(function (Builder $builder) {
                $builder->whereNull('provider_environment')
                    ->orWhereRaw('LOWER(provider_environment) = ?', ['production']);
            });
        } elseif ($environmentFilter === 'sandbox') {
            $query->sandboxTest();
        }

        return $query;
    }

    public function successfulPaymentStatuses(): array
    {
        return ['completed', 'expired'];
    }

    public function canViewTests(Request $request): bool
    {
        return $this->marketAuthorizationService->hasRole($request->user(), [MarketAuthorizationService::ROLE_ADMIN]);
    }

    public function resolveBaselineCutoff(): ?Carbon
    {
        try {
            $value = \App\Models\IntegrationSetting::query()
                ->where('key', 'data_baseline_mode')
                ->value('value');
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        $mode = $value['mode'] ?? 'fresh_start';
        $cutoffDate = $value['cutoff_date'] ?? null;

        if ($mode !== 'fresh_start' || !$cutoffDate) {
            return null;
        }

        try {
            return Carbon::parse($cutoffDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildBaseQuery(Request $request, array $with, bool $includeProviderLookups): Builder
    {
        $latestProviderTransactionOrder = 'COALESCE(last_status_at, created_at) DESC, id DESC';

        $query = Payment::query();
        if ($with !== []) {
            $query->with($with);
        }

        $query->select('payments.*');

        if ($includeProviderLookups) {
            $query->selectSub(
                BillingProviderTransaction::query()
                    ->selectRaw("CASE WHEN provider_type_key = 'pawapay' THEN provider_reported_transaction_id ELSE provider_transaction_id END")
                    ->whereColumn('payment_id', 'payments.id')
                    ->orderByRaw($latestProviderTransactionOrder)
                    ->limit(1),
                'provider_transaction_id'
            )->selectSub(
                BillingProviderTransaction::query()
                    ->select('provider_reported_phone')
                    ->whereColumn('payment_id', 'payments.id')
                    ->orderByRaw($latestProviderTransactionOrder)
                    ->limit(1),
                'provider_reported_phone'
            );
        }

        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if ($request->filled('platform_id')) {
            $query->where('platform_id', (int) $request->input('platform_id'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('phone', 'like', "%{$search}%")
                    ->orWhere('transaction_reference', 'like', "%{$search}%")
                    ->orWhereHas('providerTransactions', function (Builder $providerTransactions) use ($search) {
                        $providerTransactions->where('provider_reported_transaction_id', 'like', "%{$search}%")
                            ->orWhere('provider_transaction_id', 'like', "%{$search}%")
                            ->orWhere('provider_reported_phone', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    private function applyCustomerMixSegmentFilter(
        Builder $query,
        string $segment,
        ?Carbon $from,
        Carbon $to,
        array $successfulStatuses
    ): Builder {
        $query
            ->whereIn('payments.status', $successfulStatuses)
            ->whereNull('payments.resolution_code')
            ->where(function (Builder $builder) {
                $builder->whereNull('payments.reconciliation_state')
                    ->orWhere('payments.reconciliation_state', '!=', 'manual_review');
            });

        return $this->applyCustomerMixBucketScope($query, $segment, $from, $to);
    }

    private function applyCustomerMixBucketScope(Builder $query, string $bucketKey, ?Carbon $from, Carbon $to): Builder
    {
        return match ($bucketKey) {
            'new_active' => $query->whereHas('client', function (Builder $clientQuery) use ($from, $to) {
                $clientQuery->active();

                if ($from) {
                    $clientQuery->where('created_at', '>=', $from);
                }

                $clientQuery->where('created_at', '<=', $to);
            }),
            'existing_active' => $from
                ? $query->whereHas('client', function (Builder $clientQuery) use ($from) {
                    $clientQuery->active()
                        ->where('created_at', '<', $from);
                })
                : $query->whereRaw('1 = 0'),
            'unattributed' => $query->whereNull('payments.client_id'),
            'other_matched' => $query
                ->whereNotNull('payments.client_id')
                ->where(function (Builder $builder) use ($to) {
                    $builder->whereDoesntHave('client')
                        ->orWhereHas('client', function (Builder $clientQuery) use ($to) {
                            $clientQuery->where(function (Builder $nonActiveOrOutOfPeriod) use ($to) {
                                $nonActiveOrOutOfPeriod
                                    ->whereNull('profile_status')
                                    ->orWhere('profile_status', '!=', 'publish')
                                    ->orWhere('needs_payment', true)
                                    ->orWhere('notactive', true)
                                    ->orWhere('created_at', '>', $to);
                            });
                        });
                }),
            default => $query,
        };
    }
}
