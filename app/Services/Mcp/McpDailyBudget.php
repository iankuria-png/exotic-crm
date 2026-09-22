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
            // A date cast can serialize as midnight while SQLite stores DATE as
            // YYYY-MM-DD. Insert-or-ignore plus whereDate is both idempotent and
            // safe under concurrent stateless calls.
            foreach ([['scope_type' => 'server', 'scope_id' => 0], ['scope_type' => 'token', 'scope_id' => $token->id]] as $scope) {
                DB::table('mcp_daily_usages')->insertOrIgnore([
                    'usage_date' => $day,
                    ...$scope,
                    'rows_out' => 0,
                    'bytes_out' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $server = McpDailyUsage::query()->whereDate('usage_date', $day)->where('scope_type', 'server')->where('scope_id', 0)->lockForUpdate()->firstOrFail();
            $tokenUsage = McpDailyUsage::query()->whereDate('usage_date', $day)->where('scope_type', 'token')->where('scope_id', $token->id)->lockForUpdate()->firstOrFail();
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
