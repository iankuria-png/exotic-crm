<?php

namespace App\Services\Mcp\Presenters;

use App\Models\User;

class AgentPerformancePresenter
{
    /** Staff names are useful operational context; internal user IDs are not. */
    public function present(array $payload, User $caller): array
    {
        if (! isset($payload['agents']) || ! is_array($payload['agents'])) {
            return $payload;
        }

        $payload['agents'] = array_map(function (array $agent) use ($caller): array {
            $displayName = $agent['name'] ?? null;
            unset($agent['id'], $agent['name']);
            if (in_array($caller->role, ['admin', 'sub_admin'], true) && is_string($displayName) && $displayName !== '') {
                $agent['agent_display_name'] = $displayName;
            }

            return $agent;
        }, $payload['agents']);

        return $payload;
    }
}
