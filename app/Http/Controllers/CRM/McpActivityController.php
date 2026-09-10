<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\McpToolCall;
use Illuminate\Http\Request;

class McpActivityController extends Controller
{
    public function index(Request $request)
    {
        $query = McpToolCall::query()->latest('created_at');
        $query->when($request->filled('tool'), fn ($builder) => $builder->where('tool', $request->string('tool')));
        $query->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')));

        return response()->json([
            'rows' => $query->limit(min(200, max(1, (int) $request->input('limit', 50))))->get(),
            'summary' => [
                'calls_today' => McpToolCall::query()->whereDate('created_at', today())->count(),
                'rows_today' => (int) McpToolCall::query()->whereDate('created_at', today())->sum('row_count'),
                'bytes_today' => (int) McpToolCall::query()->whereDate('created_at', today())->sum('bytes_out'),
                'refusals_today' => McpToolCall::query()->whereDate('created_at', today())->where('status', 'refused')->count(),
            ],
        ]);
    }
}
