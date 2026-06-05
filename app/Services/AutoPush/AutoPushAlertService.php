<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushAlert;
use App\Models\AutoPushPlan;
use App\Models\PushCampaign;

class AutoPushAlertService
{
    public function raise(
        string $type,
        string $severity,
        string $title,
        ?string $body = null,
        array $context = [],
        ?AutoPushPlan $plan = null,
        ?PushCampaign $campaign = null,
        bool $dedupeOpenCampaign = false,
    ): AutoPushAlert {
        if ($dedupeOpenCampaign && $campaign) {
            $existing = AutoPushAlert::query()
                ->whereNull('resolved_at')
                ->where('type', $type)
                ->where('campaign_id', (int) $campaign->id)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return AutoPushAlert::query()->create([
            'auto_push_plan_id' => $plan?->id ?? $campaign?->auto_push_plan_id,
            'platform_id' => $plan?->platform_id ?? $campaign?->platform_id,
            'campaign_id' => $campaign?->id,
            'severity' => $severity,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'context' => $context,
        ]);
    }
}
