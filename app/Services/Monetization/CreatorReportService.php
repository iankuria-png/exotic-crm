<?php

namespace App\Services\Monetization;

use App\Models\Client;
use App\Models\ClientMonetizationPass;
use App\Models\VisitorContentPurchase;
use App\Services\ReportingCurrencyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creator directory and selling-pass ledger for the Monetize workspace.
 *
 * Money stays in each creator's native currency. Cross-market money sorts use the cached
 * reporting-currency rate per market; a market without a rate sorts last instead of being
 * compared at face value.
 */
class CreatorReportService
{
    public const STATES = ['active', 'expiring', 'unclaimed', 'held', 'ended'];

    public const CREATOR_SORTS = ['name', 'expires_at', 'pass_paid', 'sales_gross', 'sales_count', 'last_pass_at'];

    private array $rates = [];

    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrency,
        private readonly StatsService $stats,
        private readonly PassService $passService
    ) {}

    /**
     * @param  array{platform_ids:?array, client_id:?int, include_tests:bool, from:?string, to:?string}  $scope
     * @param  array{search:?string, state:?string, pass_type:?string, sort:?string, direction:?string, reporting_currency:?string, page:int, per_page:int}  $options
     */
    public function creators(array $scope, array $options, bool $withDetails = true): array
    {
        $target = $this->reportingCurrency->resolveTargetCurrency($options['reporting_currency'] ?? null);
        $rows = $this->creatorRows($scope, $target);

        $counts = ['all' => $rows->count()] + collect(self::STATES)->mapWithKeys(fn ($state) => [$state => $rows->where('state', $state)->count()])->all();

        $term = mb_strtolower(trim((string) ($options['search'] ?? '')));
        $filtered = $rows
            ->when($term !== '', fn ($c) => $c->filter(fn ($row) => str_contains(mb_strtolower($row['name']), $term) || (string) $row['id'] === $term))
            ->when(in_array($options['state'] ?? null, self::STATES, true), fn ($c) => $c->where('state', $options['state']))
            ->when(($options['pass_type'] ?? null) === 'paid', fn ($c) => $c->filter(fn ($row) => $row['paid_passes'] > 0))
            ->when(($options['pass_type'] ?? null) === 'free', fn ($c) => $c->filter(fn ($row) => $row['paid_passes'] === 0));

        $sorted = $this->sortCreators($filtered, $options['sort'] ?? 'expires_at', $options['direction'] ?? 'desc');
        $perPage = (int) $options['per_page'];
        $page = max(1, (int) $options['page']);
        $pageRows = $sorted->forPage($page, $perPage)->values();

        $clients = $withDetails ? Client::with('platform')->whereIn('id', $pageRows->pluck('id'))->get()->keyBy('id') : collect();
        $pageRows = $pageRows->map(function (array $row) use ($clients) {
            $client = $clients[$row['id']] ?? null;

            return $row + [
                'stats' => $client ? $this->stats->owner($client) : null,
                'pass' => $client ? $this->passService->current($client) : null,
            ];
        });

        return [
            'data' => $pageRows->all(),
            'total' => $sorted->count(),
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($sorted->count() / $perPage)),
            'counts' => $counts,
            'reporting_currency' => $target,
        ];
    }

    public function creatorExportRows(array $scope, array $options): Collection
    {
        $result = $this->creators($scope, ['page' => 1, 'per_page' => 100000] + $options, false);

        return collect($result['data']);
    }

    /**
     * Every selling pass in scope, newest first by default, with who holds it and how it was paid.
     */
    public function passQuery(array $scope, array $options): Builder
    {
        $query = $this->passScope($scope)
            ->with('client:id,name')
            ->leftJoin('payments', 'payments.id', '=', 'client_monetization_passes.payment_id')
            ->leftJoin('platforms', 'platforms.id', '=', 'client_monetization_passes.platform_id')
            ->select('client_monetization_passes.*', 'payments.transaction_reference as payment_reference', 'payments.status as payment_status', 'platforms.name as platform_name');

        match ($options['pass_type'] ?? 'paid') {
            'paid' => $query->where('client_monetization_passes.paid_amount', '>', 0),
            'free' => $query->where('client_monetization_passes.paid_amount', '=', 0),
            default => null,
        };
        if (! empty($options['status'])) {
            $query->where('client_monetization_passes.status', $options['status']);
        }
        if ($term = trim((string) ($options['search'] ?? ''))) {
            $query->where(function ($q) use ($term) {
                $q->whereHas('client', fn ($client) => $client->where('name', 'like', "%{$term}%"))
                    ->orWhere('payments.transaction_reference', 'like', "%{$term}%");
                if (ctype_digit(ltrim($term, '#'))) {
                    $q->orWhere('client_monetization_passes.id', (int) ltrim($term, '#'));
                }
            });
        }

        $direction = ($options['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $column = match ($options['sort'] ?? 'created_at') {
            'expires_at' => 'client_monetization_passes.expires_at',
            'starts_at' => 'client_monetization_passes.starts_at',
            default => 'client_monetization_passes.created_at',
        };

        return $query->orderBy($column, $direction)->orderBy('client_monetization_passes.id', $direction);
    }

    public function passes(array $scope, array $options): array
    {
        $query = $this->passQuery($scope, $options);
        $totals = (clone $query)->reorder()->toBase()
            ->select(DB::raw('client_monetization_passes.currency as currency, COUNT(*) as passes, SUM(client_monetization_passes.paid_amount) as paid, SUM(client_monetization_passes.list_amount) as list_value, SUM(client_monetization_passes.subsidy_amount) as subsidy, COUNT(DISTINCT client_monetization_passes.client_id) as creators'))
            ->groupBy('client_monetization_passes.currency')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'passes' => (int) $row->passes, 'creators' => (int) $row->creators, 'paid' => (float) $row->paid, 'list_value' => (float) $row->list_value, 'subsidy' => (float) $row->subsidy]);
        $page = $query->paginate((int) $options['per_page'], ['*'], 'page', (int) $options['page']);
        $page->getCollection()->transform(fn (ClientMonetizationPass $pass) => $this->passRow($pass));

        return ['passes' => $page, 'totals' => $totals->values()];
    }

    public function passRow(ClientMonetizationPass $pass): array
    {
        return [
            'id' => $pass->id,
            'client' => $pass->client ? ['id' => $pass->client->id, 'name' => $pass->client->name] : null,
            'platform_id' => $pass->platform_id,
            'platform_name' => $pass->platform_name,
            'status' => $pass->status,
            'type' => (float) $pass->paid_amount > 0 ? 'paid' : 'free',
            'grant_source' => $pass->grant_source ?? 'wallet',
            'duration_key' => $pass->duration_key,
            'duration_days' => $pass->duration_days,
            'currency' => $pass->currency,
            'list_amount' => (float) $pass->list_amount,
            'subsidy_amount' => (float) $pass->subsidy_amount,
            'paid_amount' => (float) $pass->paid_amount,
            'payment_reference' => $pass->payment_reference,
            'payment_status' => $pass->payment_status,
            'hold_reason' => $pass->hold_reason,
            'is_sandbox' => (bool) $pass->is_sandbox,
            'created_at' => $pass->created_at?->toIso8601String(),
            'starts_at' => $pass->starts_at?->toIso8601String(),
            'expires_at' => $pass->expires_at?->toIso8601String(),
            'claimed_at' => $pass->claimed_at?->toIso8601String(),
        ];
    }

    private function creatorRows(array $scope, string $target): Collection
    {
        $now = now();
        $windowPasses = $this->passScope($scope, true)
            ->selectRaw('client_id, COUNT(*) as passes, SUM(CASE WHEN paid_amount > 0 THEN 1 ELSE 0 END) as paid_passes, SUM(paid_amount) as pass_paid, MAX(created_at) as last_pass_at')
            ->whereNotNull('client_id')
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');
        if ($windowPasses->isEmpty()) {
            return collect();
        }
        $ids = $windowPasses->keys()->all();

        $current = $this->passScope($scope, false)
            ->whereIn('client_id', $ids)
            ->where('expires_at', '>', $now)
            ->whereIn('status', ['active', 'queued', 'held', 'granted'])
            ->get(['client_id', 'status', 'expires_at', 'paid_amount'])
            ->groupBy('client_id');

        $sales = VisitorContentPurchase::query()
            ->whereIn('client_id', $ids)
            ->where('status', 'active')
            ->when(! $scope['include_tests'], fn ($q) => $q->where('is_sandbox', false))
            ->when($scope['from'], fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($scope['to'], fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->selectRaw('client_id, COUNT(*) as sales_count, SUM(gross_amount) as sales_gross')
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');

        $clients = Client::query()
            ->leftJoin('platforms', 'platforms.id', '=', 'clients.platform_id')
            ->whereIn('clients.id', $ids)
            ->get(['clients.id', 'clients.name', 'clients.platform_id', 'platforms.name as platform_name', 'platforms.country as platform_country', 'platforms.currency_code as platform_currency']);

        return $clients->map(function ($client) use ($windowPasses, $current, $sales, $target, $now) {
            $window = $windowPasses[$client->id];
            $live = $current[$client->id] ?? collect();
            $selling = $live->whereIn('status', ['active', 'queued'])->sortByDesc('expires_at')->first();
            $held = $live->firstWhere('status', 'held');
            $state = match (true) {
                (bool) $held => 'held',
                (bool) $selling => $selling->expires_at->lte($now->copy()->addDays(7)) ? 'expiring' : 'active',
                $live->contains('status', 'granted') => 'unclaimed',
                default => 'ended',
            };
            $currency = strtoupper((string) ($client->platform_currency ?: 'KES'));
            $rate = $this->rate($client, $currency, $target);
            $passPaid = round((float) $window->pass_paid, 2);
            $salesGross = round((float) ($sales[$client->id]->sales_gross ?? 0), 2);

            return [
                'id' => $client->id,
                'name' => (string) $client->name,
                'platform_id' => $client->platform_id,
                'platform_name' => $client->platform_name,
                'currency' => $currency,
                'state' => $state,
                'expires_at' => ($selling ?? $held)?->expires_at?->toIso8601String(),
                'current_type' => $selling ? ((float) $selling->paid_amount > 0 ? 'paid' : 'free') : null,
                'passes' => (int) $window->passes,
                'paid_passes' => (int) $window->paid_passes,
                'pass_paid' => $passPaid,
                'pass_paid_converted' => $rate === null ? null : round($passPaid * $rate, 2),
                'sales_count' => (int) ($sales[$client->id]->sales_count ?? 0),
                'sales_gross' => $salesGross,
                'sales_gross_converted' => $rate === null ? null : round($salesGross * $rate, 2),
                'last_pass_at' => $window->last_pass_at ? (string) \Illuminate\Support\Carbon::parse($window->last_pass_at)->toIso8601String() : null,
            ];
        })->values();
    }

    private function sortCreators(Collection $rows, string $sort, string $direction): Collection
    {
        $sort = in_array($sort, self::CREATOR_SORTS, true) ? $sort : 'expires_at';
        $descending = $direction !== 'asc';
        $key = match ($sort) {
            'pass_paid' => 'pass_paid_converted',
            'sales_gross' => 'sales_gross_converted',
            default => $sort,
        };

        // Rows without a value (no current pass, no FX rate) always sort last, in either direction.
        [$missing, $present] = $rows->partition(fn (array $row) => $row[$key] === null);
        $present = $present->sort(function (array $a, array $b) use ($key, $descending) {
            $cmp = $key === 'name'
                ? strcasecmp($a['name'], $b['name'])
                : $a[$key] <=> $b[$key];
            $cmp = $descending ? -$cmp : $cmp;

            return $cmp !== 0 ? $cmp : strcasecmp($a['name'], $b['name']);
        });

        return $present->concat($missing->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE))->values();
    }

    /** Cached reporting rate for one market's currency, or null when none is available. */
    private function rate($client, string $currency, string $target): ?float
    {
        $key = $client->platform_id.'|'.$currency.'|'.$target;
        if (! array_key_exists($key, $this->rates)) {
            $normalized = $this->reportingCurrency->normalizeEventRows([[
                'event_date' => now()->toDateString(),
                'platform_id' => $client->platform_id,
                'platform_name' => $client->platform_name,
                'platform_country' => $client->platform_country,
                'currency' => $currency,
                'amount' => 1,
            ]], $target, false);
            $this->rates[$key] = $normalized['normalized_total'] === null ? null : (float) $normalized['normalized_total'];
        }

        return $this->rates[$key];
    }

    private function passScope(array $scope, bool $window = true): Builder
    {
        $query = ClientMonetizationPass::query();
        $ids = $scope['platform_ids'];
        if (is_array($ids)) {
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('client_monetization_passes.platform_id', $ids);
        }

        return $query
            ->when($scope['client_id'], fn ($q, $id) => $q->where('client_monetization_passes.client_id', $id))
            ->when(! $scope['include_tests'], fn ($q) => $q->where('client_monetization_passes.is_sandbox', false))
            ->when($window && $scope['from'], fn ($q) => $q->whereDate('client_monetization_passes.created_at', '>=', $scope['from']))
            ->when($window && $scope['to'], fn ($q) => $q->whereDate('client_monetization_passes.created_at', '<=', $scope['to']));
    }
}
