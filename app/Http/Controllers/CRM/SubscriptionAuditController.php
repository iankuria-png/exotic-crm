<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SubscriptionAuditEvent;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\Mcp\McpAuthorizationContext;
use App\Services\Mcp\Results\ToolResult;
use App\Services\SubscriptionAudit\SubscriptionAuditException;
use App\Services\SubscriptionAudit\SubscriptionAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionAuditController extends Controller
{
    public function show(Request $request, string $kind, SubscriptionAuditService $reports): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isActive() && $user->role === 'admin', 403);
        $arguments = $request->validate([
            'purpose' => ['required', 'string', 'min:3', 'max:160'],
            'run_ref' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'string', 'max:40'],
            'to' => ['sometimes', 'string', 'max:40'],
            'platform_id' => ['sometimes', 'integer', 'min:1'],
            'country' => ['sometimes', 'string', 'max:80'],
            'staff_handle' => ['sometimes', 'string', 'max:100'],
            'event_type' => ['sometimes', 'in:activation,renewal,extension,observation'],
            'population' => ['sometimes', 'in:live,test,all'],
            'exceptions_only' => ['sometimes', 'boolean'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:499'],
            'event_ref' => ['sometimes', 'string', 'max:100'],
            'include_identity' => ['sometimes', 'boolean'],
            'identified' => ['sometimes', 'boolean'],
            'profile_scope' => ['sometimes', 'in:active,published'],
            'payment_filter' => ['sometimes', 'in:all,no_successful_cash_payment_in_retained_crm,payment_history_unverified,current_action_payment_unverified,authorised_free,approval_unverified'],
        ]);
        foreach (['platform_id', 'page_size'] as $key) {
            if (isset($arguments[$key])) {
                $arguments[$key] = (int) $arguments[$key];
            }
        }
        foreach (['exceptions_only', 'identified'] as $key) {
            if (isset($arguments[$key])) {
                $arguments[$key] = $request->boolean($key);
            }
        }
        $includeIdentity = $kind === 'trace' && $request->boolean('include_identity');
        unset($arguments['include_identity']);
        $auth = McpAuthorizationContext::for($user, ['mcp:read', 'mcp:subscription-audit', 'mcp:identified-clients'], app(MarketAuthorizationService::class));

        try {
            $data = $reports->report($kind, $arguments, $auth);
        } catch (SubscriptionAuditException $exception) {
            return response()->json(['error' => ['code' => $exception->getCode(), 'message' => $exception->getMessage(), 'reason' => $exception->reason]], $exception->httpStatus);
        }

        $meta = $data['_report_meta'] ?? [];
        unset($data['_report_meta']);
        if ($includeIdentity) {
            // Only the CRM's existing administrator session can resolve names.
            // The service has already checked the run, event and current scope.
            $event = SubscriptionAuditEvent::query()
                ->whereHas('run', fn ($query) => $query->where('run_ref', $data['run_ref']))
                ->where('event_ref', $arguments['event_ref'])->firstOrFail();
            abort_unless($auth->platformIds === null || in_array((int) $event->platform_id, $auth->platformIds, true), 403);
            $staff = User::query()->whereIn('id', array_filter([$event->actor_id, $event->approver_id]))->get(['id', 'name', 'role'])->keyBy('id');
            $client = $event->client_id
                ? Client::query()->whereKey($event->client_id)->where('platform_id', $event->platform_id)->first(['id', 'name'])
                : null;
            $data['crm_identity'] = [
                'actor' => $event->actor_id ? ($staff->get($event->actor_id)?->toArray() ?? ['id' => $event->actor_id, 'state' => 'former_or_unavailable']) : null,
                'approver' => $event->approver_id ? ($staff->get($event->approver_id)?->toArray() ?? ['id' => $event->approver_id, 'state' => 'former_or_unavailable']) : null,
                'advertiser' => $client?->toArray(),
            ];
            AuditLog::query()->create([
                'platform_id' => $event->platform_id,
                'actor_id' => $user->id,
                'action' => 'subscription_audit_identity_read',
                'entity_type' => 'subscription_audit_event',
                'entity_id' => $event->id,
                'after_state' => ['purpose' => $arguments['purpose'], 'event_ref' => $event->event_ref, 'run_ref' => $data['run_ref']],
                'created_at' => now(),
            ]);
        }

        return response()->json(ToolResult::envelope($data, [], $meta))->header('Cache-Control', 'private, no-store');
    }
}
