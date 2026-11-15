<?php

namespace App\Http\Controllers\CRM;

use App\Exceptions\ClientLifecycleMutationException;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AuditService;
use App\Services\ClientLifecycleService;
use App\Services\MarketAuthorizationService;
use App\Support\ClientLockReason;
use App\Support\CrmAuditAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Staff lock / unlock for advertiser profiles: the Clients bulk bar and the
 * client page. A lock holds the profile in Expired (published, indexed,
 * contacts hidden) through the same WordPress lifecycle path SEO Recovery uses.
 *
 * Bulk locking is driven in small slices by the browser so every request stays
 * well inside proxy timeouts and the agent sees live progress.
 */
class ClientProfileLockController extends Controller
{
    /** Profiles one bulk request may change — each is a WordPress write plus a sync. */
    public const BULK_SLICE_LIMIT = 10;

    public function __construct(
        private readonly ClientLifecycleService $lifecycle,
        private readonly MarketAuthorizationService $marketAuthorization,
        private readonly AuditService $audit,
    ) {}

    public function reasons(): JsonResponse
    {
        return response()->json(['reasons' => ClientLockReason::options()]);
    }

    /** Read-only: what locking each selected profile would do. */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_ids' => 'required|array|min:1|max:200',
            'client_ids.*' => 'integer',
        ]);

        $clients = Client::query()
            ->with('platform')
            ->whereIn('id', $validated['client_ids'])
            ->orderBy('id')
            ->get();

        $summary = ['lock' => 0, 'republish' => 0, 'already_locked' => 0, 'blocked' => 0, 'paid' => 0];
        $rows = $clients->map(function (Client $client) use ($request, &$summary): array {
            $plan = $this->canAccess($request, $client)
                ? $this->lifecycle->lockPlan($client)
                : ['action' => 'blocked', 'code' => 'forbidden', 'reason' => 'You do not have access to this market.', 'has_paid_plan' => false];

            $summary[$plan['action']]++;
            if ($plan['has_paid_plan'] && in_array($plan['action'], ['lock', 'republish'], true)) {
                $summary['paid']++;
            }

            return [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
                'city' => $client->city,
                'market' => $client->platform?->name,
                'profile_status' => $client->profile_status,
                'lifecycle_state' => $client->lifecycle_state,
                'action' => $plan['action'],
                'code' => $plan['code'] ?? null,
                'reason' => $plan['reason'] ?? null,
                'has_paid_plan' => (bool) $plan['has_paid_plan'],
            ];
        })->values();

        $summary['missing'] = count(array_unique($validated['client_ids'])) - $clients->count();

        return response()->json([
            'rows' => $rows,
            'summary' => $summary,
            'slice_limit' => self::BULK_SLICE_LIMIT,
        ]);
    }

    /** One slice of a bulk lock. Per-profile failures are reported, never thrown. */
    public function bulkLock(Request $request): JsonResponse
    {
        $validated = $this->validateLockRequest($request, [
            'client_ids' => 'required|array|min:1|max:'.self::BULK_SLICE_LIMIT,
            'client_ids.*' => 'integer',
        ]);

        $clients = Client::query()
            ->with('platform')
            ->whereIn('id', $validated['client_ids'])
            ->orderBy('id')
            ->get();

        $results = [];
        $summary = ['locked' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($clients as $client) {
            if (! $this->canAccess($request, $client)) {
                $summary['failed']++;
                $results[] = $this->resultRow($client, 'failed', 'You do not have access to this market.');

                continue;
            }

            try {
                $row = $this->lockOne($request, $client, $validated['reason_code'], $validated['note'] ?? null);
            } catch (ClientLifecycleMutationException|\InvalidArgumentException $exception) {
                $row = $this->resultRow($client, 'skipped', $exception->getMessage());
            } catch (\Throwable $exception) {
                Log::error('Bulk profile lock failed', [
                    'client_id' => $client->id,
                    'wp_post_id' => $client->wp_post_id,
                    'error' => $exception->getMessage(),
                ]);
                $row = $this->resultRow($client, 'failed', 'WordPress did not confirm the lock. Retry this profile.');
            }

            $summary[$row['action'] === 'failed' ? 'failed' : ($row['action'] === 'skipped' ? 'skipped' : 'locked')]++;
            $results[] = $row;
        }

        return response()->json(['results' => $results, 'summary' => $summary]);
    }

    public function lock(Request $request, Client $client): JsonResponse
    {
        $this->authorizeAccess($request, $client);
        $validated = $this->validateLockRequest($request);

        try {
            $row = $this->lockOne($request, $client, $validated['reason_code'], $validated['note'] ?? null);
        } catch (ClientLifecycleMutationException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            Log::error('Profile lock failed', [
                'client_id' => $client->id,
                'wp_post_id' => $client->wp_post_id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'WordPress did not confirm the lock. Please retry.'], 502);
        }

        return response()->json([
            'message' => $row['message'],
            'result' => $row,
            'client' => $client->fresh(['platform']),
        ]);
    }

    public function unlock(Request $request, Client $client): JsonResponse
    {
        $this->authorizeAccess($request, $client);
        $validated = $request->validate(['note' => 'nullable|string|max:500']);
        $before = $this->lockSnapshot($client);

        try {
            $fresh = $this->lifecycle->unlock($client, (int) $request->user()->id, $validated['note'] ?? null);
        } catch (ClientLifecycleMutationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Profile unlock failed', [
                'client_id' => $client->id,
                'wp_post_id' => $client->wp_post_id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'WordPress did not confirm the unlock. Please retry.'], 502);
        }

        $isActive = ! $fresh->isPubliclyRestricted();
        $this->audit->fromRequest(
            $request,
            (int) $fresh->platform_id,
            CrmAuditAction::CLIENT_PROFILE_UNLOCK,
            'client',
            (int) $fresh->id,
            $before,
            $this->lockSnapshot($fresh),
            $validated['note'] ?? 'Unlocked profile',
        );

        return response()->json([
            'message' => $isActive
                ? 'Profile unlocked and live again on its paid plan.'
                : 'Lock lifted. With no running plan it stays Expired until the client pays.',
            'outcome' => $isActive ? 'active' : 'stays_expired',
            'client' => $fresh,
        ]);
    }

    /** @return array{client_id: int, name: string, action: string, message: string} */
    private function lockOne(Request $request, Client $client, string $reasonCode, ?string $note): array
    {
        $plan = $this->lifecycle->lockPlan($client);
        if ($plan['action'] === 'already_locked') {
            return $this->resultRow($client, 'skipped', $plan['reason'] ?? 'Already locked.');
        }

        $before = $this->lockSnapshot($client);
        $fresh = $this->lifecycle->lock($client, (int) $request->user()->id, $reasonCode, $note);

        $this->audit->fromRequest(
            $request,
            (int) $fresh->platform_id,
            CrmAuditAction::CLIENT_PROFILE_LOCK,
            'client',
            (int) $fresh->id,
            $before,
            $this->lockSnapshot($fresh) + ['mode' => $plan['action'], 'had_paid_plan' => $plan['has_paid_plan']],
            ClientLockReason::label($reasonCode).($note ? ': '.$note : ''),
        );

        $republished = $plan['action'] === 'republish';

        return $this->resultRow(
            $fresh,
            $republished ? 'republished' : 'locked',
            match (true) {
                $republished => 'Back online as a locked page. Contacts hidden.',
                $plan['has_paid_plan'] => 'Locked. The paid plan keeps running.',
                default => 'Locked. Still published, contacts hidden.',
            },
        );
    }

    private function validateLockRequest(Request $request, array $extra = []): array
    {
        $validated = $request->validate($extra + [
            'reason_code' => 'required|string|in:'.implode(',', ClientLockReason::ALL),
            'note' => 'nullable|string|max:500',
        ]);

        if (ClientLockReason::requiresNote($validated['reason_code']) && trim((string) ($validated['note'] ?? '')) === '') {
            abort(response()->json([
                'message' => 'Add a note explaining the lock.',
                'errors' => ['note' => ['Add a note explaining the lock.']],
            ], 422));
        }

        return $validated;
    }

    private function resultRow(Client $client, string $action, string $message): array
    {
        return [
            'client_id' => (int) $client->id,
            'name' => (string) $client->name,
            'action' => $action,
            'message' => $message,
        ];
    }

    private function lockSnapshot(Client $client): array
    {
        return [
            'profile_status' => $client->profile_status,
            'lifecycle_state' => $client->lifecycle_state,
            'profile_locked_at' => optional($client->profile_locked_at)->toDateTimeString(),
            'profile_lock_reason_code' => $client->profile_lock_reason_code,
        ];
    }

    private function canAccess(Request $request, Client $client): bool
    {
        return $this->marketAuthorization->userCanAccessPlatform($request->user(), (int) $client->platform_id);
    }

    private function authorizeAccess(Request $request, Client $client): void
    {
        if (! $this->canAccess($request, $client)) {
            abort(403, 'You do not have access to this client market.');
        }
    }
}
