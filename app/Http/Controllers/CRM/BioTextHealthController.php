<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\RunBioTextScanJob;
use App\Models\BioTextFinding;
use App\Models\BioTextScan;
use App\Models\Client;
use App\Models\Platform;
use App\Services\BioTextRepairService;
use App\Services\MarketAuthorizationService;
use App\Support\BioTextIntegrity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Bio text: profile bios with garbled accents, lost characters or AI
 * leftovers, found per market and repaired in WordPress with a backup of
 * every bio. Admin-only (routes/api.php); the repair edits WordPress content.
 */
class BioTextHealthController extends Controller
{
    private const VIEWS = ['all', 'fixable', 'manual', 'repaired', 'problems'];

    public function __construct(
        private readonly BioTextRepairService $service,
        private readonly MarketAuthorizationService $marketAuth,
    ) {}

    /** The market's latest check and its recent history. */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate(['platform_id' => 'required|integer|exists:platforms,id']);
        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);

        $scans = BioTextScan::query()
            ->with(['requester:id,name', 'repairRequester:id,name', 'restoreRequester:id,name'])
            ->where('platform_id', (int) $platform->id)
            ->latest('id')
            ->limit(8)
            ->get();

        return response()->json([
            'platform' => ['id' => (int) $platform->id, 'name' => $platform->name],
            'linked_profiles' => Client::query()->where('platform_id', (int) $platform->id)->where('wp_post_id', '>', 0)->count(),
            'scan' => $scans->first() ? $this->presentScan($scans->first()) : null,
            'history' => $scans->map(fn (BioTextScan $scan) => $this->presentScan($scan, withCounts: false))->all(),
        ]);
    }

    /** Start a new check of every linked bio in the market. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['platform_id' => 'required|integer|exists:platforms,id']);
        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);
        $this->ensureNoActiveScan($platform);

        $scan = $this->service->start($platform, $request->user()?->id);
        if ($scan->total_profiles === 0) {
            $scan->delete();

            return response()->json(['message' => 'This market has no profiles linked to WordPress yet.'], 422);
        }

        RunBioTextScanJob::dispatch((int) $scan->id);

        return response()->json(['data' => $this->presentScan($scan->fresh(['requester:id,name']))], 201);
    }

    public function showScan(Request $request, BioTextScan $scan): JsonResponse
    {
        $this->authorizedPlatform($request, (int) $scan->platform_id);

        return response()->json(['data' => $this->presentScan($scan->fresh(['requester:id,name', 'repairRequester:id,name', 'restoreRequester:id,name']))]);
    }

    /** One page of a check's findings, filtered by view, issue and name. */
    public function findings(Request $request, BioTextScan $scan): JsonResponse
    {
        $this->authorizedPlatform($request, (int) $scan->platform_id);
        $validated = $request->validate([
            'view' => 'nullable|string|in:'.implode(',', self::VIEWS),
            'kind' => 'nullable|string|in:'.implode(',', array_keys(BioTextIntegrity::KINDS)),
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $page = $this->filteredFindings($scan, $validated)
            ->with('client:id,wp_profile_permalink')
            ->orderByRaw("case when severity = 'error' then 0 else 1 end")
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 25), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (BioTextFinding $finding) => $this->presentFinding($finding))->all(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ]);
    }

    /** Repair every fixable finding, or the chosen ones. */
    public function repair(Request $request, BioTextScan $scan): JsonResponse
    {
        $platform = $this->authorizedPlatform($request, (int) $scan->platform_id);
        $ids = $this->validatedIds($request);
        $this->ensureReviewable($scan, $platform);

        $count = $this->service->queueRepair($scan, $ids, $request->user()?->id);
        if ($count === 0) {
            return response()->json(['message' => 'Nothing left to repair in this check. Bios that need rewriting by hand are listed separately.'], 422);
        }

        RunBioTextScanJob::dispatch((int) $scan->id);

        return response()->json(['data' => $this->presentScan($scan->fresh(['requester:id,name', 'repairRequester:id,name']))]);
    }

    /** Put back the bios a repair changed: all of them, or the chosen ones. */
    public function restore(Request $request, BioTextScan $scan): JsonResponse
    {
        $platform = $this->authorizedPlatform($request, (int) $scan->platform_id);
        $ids = $this->validatedIds($request);
        $this->ensureReviewable($scan, $platform);

        $count = $this->service->queueRestore($scan, $ids, $request->user()?->id);
        if ($count === 0) {
            return response()->json(['message' => 'There are no repaired bios to put back in this check.'], 422);
        }

        RunBioTextScanJob::dispatch((int) $scan->id);

        return response()->json(['data' => $this->presentScan($scan->fresh(['requester:id,name', 'restoreRequester:id,name']))]);
    }

    /** Every finding as CSV, with each bio before and after: the run's backup. */
    public function export(Request $request, BioTextScan $scan): StreamedResponse
    {
        $platform = $this->authorizedPlatform($request, (int) $scan->platform_id);
        $filters = $request->validate([
            'view' => 'nullable|string|in:'.implode(',', self::VIEWS),
            'kind' => 'nullable|string|in:'.implode(',', array_keys(BioTextIntegrity::KINDS)),
            'search' => 'nullable|string|max:100',
        ]);
        $filename = sprintf('bio-text-%s-check-%d.csv', str($platform->name)->slug(), $scan->id);

        return response()->streamDownload(function () use ($scan, $filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['client_id', 'wp_post_id', 'name', 'issues', 'status', 'note', 'bio_before', 'bio_after', 'repaired_at', 'restored_at']);
            $this->filteredFindings($scan, $filters)->orderBy('id')->chunkById(200, function ($findings) use ($out) {
                foreach ($findings as $finding) {
                    fputcsv($out, [
                        (int) $finding->client_id,
                        (int) $finding->wp_post_id,
                        $finding->client_name,
                        implode(' ', array_filter(explode(',', (string) $finding->kinds))),
                        $finding->status,
                        (string) $finding->error,
                        $finding->original_html,
                        (string) $finding->repaired_html,
                        optional($finding->repaired_at)->toIso8601String(),
                        optional($finding->restored_at)->toIso8601String(),
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function filteredFindings(BioTextScan $scan, array $filters): Builder
    {
        $query = BioTextFinding::query()->where('scan_id', (int) $scan->id);

        match ($filters['view'] ?? 'all') {
            'fixable' => $query->where('fixable', true)->whereIn('status', [...BioTextFinding::REPAIRABLE_STATUSES, BioTextFinding::STATUS_QUEUED]),
            'manual' => $query->where(function (Builder $inner) {
                foreach (BioTextRepairService::MANUAL_KINDS as $kind) {
                    $inner->orWhere('kinds', 'like', "%,{$kind},%");
                }
            }),
            'repaired' => $query->whereIn('status', [BioTextFinding::STATUS_REPAIRED, BioTextFinding::STATUS_RESTORE_QUEUED]),
            'problems' => $query->whereNotNull('error'),
            default => null,
        };

        if (! empty($filters['kind'])) {
            $query->where('kinds', 'like', '%,'.$filters['kind'].',%');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('client_name', 'like', '%'.addcslashes($search, '%_\\').'%');
                if (ctype_digit($search)) {
                    $inner->orWhere('wp_post_id', (int) $search)->orWhere('client_id', (int) $search);
                }
            });
        }

        return $query;
    }

    /** @return list<int>|null */
    private function validatedIds(Request $request): ?array
    {
        $validated = $request->validate([
            'finding_ids' => 'sometimes|array|min:1|max:'.BioTextRepairService::MAX_SELECTION,
            'finding_ids.*' => 'integer|min:1',
        ]);

        return isset($validated['finding_ids']) ? array_values(array_unique(array_map('intval', $validated['finding_ids']))) : null;
    }

    private function authorizedPlatform(Request $request, int $platformId): Platform
    {
        $this->marketAuth->ensureUserCanAccessPlatform($request->user(), $platformId, 'You do not have access to this market.');

        return Platform::query()->findOrFail($platformId);
    }

    private function ensureNoActiveScan(Platform $platform): void
    {
        $active = BioTextScan::query()
            ->where('platform_id', (int) $platform->id)
            ->whereIn('status', BioTextScan::ACTIVE_STATUSES)
            ->exists();

        if ($active) {
            throw new ConflictHttpException('A bio text check or repair is already running for this market.');
        }
    }

    private function ensureReviewable(BioTextScan $scan, Platform $platform): void
    {
        $this->ensureNoActiveScan($platform);

        if (! $scan->isReviewable()) {
            throw new ConflictHttpException('This check has not finished reading the market yet.');
        }

        $newer = BioTextScan::query()->where('platform_id', (int) $platform->id)->where('id', '>', (int) $scan->id)->exists();
        if ($newer) {
            throw new ConflictHttpException('A newer check exists for this market. Repair from the latest check.');
        }
    }

    private function presentScan(BioTextScan $scan, bool $withCounts = true): array
    {
        $statusCounts = [];
        $manual = 0;
        $openFixable = 0;
        $restorable = 0;

        if ($withCounts) {
            $statusCounts = BioTextFinding::query()
                ->where('scan_id', (int) $scan->id)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($total) => (int) $total)
                ->all();
            $manual = $this->filteredFindings($scan, ['view' => 'manual'])->count();
            $openFixable = $this->filteredFindings($scan, ['view' => 'fixable'])->count();
            $restorable = $this->service->restorable($scan)->count();
        }

        $reviewable = $scan->isReviewable();

        return [
            'id' => (int) $scan->id,
            'platform_id' => (int) $scan->platform_id,
            'status' => $scan->status,
            'requested_by' => $scan->requester?->name,
            'repair_requested_by' => $scan->repairRequester?->name,
            'restore_requested_by' => $scan->restoreRequester?->name,
            'total_profiles' => (int) $scan->total_profiles,
            'profiles_scanned' => (int) $scan->profiles_scanned,
            'profiles_unreadable' => (int) $scan->profiles_unreadable,
            'profiles_affected' => (int) $scan->profiles_affected,
            'profiles_fixable' => (int) $scan->profiles_fixable,
            'profiles_manual' => $manual,
            'issue_counts' => (object) ($scan->issue_counts ?? []),
            'status_counts' => (object) $statusCounts,
            'open_fixable' => $openFixable,
            'restorable' => $restorable,
            'repair' => [
                'target' => (int) $scan->repair_target,
                'repaired' => (int) $scan->repaired,
                'unchanged' => (int) $scan->repair_unchanged,
                'failed' => (int) $scan->repair_failed,
            ],
            'restore' => [
                'target' => (int) $scan->restore_target,
                'restored' => (int) $scan->restored,
                'failed' => (int) $scan->restore_failed,
            ],
            'can_repair' => $reviewable && $openFixable > 0,
            'can_restore' => $reviewable && $restorable > 0,
            'notes' => $scan->notes,
            'created_at' => optional($scan->created_at)->toIso8601String(),
            'started_at' => optional($scan->started_at)->toIso8601String(),
            'scanned_at' => optional($scan->scanned_at)->toIso8601String(),
            'repair_started_at' => optional($scan->repair_started_at)->toIso8601String(),
            'finished_at' => optional($scan->finished_at)->toIso8601String(),
        ];
    }

    private function presentFinding(BioTextFinding $finding): array
    {
        return [
            'id' => (int) $finding->id,
            'client_id' => $finding->client_id,
            'wp_post_id' => (int) $finding->wp_post_id,
            'name' => $finding->client_name,
            'profile_url' => $finding->client?->wp_profile_permalink,
            'issues' => $finding->issues ?? [],
            'severity' => $finding->severity,
            'fixable' => (bool) $finding->fixable,
            'status' => $finding->status,
            'error' => $finding->error,
            'original_html' => $finding->original_html,
            'repaired_html' => $finding->repaired_html,
            'repaired_at' => optional($finding->repaired_at)->toIso8601String(),
            'restored_at' => optional($finding->restored_at)->toIso8601String(),
        ];
    }
}
