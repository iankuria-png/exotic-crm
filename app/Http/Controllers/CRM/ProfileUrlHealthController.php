<?php

namespace App\Http\Controllers\CRM;

use App\Exceptions\ProfileUrlHealthUnavailableException;
use App\Http\Controllers\Controller;
use App\Jobs\RunProfileSlugAliasRepairJob;
use App\Models\Platform;
use App\Models\ProfileSlugAliasRepairRun;
use App\Services\MarketAuthorizationService;
use App\Services\ProfileSlugAliasRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Profile URLs: old profile addresses that redirect, or could redirect, to the
 * wrong profile. Admin-only (routes/api.php); the repair edits WordPress data.
 */
class ProfileUrlHealthController extends Controller
{
    private const KINDS = ['wrong_target', 'revivable', 'at_risk'];

    public function __construct(
        private readonly ProfileSlugAliasRepairService $repair,
        private readonly MarketAuthorizationService $marketAuth,
    ) {}

    /** The market's audit (summary + one page of URLs) and its recent runs. */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'kind' => 'nullable|string|in:'.implode(',', self::KINDS),
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);

        $runs = $this->recentRuns($platform);
        $base = [
            'platform' => ['id' => (int) $platform->id, 'name' => $platform->name],
            'runs' => $runs,
        ];

        try {
            $audit = $this->repair->audit(
                $platform,
                (string) ($validated['kind'] ?? ''),
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 25)
            );
        } catch (ProfileUrlHealthUnavailableException $exception) {
            return response()->json($base + [
                'available' => false,
                'reason' => $exception->reason,
                'message' => $exception->getMessage(),
            ]);
        }

        return response()->json($base + ['available' => true, 'audit' => $audit]);
    }

    /** Queue a repair of every stale alias in the market. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
        ]);
        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);
        $this->ensureNoActiveRun($platform);

        try {
            $summary = $this->repair->audit($platform, '', 1, 1)['summary'] ?? [];
        } catch (ProfileUrlHealthUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason], 409);
        }

        if ((int) ($summary['urls'] ?? 0) === 0) {
            return response()->json(['message' => 'Every profile URL in this market is already healthy.'], 422);
        }

        $run = ProfileSlugAliasRepairRun::create([
            'platform_id' => (int) $platform->id,
            'requested_by' => $request->user()?->id,
            'status' => ProfileSlugAliasRepairRun::STATUS_QUEUED,
            'audit_summary' => $summary,
            'target_urls' => (int) ($summary['urls'] ?? 0),
        ]);

        RunProfileSlugAliasRepairJob::dispatch((int) $run->id);

        return response()->json(['data' => $this->presentRun($run->fresh(['requester:id,name']))], 201);
    }

    public function showRun(Request $request, ProfileSlugAliasRepairRun $run): JsonResponse
    {
        $this->authorizedPlatform($request, (int) $run->platform_id);

        return response()->json(['data' => $this->presentRun($run->fresh(['requester:id,name', 'restorer:id,name']))]);
    }

    /** Put every alias the run released back, resuming a restore that stopped. */
    public function restore(Request $request, ProfileSlugAliasRepairRun $run): JsonResponse
    {
        $platform = $this->authorizedPlatform($request, (int) $run->platform_id);
        $this->ensureNoActiveRun($platform);

        if (! $run->canRestore()) {
            return response()->json(['message' => 'Only a finished run that released aliases can be restored.'], 422);
        }

        $run->forceFill([
            'status' => ProfileSlugAliasRepairRun::STATUS_RESTORING,
            'restored_by' => $request->user()?->id,
            'notes' => null,
        ])->save();

        RunProfileSlugAliasRepairJob::dispatch((int) $run->id);

        return response()->json(['data' => $this->presentRun($run->fresh(['requester:id,name', 'restorer:id,name']))]);
    }

    /** The run's released aliases as CSV: its backup, readable outside the CRM. */
    public function backup(Request $request, ProfileSlugAliasRepairRun $run): StreamedResponse
    {
        $platform = $this->authorizedPlatform($request, (int) $run->platform_id);
        $filename = sprintf('profile-url-repair-%s-run-%d.csv', str($platform->name)->slug(), $run->id);

        return response()->streamDownload(function () use ($run) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['meta_id', 'post_id', 'old_slug', 'case']);
            foreach ($run->backup ?? [] as $row) {
                fputcsv($out, [
                    (int) ($row['meta_id'] ?? 0),
                    (int) ($row['post_id'] ?? 0),
                    (string) ($row['slug'] ?? ''),
                    (string) ($row['kind'] ?? ''),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function authorizedPlatform(Request $request, int $platformId): Platform
    {
        $this->marketAuth->ensureUserCanAccessPlatform($request->user(), $platformId, 'You do not have access to this market.');

        return Platform::query()->findOrFail($platformId);
    }

    private function ensureNoActiveRun(Platform $platform): void
    {
        $active = ProfileSlugAliasRepairRun::query()
            ->where('platform_id', (int) $platform->id)
            ->whereIn('status', ProfileSlugAliasRepairRun::ACTIVE_STATUSES)
            ->exists();

        if ($active) {
            throw new ConflictHttpException('A profile URL repair is already running for this market.');
        }
    }

    private function recentRuns(Platform $platform): array
    {
        return ProfileSlugAliasRepairRun::query()
            ->with(['requester:id,name', 'restorer:id,name'])
            ->where('platform_id', (int) $platform->id)
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (ProfileSlugAliasRepairRun $run) => $this->presentRun($run))
            ->all();
    }

    private function presentRun(ProfileSlugAliasRepairRun $run): array
    {
        $backup = $run->backup ?? [];
        $byKind = array_fill_keys(self::KINDS, 0);
        foreach ($backup as $row) {
            $kind = (string) ($row['kind'] ?? '');
            if (isset($byKind[$kind])) {
                $byKind[$kind]++;
            }
        }

        return [
            'id' => (int) $run->id,
            'platform_id' => (int) $run->platform_id,
            'status' => $run->status,
            'requested_by' => $run->requester?->name,
            'restored_by' => $run->restorer?->name,
            'target_urls' => (int) $run->target_urls,
            'urls_processed' => (int) $run->urls_processed,
            'aliases_released' => (int) $run->aliases_released,
            'released_by_kind' => $byKind,
            'restored_count' => (int) $run->restored_count,
            'backup_count' => count($backup),
            'changes' => $run->audit_summary['changes'] ?? null,
            'notes' => $run->notes,
            'can_restore' => $run->canRestore(),
            'created_at' => optional($run->created_at)->toIso8601String(),
            'started_at' => optional($run->started_at)->toIso8601String(),
            'finished_at' => optional($run->finished_at)->toIso8601String(),
            'restored_at' => optional($run->restored_at)->toIso8601String(),
        ];
    }
}
