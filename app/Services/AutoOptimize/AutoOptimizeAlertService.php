<?php

namespace App\Services\AutoOptimize;

use App\Models\AutoOptimizeAlert;
use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;

class AutoOptimizeAlertService
{
    public function raise(
        string $type,
        string $severity,
        string $title,
        ?string $body = null,
        array $context = [],
        ?AutoOptimizePlan $plan = null,
        ?AutoOptimizeItem $item = null,
        bool $dedupeOpenPlan = false,
    ): AutoOptimizeAlert {
        if ($dedupeOpenPlan && $plan) {
            $existing = AutoOptimizeAlert::query()
                ->whereNull('resolved_at')
                ->where('type', $type)
                ->where('auto_optimize_plan_id', (int) $plan->id)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return AutoOptimizeAlert::query()->create([
            'auto_optimize_plan_id' => $plan?->id,
            'platform_id' => $item?->platform_id ?? $plan?->platform_id,
            'client_id' => $item?->client_id,
            'severity' => $severity,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'context' => $context,
        ]);
    }
}
