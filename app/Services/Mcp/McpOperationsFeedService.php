<?php

namespace App\Services\Mcp;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\Collections\CollectionAccessService;
use App\Services\Collections\CollectionWalletBalanceService;
use App\Services\MarketAuthorizationService;
use App\Services\PaymentPresenter;
use App\Services\ReportingCurrencyService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only operational feeds for MCP: receiving-wallet balances and the latest
 * payments and subscriptions, reusing the same sources as the CRM screens.
 */
class McpOperationsFeedService
{
    private const DISPLAY_TZ = 'Africa/Nairobi';

    public function __construct(
        private readonly MarketAuthorizationService $markets,
        private readonly PseudonymService $pseudonyms,
        private readonly McpStaffAliasService $aliases,
        private readonly PaymentPresenter $presenter,
        private readonly ReportingCurrencyService $currencies,
    ) {}

    /** Balances exactly as each receiving wallet's latest credit SMS stated them. */
    public function walletBalances(array $arguments, McpAuthorizationContext $auth): array
    {
        if (! $auth->roleIs(['admin', 'sub_admin', 'sales'])) {
            throw McpProtocolException::rpc(-32001, 'Receiving-wallet balances are available to administrators, sub-administrators and sales staff.', 'wallet_denied', 403);
        }
        $accountIds = app(CollectionAccessService::class)->accountIds($auth->user);
        $platformId = $this->platform($arguments, $auth->user);
        if ($platformId && $accountIds) {
            $accountIds = \Illuminate\Support\Facades\DB::table('collection_account_platforms')
                ->whereIn('account_id', $accountIds)->where('platform_id', $platformId)->pluck('account_id')->unique()->values()->all();
        }
        $markets = \Illuminate\Support\Facades\DB::table('collection_account_platforms')
            ->join('platforms', 'platforms.id', '=', 'collection_account_platforms.platform_id')
            ->whereIn('collection_account_platforms.account_id', $accountIds ?: [0])
            ->get(['collection_account_platforms.account_id', 'platforms.name'])
            ->groupBy('account_id')->map(fn ($rows) => $rows->pluck('name')->unique()->sort()->values()->all());

        $rows = collect(app(CollectionWalletBalanceService::class)->latest($accountIds))->map(function (array $row) use ($markets): array {
            $at = $row['at'] ? \Carbon\CarbonImmutable::parse($row['at']) : null;

            return [
                'wallet' => $row['label'],
                'markets' => $markets[$row['account_id']] ?? [],
                'currency' => $row['currency'],
                'balance' => round($row['minor'] / 100, 2),
                'balance_display' => $row['currency'].' '.number_format($row['minor'] / 100, 2),
                'reported_at' => $at?->toIso8601String(),
                'reported_at_eat' => $at ? $this->eat($at) : null,
                'minutes_since_report' => $at ? (int) max(0, $at->diffInMinutes(now())) : null,
                'source' => 'Stated in the latest credit SMS received on the wallet\'s receiving phone',
            ];
        })->values()->all();

        return [
            'definition' => 'The balance the provider\'s own credit SMS reported right after the most recent incoming payment. It is never computed.',
            'caveats' => [
                'Withdrawals, transfers or charges made after that SMS are not reflected until the next credit SMS arrives.',
                'Wallets without a recent credit SMS that states a balance are omitted.',
            ],
            'rows' => $rows,
        ];
    }

    /** Latest completed cash payments, newest first, as on the dashboard's "Last payments" list. */
    public function recentPayments(array $arguments, McpAuthorizationContext $auth): array
    {
        $limit = $this->limit($arguments);
        $identified = $this->identified($arguments, $auth, 'exotic_recent_payments');
        $query = Payment::query()->businessVisible()->excludingWalletTopups()->where('status', 'completed');
        $this->scopeMarkets($query, $arguments, $auth);
        $channel = (string) ($arguments['channel'] ?? 'all');
        $payments = $query->with([
            'client:id,name', 'platform:id,name,country,currency_code', 'product:id,name',
            'deal:id,assigned_to,plan_type', 'routingDecisions', 'providerTransactions', 'manualSubmission',
        ])
            ->orderByRaw('COALESCE(completed_at, created_at) desc')->orderByDesc('id')
            // Channel is derived per payment, so read a bounded window and filter in memory.
            ->limit($channel === 'all' ? $limit : min(500, $limit * 10))->get();

        $rows = $payments->map(function (Payment $payment) use ($identified): array {
            $at = $payment->completed_at ?: $payment->created_at;
            $currency = strtoupper((string) ($payment->currency ?: $payment->platform?->currency_code ?: ''));
            $channel = $this->presenter->paymentChannel($payment);
            $method = $this->presenter->paymentMethod($payment);

            return array_filter([
                'paid_at' => $at?->toIso8601String(),
                'paid_at_eat' => $at ? $this->eat($at) : null,
                'profile_name' => $identified ? $payment->client?->name : null,
                'client_handle' => ! $identified && $payment->client_id ? $this->pseudonyms->handle('client', (int) $payment->client_id) : null,
                'market' => $payment->platform?->name,
                'country' => $payment->platform?->country,
                'product' => $payment->product?->name,
                'plan_type' => $payment->deal?->plan_type,
                'amount' => round((float) $payment->amount, 2),
                'currency' => $currency,
                'amount_usd' => $this->usd($payment, $currency, $at),
                'channel' => $channel['key'] ?? null,
                'channel_label' => $channel['label'] ?? null,
                'method' => trim(($method['label'] ?? '').(! empty($method['subtitle']) ? ' · '.$method['subtitle'] : '')) ?: null,
                'agent_alias' => $this->agentAlias($payment->deal?->assigned_to ?: $payment->confirmed_by),
            ], fn ($value) => $value !== null);
        });
        if ($channel !== 'all') {
            $rows = $rows->where('channel', $channel);
        }

        return [
            'definition' => 'Completed customer payments (wallet top-ups excluded), newest first, by completion time.',
            'identified' => $identified,
            'limit' => $limit,
            'rows' => $rows->take($limit)->values()->all(),
        ];
    }

    /** Most recently activated subscriptions (deals), newest first. */
    public function recentSubscriptions(array $arguments, McpAuthorizationContext $auth): array
    {
        $limit = $this->limit($arguments);
        $identified = $this->identified($arguments, $auth, 'exotic_recent_subscriptions');
        $query = Deal::query()->whereNotNull('activated_at');
        $this->scopeMarkets($query, $arguments, $auth);
        if (($arguments['status'] ?? 'any') === 'active') {
            $query->currentlyActive();
        }
        if (($arguments['include_free_trials'] ?? true) === false) {
            $query->where(fn (Builder $q) => $q->where('is_free_trial', false)->orWhereNull('is_free_trial'));
        }
        $deals = $query->with(['client:id,name', 'platform:id,name,country,currency_code', 'product:id,name'])
            ->orderByDesc('activated_at')->orderByDesc('id')->limit($limit)->get();

        $rows = $deals->map(fn (Deal $deal): array => array_filter([
            'activated_at' => $deal->activated_at?->toIso8601String(),
            'activated_at_eat' => $deal->activated_at ? $this->eat($deal->activated_at) : null,
            'expires_at_eat' => $deal->expires_at ? $this->eat($deal->expires_at) : null,
            'status' => $deal->effective_status ?? $deal->status,
            'profile_name' => $identified ? $deal->client?->name : null,
            'client_handle' => ! $identified && $deal->client_id ? $this->pseudonyms->handle('client', (int) $deal->client_id) : null,
            'market' => $deal->platform?->name,
            'country' => $deal->platform?->country,
            'product' => $deal->product?->name,
            'plan_type' => $deal->plan_type,
            'duration_days' => $deal->duration_days,
            'amount' => $deal->amount !== null ? round((float) $deal->amount, 2) : null,
            'currency' => $deal->currency ? strtoupper((string) $deal->currency) : $deal->platform?->currency_code,
            'free_trial' => (bool) $deal->is_free_trial,
            'origin' => $deal->origin,
            'agent_alias' => $this->agentAlias($deal->assigned_to),
        ], fn ($value) => $value !== null));

        return [
            'definition' => 'Subscriptions (CRM deals) ordered by activation time, newest first.',
            'identified' => $identified,
            'limit' => $limit,
            'rows' => $rows->values()->all(),
        ];
    }

    private function scopeMarkets(Builder $query, array $arguments, McpAuthorizationContext $auth): void
    {
        $platformId = $this->platform($arguments, $auth->user);
        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif (is_array($auth->platformIds)) {
            $query->whereIn('platform_id', $auth->platformIds ?: [0]);
        }
        if (! $platformId && ! empty($arguments['country'])) {
            $country = trim((string) $arguments['country']);
            $query->whereIn('platform_id', Platform::query()->where(fn ($q) => $q->where('country', $country)->orWhere('name', $country))->select('id'));
        }
    }

    private function platform(array $arguments, User $user): ?int
    {
        $platformId = isset($arguments['platform_id']) ? (int) $arguments['platform_id'] : null;
        if ($platformId && ! $this->markets->userCanAccessPlatform($user, $platformId)) {
            throw McpProtocolException::rpc(-32001, 'The requested market is not available to this account.', 'market_denied', 403);
        }

        return $platformId ?: null;
    }

    private function identified(array $arguments, McpAuthorizationContext $auth, string $tool): bool
    {
        if (($arguments['identified'] ?? false) !== true) {
            return false;
        }
        $purpose = trim((string) ($arguments['purpose'] ?? ''));
        if (! $auth->roleIs(['admin']) || ! $auth->allows('mcp:identified-clients') || $purpose === '') {
            throw McpProtocolException::rpc(-32011, 'Profile names require an administrator, the identified-client capability and a purpose.', 'identified_client_denied', 403);
        }
        AuditLog::query()->create(['actor_id' => $auth->user->id, 'action' => 'mcp_identified_client_rows', 'entity_type' => 'mcp_operations_feed', 'entity_id' => 0,
            'after_state' => ['tool' => $tool, 'platform_id' => $arguments['platform_id'] ?? null, 'limit' => $this->limit($arguments), 'purpose' => $purpose], 'created_at' => now()]);

        return true;
    }

    private function limit(array $arguments): int
    {
        return min(50, max(1, (int) ($arguments['limit'] ?? 10)));
    }

    private function agentAlias(?int $userId): ?string
    {
        return $userId ? $this->aliases->aliasFor($userId) : null;
    }

    private function usd(Payment $payment, string $currency, ?CarbonInterface $at): ?float
    {
        if ($currency === '') {
            return null;
        }
        try {
            $normalized = $this->currencies->normalizeEventRows([(object) [
                'event_date' => ($at ?? now())->toDateString(), 'currency' => $currency, 'amount' => (float) $payment->amount,
                'platform_id' => $payment->platform_id, 'platform_country' => $payment->platform?->country, 'platform_name' => $payment->platform?->name,
            ]], 'USD', false);
        } catch (\Throwable) {
            return null;
        }
        $total = $normalized['normalized_total'] ?? null;

        return is_numeric($total) && ($normalized['normalized_currency'] ?? null) === 'USD' ? round((float) $total, 2) : null;
    }

    private function eat(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(self::DISPLAY_TZ)->format('j M Y, H:i').' EAT';
    }
}
