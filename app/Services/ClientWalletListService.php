<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ClientWalletListService
{
    private array $currencyPolicies = [];

    public function __construct(private readonly WalletSettingsService $walletSettingsService) {}

    public function applyPositiveBalanceFilter(Builder $query): void
    {
        // Resolve currency rules once per market, using the same settings as the wallet tab.
        // The subquery retains the caller's market access and existing filters.
        $platforms = Platform::query()->whereIn('id', (clone $query)->reorder()->select('clients.platform_id'))->get();
        $groups = [];
        foreach ($platforms as $platform) {
            [$primary, $currencies] = $this->currencyPolicy($platform);
            $key = $primary.':'.implode(',', $currencies);
            $groups[$key]['primary'] = $primary;
            $groups[$key]['currencies'] = $currencies;
            $groups[$key]['platform_ids'][] = $platform->id;
        }

        if ($groups === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $markets) use ($groups): void {
            foreach ($groups as $group) {
                $markets->orWhere(function (Builder $market) use ($group): void {
                    $primary = $group['primary'];
                    $market->whereIn('clients.platform_id', $group['platform_ids'])
                        ->where(function (Builder $wallet) use ($group, $primary): void {
                            $wallet->whereHas('walletBalances', fn (Builder $balances) => $balances
                                ->whereIn('currency', $group['currencies'])->where('balance', '>', 0))
                                ->orWhere(function (Builder $legacy) use ($primary): void {
                                    // A primary ledger supersedes its old mirror, even at zero.
                                    $legacy->where('wallet_balance', '>', 0)
                                        ->where(function (Builder $currency) use ($primary): void {
                                            $currency->whereNull('wallet_currency')
                                                ->orWhereRaw("TRIM(wallet_currency) = ''")
                                                ->orWhereRaw('UPPER(TRIM(wallet_currency)) = ?', [$primary]);
                                        })
                                        ->whereDoesntHave('walletBalances', fn (Builder $balances) => $balances->where('currency', $primary));
                                });
                        });
                });
            }
        });
    }

    public function decorate(Collection $clients): void
    {
        $clients->loadMissing(['platform', 'walletBalances']);

        foreach ($clients as $client) {
            $client->setAttribute('wallet_list_balances', $this->balances($client));
            $client->unsetRelation('walletBalances');
        }
    }

    private function balances(Client $client): array
    {
        [$primary, $currencies] = $this->currencyPolicy($client->platform);
        $balances = $client->walletBalances->mapWithKeys(fn ($balance) => [
            strtoupper(trim((string) $balance->currency)) => [
                'currency' => strtoupper(trim((string) $balance->currency)),
                'balance' => $balance->balance,
                'last_synced_at' => $balance->last_synced_at?->toIso8601String(),
            ],
        ]);
        $legacyCurrency = strtoupper(trim((string) $client->wallet_currency));
        if (! $balances->has($primary) && ($legacyCurrency === '' || $legacyCurrency === $primary)) {
            $balances->put($primary, [
                'currency' => $primary,
                'balance' => $client->wallet_balance,
                'last_synced_at' => $client->wallet_last_synced_at?->toIso8601String(),
            ]);
        }

        return collect($currencies)->map(fn (string $currency) => $balances->get($currency))
            ->filter(fn (?array $balance) => $balance !== null && (float) $balance['balance'] > 0)
            ->values()->all();
    }

    private function currencyPolicy(Platform $platform): array
    {
        if (! isset($this->currencyPolicies[$platform->id])) {
            $primary = $this->walletSettingsService->runtimeWalletCurrencyCode($platform, '');
            $currencies = array_values(array_unique(array_merge([$primary], $platform->effectiveCurrencies())));
            $this->currencyPolicies[$platform->id] = [$primary, $currencies];
        }

        return $this->currencyPolicies[$platform->id];
    }
}
