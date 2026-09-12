<?php

namespace App\Services\Mcp;

use App\Models\McpDailyUsage;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class McpDailyBudget
{
    public function __construct(private readonly McpSettingsService $settings) {}

    public function reserve(PersonalAccessToken $token, int $rows, int $bytes): bool
    {
        $limits = $this->settings->effectiveLimits($token->id);

        return DB::transaction(function () use ($token, $rows, $bytes, $limits) {
            $day = now()->toDateString();
            $server = McpDailyUsage::query()->firstOrCreate(['usage_date' => $day, 'scope_type' => 'server', 'scope_id' => 0]);
            $tokenUsage = McpDailyUsage::query()->firstOrCreate(['usage_date' => $day, 'scope_type' => 'token', 'scope_id' => $token->id]);
            $server = McpDailyUsage::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $tokenUsage = McpDailyUsage::query()->whereKey($tokenUsage->id)->lockForUpdate()->firstOrFail();
            if ($server->rows_out + $rows > $limits['server_rows'] || $server->bytes_out + $bytes > $limits['server_bytes'] || $tokenUsage->rows_out + $rows > $limits['token_rows'] || $tokenUsage->bytes_out + $bytes > $limits['token_bytes']) {
                return false;
            }
            $server->increment('rows_out', $rows);
            $server->increment('bytes_out', $bytes);
            $tokenUsage->increment('rows_out', $rows);
            $tokenUsage->increment('bytes_out', $bytes);

            return true;
        }, 3);
    }
}
