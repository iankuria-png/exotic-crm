<?php

namespace App\Services\Mcp\Presenters;

use App\Models\User;
use App\Services\Mcp\McpStaffAliasService;

class AgentPerformancePresenter
{
    public function __construct(private readonly McpStaffAliasService $aliases) {}

    /** MCP staff identity is a stable administrator-managed alias, never a name or ID. */
    public function present(array $payload, User $caller): array
    {
        if (! isset($payload['agents']) || ! is_array($payload['agents'])) {
            return $payload;
        }

        $payload['agents'] = array_map(function (array $agent): array {
            $alias = isset($agent['id']) ? $this->aliases->aliasFor((int) $agent['id']) : null;
            unset($agent['id'], $agent['name'], $agent['email'], $agent['phone']);
            if ($alias) {
                $agent['agent_alias'] = $alias;
            } else {
                $agent['staff_visibility'] = 'alias_not_configured';
            }

            return $agent;
        }, $payload['agents']);

        return $payload;
    }
}
