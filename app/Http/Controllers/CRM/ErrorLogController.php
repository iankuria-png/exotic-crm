<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\ErrorLogGroup;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ErrorLogController extends Controller
{
    /** Ceiling on a JSON debug bundle: traces are big, and 500 signatures is already more than anyone works through in a sitting. */
    private const MAX_BUNDLE_GROUPS = 500;

    public function index(Request $request): JsonResponse
    {
        $perPage = max(5, min(100, $request->integer('per_page', 25)));

        $paginator = $this->filteredQuery($request)
            ->orderByDesc('last_seen_at')
            ->paginate($perPage)
            ->withQueryString();

        $data = $paginator->getCollection()->map(fn (ErrorLogGroup $group) => $this->transform($group));
        $paginator->setCollection($data);

        $summary = $this->summary();

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

    /**
     * Download the filtered error table.
     *
     * Two shapes, because two jobs. `csv` is the table as you see it — one row
     * per signature, for triage and for handing a list to somebody. `json` is
     * the debugging shape: the same groups, each carrying its recent
     * occurrences with full stack traces, request URLs and context, so an
     * engineer (or Claude) can work the failure without a second round trip.
     */
    public function export(Request $request): StreamedResponse
    {
        $format = $request->input('format') === 'json' ? 'json' : 'csv';
        $stamp = now()->format('Ymd-His');

        return $format === 'json'
            ? $this->streamJsonBundle($request, "crm-errors-{$stamp}.json")
            : $this->streamCsv($request, "crm-errors-{$stamp}.csv");
    }

    private function streamCsv(Request $request, string $filename): StreamedResponse
    {
        $query = $this->filteredQuery($request)->with('latestOccurrence', 'resolver:id,name,email');

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Error', 'Message', 'File', 'Line', 'Level', 'Source', 'Status',
                'Occurrences', 'First seen', 'Last seen', 'Resolved at', 'Resolved by',
                'Latest request', 'Latest request ID', 'Notes', 'Signature',
            ]);

            // chunkById reorders by primary key, so rows arrive oldest-first
            // regardless of what we ask for. That is fine for a spreadsheet and
            // it is the only iteration that cannot skip rows while new errors
            // are still being recorded underneath us.
            $query->chunkById(500, function ($groups) use ($handle) {
                foreach ($groups as $group) {
                    $latest = $group->latestOccurrence;

                    fputcsv($handle, [
                        $group->exception_class ?: 'Log entry',
                        (string) $group->message,
                        (string) $group->file,
                        $group->line,
                        $group->level,
                        $group->source,
                        $group->resolved_at ? 'resolved' : 'unresolved',
                        (int) $group->occurrence_count,
                        optional($group->first_seen_at)->toDateTimeString(),
                        optional($group->last_seen_at)->toDateTimeString(),
                        optional($group->resolved_at)->toDateTimeString(),
                        $group->resolver?->name,
                        $latest ? trim(($latest->method ?: '').' '.($latest->url ?: '')) : null,
                        $latest->context['request_id'] ?? null,
                        (string) $group->notes,
                        $group->signature,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Stack traces are large, so the bundle is written out group by group
     * rather than assembled in memory, and capped at MAX_BUNDLE_GROUPS. A
     * truncated bundle says so in its own header instead of silently ending.
     */
    private function streamJsonBundle(Request $request, string $filename): StreamedResponse
    {
        $perGroup = max(1, min(20, $request->integer('occurrences', 5)));
        $query = $this->filteredQuery($request);
        $matched = (clone $query)->count();

        $header = [
            'generated_at' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'app_url' => config('app.url'),
            'filters' => [
                'search' => trim((string) $request->input('search', '')) ?: null,
                'level' => $request->input('level') ?: null,
                'source' => $request->input('source') ?: null,
                'status' => $request->input('status', 'unresolved') ?: 'all',
            ],
            'matched_groups' => $matched,
            'exported_groups' => min($matched, self::MAX_BUNDLE_GROUPS),
            'truncated' => $matched > self::MAX_BUNDLE_GROUPS,
            'occurrences_per_group' => $perGroup,
            'summary' => $this->summary(),
        ];

        return response()->streamDownload(function () use ($query, $header, $perGroup) {
            echo '{';

            foreach ($header as $key => $value) {
                echo json_encode($key).':'.json_encode($value).',';
            }

            echo '"groups":[';

            // Resolve the most-recent N ids up front, then hydrate them in small
            // batches. chunk()/chunkById() would either fight the cap or lose the
            // recency ordering, and the ordering is the point: a truncated bundle
            // should hold the newest failures, not the oldest.
            $ids = $query->orderByDesc('last_seen_at')
                ->orderByDesc('id')
                ->limit(self::MAX_BUNDLE_GROUPS)
                ->pluck('id');

            $written = 0;

            foreach ($ids->chunk(50) as $idChunk) {
                $groups = ErrorLogGroup::query()
                    ->whereIn('id', $idChunk)
                    ->get()
                    ->sortBy(fn (ErrorLogGroup $group) => $idChunk->search($group->id))
                    ->values();

                foreach ($groups as $group) {
                    if ($written > 0) {
                        echo ',';
                    }

                    echo json_encode($this->bundleGroup($group, $perGroup), JSON_UNESCAPED_SLASHES);
                    $written++;
                }

                flush();
            }

            echo ']}';
        }, $filename, ['Content-Type' => 'application/json']);
    }

    private function bundleGroup(ErrorLogGroup $group, int $perGroup): array
    {
        $occurrences = $group->occurrences()
            ->with('user:id,name,email')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($perGroup)
            ->get();

        return array_merge($this->transform($group), [
            'recent_occurrences' => $occurrences->map(fn ($occurrence) => [
                'occurred_at' => optional($occurrence->occurred_at)->toIso8601String(),
                'method' => $occurrence->method,
                'url' => $occurrence->url,
                'ip' => $occurrence->ip,
                'user' => $occurrence->user ? [
                    'id' => $occurrence->user->id,
                    'name' => $occurrence->user->name,
                    'email' => $occurrence->user->email,
                ] : null,
                'context' => $occurrence->context,
                'trace' => $occurrence->trace,
            ])->all(),
        ]);
    }

    private function filteredQuery(Request $request)
    {
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
                $builder->where('message', 'like', '%'.$search.'%')
                    ->orWhere('exception_class', 'like', '%'.$search.'%')
                    ->orWhere('file', 'like', '%'.$search.'%');
            });
        }

        return $query;
    }

    private function summary(): array
    {
        return [
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
