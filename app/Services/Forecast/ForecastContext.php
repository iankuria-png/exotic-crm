<?php

namespace App\Services\Forecast;

use App\Models\Platform;
use App\Services\MarketAuthorizationService;
use App\Services\ReportingCurrencyService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ForecastContext
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $platformId,
        public readonly int|array|null $platformScope,
        public readonly ?array $accessiblePlatformIds,
        public readonly string $currency,
        public readonly int $days,
        public readonly string $mode = 'project',
        public readonly int $horizonDays = 90,
    ) {}

    public static function fromRequest(
        Request $request,
        MarketAuthorizationService $marketAuthorizationService,
        ReportingCurrencyService $reportingCurrencyService
    ): self {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'currency' => 'nullable|string|min:3|max:8',
            'reporting_currency' => 'nullable|string|min:3|max:8',
            'mode' => 'nullable|in:replay,project,target',
            'horizon_days' => 'nullable|integer|min:1|max:366',
        ]);

        $from = Carbon::parse((string) $validated['from'])->startOfDay();
        $to = Carbon::parse((string) $validated['to'])->endOfDay();
        $days = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $platformId = isset($validated['platform_id']) ? (int) $validated['platform_id'] : null;

        $marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this forecast market.'
        );

        $accessible = $marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        $scope = $platformId ?: $accessible;
        $currency = $reportingCurrencyService->resolveTargetCurrency(
            $validated['currency'] ?? $validated['reporting_currency'] ?? null
        );

        return new self(
            from: $from,
            to: $to,
            platformId: $platformId,
            platformScope: $scope,
            accessiblePlatformIds: $accessible,
            currency: $currency,
            days: $days,
            mode: (string) ($validated['mode'] ?? 'project'),
            horizonDays: (int) ($validated['horizon_days'] ?? 90),
        );
    }

    public function platformIdsForServices(): ?array
    {
        if (is_int($this->platformScope)) {
            return [$this->platformScope];
        }

        return $this->platformScope;
    }

    public function cacheKey(): string
    {
        $scope = $this->platformScope === null
            ? 'all'
            : implode('-', is_array($this->platformScope) ? $this->platformScope : [$this->platformScope]);

        return 'forecast:baseline:'.sha1(implode('|', [
            $this->from->toDateTimeString(),
            $this->to->toDateTimeString(),
            $scope,
            $this->currency,
        ]));
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'platform_id' => $this->platformId,
            'currency' => $this->currency,
            'days' => $this->days,
            'mode' => $this->mode,
            'horizon_days' => $this->horizonDays,
            'accessible_platform_ids' => $this->accessiblePlatformIds,
            'selected_market' => $this->platformId ? Platform::query()->find($this->platformId)?->only(['id', 'name', 'country']) : null,
        ];
    }
}
