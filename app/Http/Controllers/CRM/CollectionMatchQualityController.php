<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Collections\CollectionAccessService;
use App\Services\Collections\CollectionMatchAccuracyService;
use App\Services\Collections\CollectionMatchFeedbackService;
use App\Services\MarketAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Staff explanations for profile choices and the matching accuracy report. */
class CollectionMatchQualityController extends Controller
{
    public function __construct(private readonly CollectionAccessService $access, private readonly CollectionMatchFeedbackService $feedback) {}

    private function enabled(): void
    {
        abort_unless(config('collection.workspace_enabled') && Schema::hasTable('collection_match_feedback'), 404);
    }

    public function show(Request $request, int $receipt)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $r->load('allocation', 'sale');
        $suggestion = $this->feedback->suggestion($r);
        $outcomes = $this->feedback->outcomeClientIds($r);
        $rows = $this->feedback->forReceipt($r);
        $names = DB::table('clients')->whereIn('id', array_filter([...$outcomes, $suggestion['client_id']]))->pluck('name', 'id');
        $explained = array_column($rows, 'client_id');

        return response()->json([
            'suggestion' => $suggestion['client_id'] ? $suggestion + ['name' => $names[$suggestion['client_id']] ?? null] : null,
            'outcomes' => array_map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? 'Profile '.$id, 'agreed' => $id === $suggestion['client_id'],
                'explained' => in_array($id, $explained, true)], $outcomes),
            'feedback' => $rows,
            'reasons' => collect(CollectionMatchFeedbackService::REASONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $receipt)
    {
        $this->enabled();
        $r = $this->access->receipt($request->user(), $receipt);
        $r->load('allocation', 'sale');
        $input = $request->validate(['client_id' => 'required|integer|min:1',
            'reason' => ['nullable', Rule::in(array_keys(CollectionMatchFeedbackService::REASONS))],
            'note' => 'nullable|string|max:500', 'source' => ['required', Rule::in(CollectionMatchFeedbackService::SOURCES)]]);
        $row = $this->feedback->record($r, $request->user(), (int) $input['client_id'], $input['reason'] ?? null, $input['note'] ?? null, $input['source']);

        return response()->json(['feedback' => $this->feedback->forReceipt($r), 'agreed' => $row->agreed], 201);
    }

    public function accuracy(Request $request, CollectionMatchAccuracyService $accuracy)
    {
        $this->enabled();
        app(MarketAuthorizationService::class)->ensureManager($request->user(), 'Only managers can view matching quality.');
        $input = $request->validate(['platform_id' => 'nullable|integer|min:1', 'days' => 'nullable|in:7,14,30,90', 'fresh' => 'nullable|boolean']);
        $accounts = $this->access->accountIdsForView($request->user(), isset($input['platform_id']) ? (int) $input['platform_id'] : null);
        $to = CarbonImmutable::now();
        $from = $to->setTimezone(CollectionMatchAccuracyService::TIMEZONE)->subDays((int) ($input['days'] ?? 30) - 1)->startOfDay();
        $report = $accounts ? $accuracy->report($accounts, $from, $to, (bool) ($input['fresh'] ?? false)) : null;

        return response()->json(['accounts' => count($accounts), 'report' => $report])->header('Cache-Control', 'private, no-store');
    }
}
