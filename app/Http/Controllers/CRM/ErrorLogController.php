<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\ErrorLogGroup;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErrorLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(5, min(100, $request->integer('per_page', 25)));
        $search = trim((string) $request->input('search', ''));
        $level = $request->input('level');
        $source = $request->input('source');
        $status = $request->input('status', 'unresolved');

        $query = ErrorLogGroup::query();

        if ($status === 'unresolved') {
            $query->whereNull('resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('resolved_at');
        }

        if (in_array($level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $query->where('level', $level);
        }

        if (in_array($source, ['exception', 'log', 'queue_job', 'client'], true)) {
            $query->where('source', $source);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('message', 'like', '%' . $search . '%')
                    ->orWhere('exception_class', 'like', '%' . $search . '%')
                    ->orWhere('file', 'like', '%' . $search . '%');
            });
        }

        $paginator = $query
            ->orderByDesc('last_seen_at')
            ->paginate($perPage)
            ->withQueryString();

        $data = $paginator->getCollection()->map(fn (ErrorLogGroup $group) => $this->transform($group));
        $paginator->setCollection($data);

        $summary = [
            'unresolved_critical' => ErrorLogGroup::query()
                ->whereNull('resolved_at')
                ->whereIn('level', ['critical', 'alert', 'emergency'])
                ->count(),
            'unresolved_total' => ErrorLogGroup::query()->whereNull('resolved_at')->count(),
            'occurrences_today' => ErrorLogGroup::query()
                ->where('last_seen_at', '>=', Carbon::today())
                ->sum('occurrence_count'),
            'resolved_last_7_days' => ErrorLogGroup::query()
                ->whereNotNull('resolved_at')
                ->where('resolved_at', '>=', Carbon::now()->subDays(7))
                ->count(),
            'top_offender' => ErrorLogGroup::query()
                ->whereNull('resolved_at')
                ->orderByDesc('occurrence_count')
                ->limit(1)
                ->get(['exception_class', 'message', 'occurrence_count'])
                ->map(fn (ErrorLogGroup $group) => [
                    'label' => $group->exception_class ? class_basename($group->exception_class) : 'Log entry',
                    'count' => (int) $group->occurrence_count,
                ])
                ->first(),
        ];

        return response()->json([
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'summary' => $summary,
        ]);
    }

    public function show(ErrorLogGroup $group): JsonResponse
    {
        $occurrences = $group->occurrences()
            ->with('user:id,name,email')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => array_merge(
                $this->transform($group),
                [
                    'occurrences' => $occurrences->map(fn ($occurrence) => [
                        'id' => $occurrence->id,
                        'occurred_at' => optional($occurrence->occurred_at)->toIso8601String(),
                        'trace' => $occurrence->trace,
                        'context' => $occurrence->context,
                        'url' => $occurrence->url,
                        'method' => $occurrence->method,
                        'ip' => $occurrence->ip,
                        'user' => $occurrence->user ? [
                            'id' => $occurrence->user->id,
                            'name' => $occurrence->user->name,
                            'email' => $occurrence->user->email,
                        ] : null,
                    ]),
                ]
            ),
        ]);
    }

    public function resolve(Request $request, ErrorLogGroup $group): JsonResponse
    {
        $group->resolved_at = now();
        $group->resolved_by = $request->user()?->id;
        $group->notes = $request->input('notes');
        $group->save();

        return response()->json(['data' => $this->transform($group)]);
    }

    public function reopen(ErrorLogGroup $group): JsonResponse
    {
        $group->resolved_at = null;
        $group->resolved_by = null;
        $group->save();

        return response()->json(['data' => $this->transform($group)]);
    }

    private function transform(ErrorLogGroup $group): array
    {
        return [
            'id' => $group->id,
            'signature' => $group->signature,
            'level' => $group->level,
            'source' => $group->source,
            'exception_class' => $group->exception_class,
            'message' => $group->message,
            'file' => $group->file,
            'line' => $group->line,
            'occurrence_count' => (int) $group->occurrence_count,
            'first_seen_at' => optional($group->first_seen_at)->toIso8601String(),
            'last_seen_at' => optional($group->last_seen_at)->toIso8601String(),
            'resolved_at' => optional($group->resolved_at)->toIso8601String(),
            'resolved_by' => $group->resolved_by,
            'notes' => $group->notes,
            'incident' => $group->toIncident(),
        ];
    }
}
