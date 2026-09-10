<?php

namespace App\Services\Mcp;

use App\Models\User;
use Illuminate\Http\Request;

class McpRequestAdapter
{
    public function build(array $arguments, User $user): Request
    {
        $query = [];
        foreach (['window' => 'horizon', 'from' => 'from', 'to' => 'to', 'platform_id' => 'platform_id', 'reporting_currency' => 'reporting_currency', 'bucket' => 'bucket', 'date' => 'date'] as $mcpKey => $httpKey) {
            if (array_key_exists($mcpKey, $arguments)) {
                $query[$httpKey] = $arguments[$mcpKey];
            }
        }

        $request = Request::create('/mcp-synthetic', 'GET', $query);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
