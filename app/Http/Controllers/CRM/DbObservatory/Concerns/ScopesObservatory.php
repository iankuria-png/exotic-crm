<?php

namespace App\Http\Controllers\CRM\DbObservatory\Concerns;

use App\Models\DbScanMarketRun;
use App\Models\DbScanPass;
use App\Models\Platform;
use App\Services\MarketAuthorizationService;
use App\Support\DbScannerPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Every Observatory query goes through MarketAuthorizationService scope.
 * Records outside a sub-admin's markets are reported as 404, and grouped
 * totals include only allowed markets.
 */
trait ScopesObservatory
{
    protected function ensureView(Request $request): void
    {
        abort_unless(DbScannerPermissions::canView($request->user()), 403, 'The Database Observatory is limited to administrators and sub-administrators.');
    }

    protected function ensureOperate(Request $request): void
    {
        abort_unless(DbScannerPermissions::canOperate($request->user()), 403, 'Only administrators can run, control or triage scans.');
    }

    protected function ensureConfigure(Request $request): void
    {
        abort_unless(DbScannerPermissions::canConfigure($request->user()), 403, 'Only administrators can change scanner configuration.');
    }

    /**
     * @return array<int, int>|null null means every market (admin)
     */
    protected function scopeIds(Request $request): ?array
    {
        $ids = app(MarketAuthorizationService::class)->resolveAccessiblePlatformIds($request->user());

        return is_array($ids) ? array_values(array_map('intval', $ids)) : null;
    }

    protected function scoped(Builder $query, Request $request, string $column = 'platform_id'): Builder
    {
        $ids = $this->scopeIds($request);
        if (is_array($ids)) {
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $ids);
        }

        return $query;
    }

    protected function inScope(Request $request, ?int $platformId): bool
    {
        $ids = $this->scopeIds($request);

        return ! is_array($ids) || in_array((int) $platformId, $ids, true);
    }

    protected function abortUnlessInScope(Request $request, ?int $platformId): void
    {
        abort_unless($this->inScope($request, $platformId), 404);
    }

    protected function scopedRun(Request $request, int $runId): DbScanMarketRun
    {
        $run = DbScanMarketRun::query()->findOrFail($runId);
        $this->abortUnlessInScope($request, (int) $run->platform_id);

        return $run;
    }

    /**
     * A pass is visible when any of its markets is; a sub-admin sees only
     * their markets' runs inside it.
     */
    protected function scopedPass(Request $request, int $passId): DbScanPass
    {
        $pass = DbScanPass::query()->findOrFail($passId);
        $platforms = array_map('intval', (array) ($pass->scope['platform_ids'] ?? []));
        $ids = $this->scopeIds($request);
        abort_if(is_array($ids) && array_intersect($platforms, $ids) === [], 404);

        return $pass;
    }

    /**
     * @return array<int, string>
     */
    protected function marketNames(?array $ids = null): array
    {
        return Platform::query()
            ->when(is_array($ids), fn ($q) => $q->whereIn('id', $ids))
            ->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)
            ->all();
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }

    protected function paginated($paginator, array $data): array
    {
        return [
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }
}
