<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\Client;

class AutoPushBoostPlanResolver
{
    public function forClient(Client $client): ?AutoPushPlan
    {
        return AutoPushPlan::query()
            ->with('platform')
            ->where('platform_id', (int) $client->platform_id)
            ->where('enabled', true)
            ->orderByDesc('autopilot')
            ->latest('updated_at')
            ->first()
            ?: AutoPushPlan::query()
                ->with('platform')
                ->where('platform_id', (int) $client->platform_id)
                ->latest('updated_at')
                ->first();
    }
}
