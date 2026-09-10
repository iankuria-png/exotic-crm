<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\McpToolCall;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

class McpActivityController extends Controller
{
    public function index(Request $request)
    {
        $query = McpToolCall::query()->latest('created_at');
        $query->when($request->filled('tool'), fn ($builder) => $builder->where('tool', $request->string('tool')));
        $query->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')));
        $query->when($request->filled('refusal_reason'), fn ($builder) => $builder->where('refusal_reason', $request->string('refusal_reason')));
        $query->when($request->filled('from'), fn ($builder) => $builder->where('created_at', '>=', Carbon::parse((string) $request->string('from'))->startOfDay()));
        $query->when($request->filled('to'), fn ($builder) => $builder->where('created_at', '<=', Carbon::parse((string) $request->string('to'))->endOfDay()));

        $rows = $query->limit(min(200, max(1, (int) $request->input('limit', 50))))->get();
        $tokenIds = $rows->pluck('token_id')->filter()->unique()->values();
        $tokenLabels = $tokenIds->isEmpty()
            ? collect()
            : PersonalAccessToken::query()->whereIn('id', $tokenIds)->pluck('name', 'id');
        $latencies = McpToolCall::query()->whereDate('created_at', today())->pluck('latency_ms')->map(fn ($value) => (int) $value)->sort()->values();
        $p95Index = $latencies->isEmpty() ? null : min($latencies->count() - 1, (int) ceil($latencies->count() * 0.95) - 1);
        $weekAgo = now()->subDays(7);
        $toolStats = McpToolCall::query()
            ->select('tool')
            ->selectRaw('COUNT(*) as calls_7d')
            ->selectRaw('COALESCE(SUM(bytes_out), 0) as bytes_7d')
            ->selectRaw('COALESCE(AVG(latency_ms), 0) as avg_latency_ms')
            ->selectRaw('SUM(CASE WHEN status IN (\'failed\', \'refused\') THEN 1 ELSE 0 END) as errors_7d')
            ->where('created_at', '>=', $weekAgo)
            ->groupBy('tool')
            ->get()
            ->keyBy('tool')
            ->map(fn ($row) => [
                'calls_7d' => (int) $row->calls_7d,
                'bytes_7d' => (int) $row->bytes_7d,
                'avg_latency_ms' => (int) round((float) $row->avg_latency_ms),
                'errors_7d' => (int) $row->errors_7d,
            ]);

        return response()->json([
            'rows' => $rows->map(function ($row) use ($tokenLabels) {
                $data = $row->toArray();
                $rawLabel = $row->token_id ? ($tokenLabels[$row->token_id] ?? 'Unknown token') : 'Session';
                $data['token_label'] = str_starts_with((string) $rawLabel, 'mcp:') ? substr((string) $rawLabel, 4) : $rawLabel;

                return $data;
            })->values(),
            'summary' => [
                'calls_today' => McpToolCall::query()->whereDate('created_at', today())->count(),
                'rows_today' => (int) McpToolCall::query()->whereDate('created_at', today())->sum('row_count'),
                'bytes_today' => (int) McpToolCall::query()->whereDate('created_at', today())->sum('bytes_out'),
                'refusals_today' => McpToolCall::query()->whereDate('created_at', today())->where('status', 'refused')->count(),
                'p95_latency_ms' => $p95Index === null ? 0 : (int) $latencies[$p95Index],
                'active_tokens' => PersonalAccessToken::query()->where('name', 'like', 'mcp:%')->where(function ($builder) {
                    $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->count(),
                'expiring_tokens' => PersonalAccessToken::query()->where('name', 'like', 'mcp:%')->whereBetween('expires_at', [now(), now()->addDays(7)])->count(),
            ],
            'tool_stats' => $toolStats,
        ]);
    }
}
