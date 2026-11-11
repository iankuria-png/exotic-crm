<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Collections\CollectionSubsidiaryObligation;
use App\Models\Deal;
use App\Models\Platform;
use App\Services\Collections\CollectionAccessService;
use App\Services\Collections\CollectionSubsidiaryFulfillmentService;
use App\Services\MarketAuthorizationService;
use App\Services\SubsidiaryActivationPreviewService;
use App\Services\SubsidiaryActivationSummaryService;
use App\Services\SubsidiaryTrialService;
use Illuminate\Http\Request;

class CollectionSubsidiaryController extends Controller
{
    private function owner(Request $request, int $receipt): CollectionSubsidiaryObligation
    {
        abort_unless(config('collection.workspace_enabled'), 404);
        $r = app(CollectionAccessService::class)->receipt($request->user(), $receipt);
        $o = CollectionSubsidiaryObligation::where('allocation_id', $r->allocation?->id)->orderByDesc('source_generation')->firstOrFail();
        app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($request->user(), $o->target_platform_id);

        return $o;
    }

    public function show(Request $request, int $receipt)
    {
        $o = $this->owner($request, $receipt);

        $trial = $o->trial_request_id ? \App\Models\FreeTrialRequest::find($o->trial_request_id) : null;
        $job = \Illuminate\Support\Facades\DB::table('collection_outbox')->where('kind', 'subsidiary')->where('subject_id', $o->id)->orderByDesc('id')->first();

        return response()->json(['id' => $o->id, 'version' => $o->version, 'state' => $o->state, 'last_error_code' => $o->last_error_code,
            'profile_ready' => (bool) $trial?->client_id,
            'profile_action' => $trial?->client_id ? ($trial->activation_result_json['profile_origin'] ?? 'existing') : ($trial ? 'creating' : 'finding'),
            'duration_days' => $o->interval['duration_days'] ?? $o->allocation->purchase_snapshot['duration_days'],
            'automation' => ['attempts' => (int) ($job?->attempts ?? 0),
                'next_retry_at' => $job && ! $job->completed_at ? \Illuminate\Support\Carbon::parse($job->available_at)->toIso8601String() : null],
            'deal_id' => $o->source_deal_id, 'target_platform_id' => $o->target_platform_id, 'trial_request_id' => $o->trial_request_id,
            'summary' => app(SubsidiaryActivationSummaryService::class)->read(Deal::findOrFail($o->source_deal_id), $request->user())]);
    }

    public function targets(Request $request, int $receipt)
    {
        $o = $this->owner($request, $receipt);
        $targets = app(SubsidiaryTrialService::class)->targetsForDeal(Deal::findOrFail($o->source_deal_id), $request->user());
        $targets['targets'] = array_values(array_filter($targets['targets'], fn ($p) => (int) $p['id'] === (int) $o->target_platform_id));
        $targets['recommended_platform_id'] = $o->target_platform_id;

        return response()->json($targets);
    }

    private function previewFor(Request $request, CollectionSubsidiaryObligation $o, array $input): array
    {
        $main = Deal::findOrFail($o->source_deal_id);
        $options = array_intersect_key($input, array_flip(['copy_region_id', 'copy_city_id', 'copy_currency_id']));
        $preview = app(SubsidiaryTrialService::class)->previewForDeal($main, Platform::findOrFail($o->target_platform_id), ! empty($input['client_id']) ? (int) $input['client_id'] : null,
            $options + ['duration_mode' => 'match_primary', 'copy' => true, 'collection_obligation_id' => $o->id]);
        $preview['preview_token'] = app(SubsidiaryActivationPreviewService::class)->issue($request->user(), $main, $preview, ! empty($input['client_id']) ? (int) $input['client_id'] : null, (int) $main->duration_days);
        unset($preview['resolution']['identity']);

        return $preview;
    }

    public function preview(Request $request, int $receipt)
    {
        $o = $this->owner($request, $receipt);
        $input = $request->validate(['client_id' => 'nullable|integer', 'copy_region_id' => 'nullable|integer', 'copy_city_id' => 'nullable|integer', 'copy_currency_id' => 'nullable|integer']);

        return response()->json($this->previewFor($request, $o, $input));
    }

    public function finish(Request $request, int $receipt)
    {
        $o = $this->owner($request, $receipt);
        $input = $request->validate(['expected_version' => 'required|integer|min:1', 'manual_client_id' => 'nullable|integer',
            'preview_token' => 'required|string', 'create_confirmed' => 'sometimes|boolean', 'pin' => 'nullable|regex:/^\d{4,6}$/',
            'copy_region_id' => 'nullable|integer', 'copy_city_id' => 'nullable|integer', 'copy_currency_id' => 'nullable|integer']);
        $input['client_id'] = $input['manual_client_id'] ?? null;
        $main = Deal::findOrFail($o->source_deal_id);
        $preview = app(SubsidiaryTrialService::class)->previewForDeal($main, Platform::findOrFail($o->target_platform_id), $input['client_id'],
            $input + ['duration_mode' => 'match_primary', 'copy' => true, 'collection_obligation_id' => $o->id]);
        app(SubsidiaryActivationPreviewService::class)->validate($request->user(), $main,
            $input + ['platform_id' => (int) $o->target_platform_id, 'duration_days' => (int) $main->duration_days], $preview);
        abort_if($preview['resolution']['kind'] === 'create_if_needed' && empty($input['create_confirmed']), 422, 'Confirm creation of the paired profile.');
        app(CollectionSubsidiaryFulfillmentService::class)->finish($o, $request->user(), $input);

        return $this->show($request, $receipt);
    }
}
