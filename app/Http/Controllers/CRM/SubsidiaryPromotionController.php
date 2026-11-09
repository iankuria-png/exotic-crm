<?php

namespace App\Http\Controllers\CRM;

use App\Billing\BillingPermissions;
use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Models\SubsidiaryProfileCopyRun;
use App\Services\MarketAuthorizationService;
use App\Services\SubsidiaryProfileCopyService;
use App\Services\SubsidiaryPromotionPolicyService;
use Illuminate\Http\Request;

/** Paired-market promotion settings and explicit profile-setup recovery. */
class SubsidiaryPromotionController extends Controller
{
    public function __construct(private readonly SubsidiaryPromotionPolicyService $policies) {}

    /** Configured pairs this user can see in both markets. Reads do not mutate. */
    public function index(Request $request)
    {
        $auth = app(MarketAuthorizationService::class);
        $pairs = [];
        foreach ((array) config('subsidiary_trials.pairs', []) as $source => $target) {
            if (! $auth->userCanAccessPlatform($request->user(), (int) $source) || ! $auth->userCanAccessPlatform($request->user(), (int) $target)) {
                continue;
            }
            $pairs[] = $this->payload($request, (int) $source, (int) $target);
        }

        return response()->json(['pairs' => $pairs, 'editable' => BillingPermissions::canEditBillingConfig($request->user()), 'copy_enabled' => app(SubsidiaryProfileCopyService::class)->enabled()]);
    }

    public function show(Request $request, Platform $source, Platform $target)
    {
        $this->access($request, $source, $target);

        return response()->json($this->payload($request, $source->id, $target->id));
    }

    public function update(Request $request, Platform $source, Platform $target)
    {
        $this->access($request, $source, $target);
        abort_unless(BillingPermissions::canEditBillingConfig($request->user()) && ! $request->attributes->get('crm_impersonator'), 403, 'You cannot change billing settings.');
        $v = $request->validate(['duration_mode' => 'required|in:match_primary,fixed_days', 'fixed_days' => 'nullable|required_if:duration_mode,fixed_days|integer|min:1|max:90',
            'allow_day_override' => 'required|boolean', 'copy_new_profile' => 'required|boolean', 'version' => 'required|integer|min:0', 'reason' => 'nullable|string|max:1000']);
        $this->policies->save($request->user(), $source->id, $target->id, $v, trim((string) ($v['reason'] ?? '')) ?: 'Updated paired-market settings.');

        return response()->json($this->payload($request, $source->id, $target->id));
    }

    public function resume(Request $request, SubsidiaryProfileCopyRun $run)
    {
        abort_if($request->attributes->get('crm_impersonator'), 403);
        $v = $request->validate(['version' => 'required|integer', 'reason' => 'required|string|max:2000']);

        return response()->json(app(SubsidiaryProfileCopyService::class)->resume($run, $request->user(), (int) $v['version'], $v['reason']), 202);
    }

    private function access(Request $request, Platform $source, Platform $target): void
    {
        $auth = app(MarketAuthorizationService::class);
        $auth->ensureUserCanAccessPlatform($request->user(), $source->id);
        $auth->ensureUserCanAccessPlatform($request->user(), $target->id);
        abort_unless($this->policies->paired($source->id, $target->id), 422, 'These markets are not a configured pair.');
    }

    private function payload(Request $request, int $source, int $target): array
    {
        $names = Platform::whereIn('id', [$source, $target])->pluck('name', 'id');

        return $this->policies->policy($source, $target) + ['source_name' => $names[$source] ?? null, 'target_name' => $names[$target] ?? null];
    }
}
