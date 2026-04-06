<?php

namespace App\Billing\Diagnostics;

use App\Models\User;

final class BillingDiagnosticsPresenter
{
    /**
     * @param  array<int, int>|null  $visibleMarketIds
     * @return array<string, mixed>
     */
    public function present(
        BillingDiagnosticsView $view,
        User $viewer,
        ?array $visibleMarketIds = null,
        bool $canUseRouteSimulator = false,
        bool $canDrillAcrossMarkets = false
    ): array {
        return [
            'market_id' => $view->marketId,
            'provider_key' => $view->providerKey,
            'source' => $view->source,
            'sections' => $view->sections,
            'meta' => array_merge($view->meta, [
                'permissions' => [
                    'route_simulator' => $canUseRouteSimulator,
                    'cross_market_drillthrough' => $canDrillAcrossMarkets,
                ],
                'viewer' => [
                    'role' => $viewer->role,
                    'market_scope' => $visibleMarketIds === null ? 'all' : 'restricted',
                    'visible_market_ids' => $visibleMarketIds,
                ],
            ]),
        ];
    }
}
