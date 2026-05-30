<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\BriefingRecipient;
use App\Services\Ai\AiBriefingSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serves a single weekly briefing behind a short /b/{token} deep link.
 *
 * Authorization: the link is NOT self-authenticating. The viewer must be the
 * logged-in recipient (briefing_recipients.user_id) — or, when admin_override is
 * enabled, an active CEO/admin for support and audit. The token only identifies
 * which briefing to load; it never grants access on its own.
 *
 * Read-only.
 */
class AiBriefingShareController extends Controller
{
    public function __construct(private readonly AiBriefingSettingsService $settings) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $recipient = BriefingRecipient::with(['briefing.run'])
            ->where('share_token', $token)
            ->first();

        if (!$recipient) {
            return response()->json(['message' => 'Briefing not found.'], 404);
        }

        if ($recipient->isExpired()) {
            return response()->json(['message' => 'This briefing link has expired.'], 410);
        }

        $user = $request->user();
        if (!$this->authorizes($user, $recipient)) {
            return response()->json(['message' => 'You are not authorized to view this briefing.'], 403);
        }

        if ($recipient->opened_at === null) {
            $recipient->forceFill(['opened_at' => now()])->save();
        }

        $briefing = $recipient->briefing;

        return response()->json([
            'audience'   => $briefing->audience,
            'period'     => [
                'period'       => $briefing->period,
                'period_start' => optional($briefing->period_start)->toIso8601String(),
                'period_end'   => optional($briefing->period_end)->toIso8601String(),
            ],
            'scope' => [
                'org_wide'     => empty($briefing->scope_platform_ids),
                'platform_ids' => $briefing->scope_platform_ids ?? [],
            ],
            'summary_sms' => $briefing->summary_sms,
            'body'        => $briefing->decodedBody(),
            'recipient'   => [
                'name'      => $recipient->name,
                'opened_at' => optional($recipient->opened_at)->toIso8601String(),
            ],
            'generated_at' => optional($briefing->created_at)->toIso8601String(),
        ]);
    }

    private function authorizes($user, BriefingRecipient $recipient): bool
    {
        if (!$user) {
            return false;
        }

        if ((int) $user->id === (int) $recipient->user_id) {
            return true;
        }

        if ($this->settings->adminOverride()
            && ($user->status ?? 'active') === 'active'
            && (in_array($user->role ?? null, ['admin'], true) || (bool) ($user->is_ceo ?? false))
        ) {
            return true;
        }

        return false;
    }
}
