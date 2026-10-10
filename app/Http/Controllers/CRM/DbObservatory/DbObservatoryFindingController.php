<?php

namespace App\Http\Controllers\CRM\DbObservatory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CRM\DbObservatory\Concerns\ScopesObservatory;
use App\Models\DbScanFinding;
use App\Models\DbScanFindingEvent;
use App\Models\DbScanObservation;
use App\Models\DbScanRule;
use App\Models\DbScanSuppression;
use App\Models\User;
use App\Services\DbScanner\DbScanAuditWriter;
use App\Services\DbScanner\Engine\FindingRecorder;
use App\Services\DbScanner\Evidence\EvidenceSanitizer;
use App\Services\DbScanner\FindingInterpretation;
use App\Services\DbScanner\ObservatoryPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DbObservatoryFindingController extends Controller
{
    use ScopesObservatory;

    private const EXPORT_CAP = 10000;

    public function __construct(
        private readonly ObservatoryPresenter $presenter,
        private readonly DbScanAuditWriter $audit,
        private readonly FindingRecorder $recorder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureView($request);
        $names = $this->marketNames($this->scopeIds($request));
        $query = $this->filtered($request);

        $groups = null;
        if (in_array($request->string('group_by')->toString(), ['rule', 'market'], true)) {
            $column = $request->string('group_by')->toString() === 'rule' ? 'rule_key' : 'platform_id';
            $groups = (clone $query)->reorder()
                ->selectRaw($column.' AS group_key, COUNT(*) AS findings, COUNT(DISTINCT platform_id) AS markets, '
                    ."SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS critical, "
                    ."SUM(CASE WHEN severity = 'warn' THEN 1 ELSE 0 END) AS warn, "
                    ."SUM(CASE WHEN severity = 'info' THEN 1 ELSE 0 END) AS info, MAX(last_seen_at) AS last_seen_at")
                ->groupBy($column)
                ->orderByDesc('critical')
                ->orderByDesc('findings')
                ->limit(200)
                ->get()
                ->map(fn ($g) => [
                    'key' => (string) $g->group_key,
                    'label' => $column === 'platform_id' ? ($names[$g->group_key] ?? 'Market #'.$g->group_key) : (string) $g->group_key,
                    'findings' => (int) $g->findings,
                    'markets' => (int) $g->markets,
                    'critical' => (int) $g->critical,
                    'warn' => (int) $g->warn,
                    'info' => (int) $g->info,
                    'last_seen_at' => $g->last_seen_at ? date(DATE_ATOM, strtotime((string) $g->last_seen_at)) : null,
                ]);
        }

        $page = (clone $query)->paginate($this->perPage($request));
        $this->presenter->prepare($page->items(), $names);
        $payload = $this->paginated($page, collect($page->items())->map(fn ($f) => $this->presenter->finding($f, $names))->all());
        $payload['groups'] = $groups;
        $payload['families'] = FindingInterpretation::counts($query);
        $payload['selection_sampled'] = (bool) $request->attributes->get('review_selection_sampled', false);
        if ($request->input('group_by') === 'related' && ! $request->filled('review_group')) {
            [$selection, $sampled] = $this->bounded($query);
            $related = FindingInterpretation::groups($selection, $names);
            $groupPage = max(1, $request->integer('page', 1));
            $pageGroups = array_slice($related, ($groupPage - 1) * 10, 10);
            $memberIds = [];
            foreach ($pageGroups as $g) {
                $memberIds = [...$memberIds, ...array_slice($g['member_ids'], 0, 5)];
            }
            $members = $this->scoped(DbScanFinding::query(), $request)->whereIn('id', $memberIds)->get()->keyBy('id');
            $this->presenter->prepare($members, $names);
            $payload['review_groups'] = array_map(function ($g) use ($members, $names) {
                $g['members'] = collect(array_slice($g['member_ids'], 0, 5))->map(fn ($id) => isset($members[$id]) ? $this->presenter->finding($members[$id], $names) : null)->filter()->values()->all();
                unset($g['member_ids']);

                return $g;
            }, $pageGroups);
            $payload['review_meta'] = ['total' => count($related), 'current_page' => $groupPage, 'last_page' => max(1, (int) ceil(count($related) / 10)), 'sampled' => $sampled, 'findings_considered' => $selection->count(), 'selection_cap' => FindingInterpretation::GROUP_CAP];
        }
        $payload['facets'] = [
            'status' => $this->facet($request, 'status'),
            'severity' => $this->facet($request, 'severity'),
            'category' => $this->facet($request, 'category'),
            'confidence' => $this->facet($request, 'confidence'),
            'behavior' => $this->facet($request, 'behavior'),
        ];

        return response()->json($payload);
    }

    public function show(Request $request, int $finding): JsonResponse
    {
        $this->ensureView($request);
        $model = DbScanFinding::query()->findOrFail($finding);
        $this->abortUnlessInScope($request, (int) $model->platform_id);
        $names = $this->marketNames($this->scopeIds($request));
        $rule = DbScanRule::query()->where('key', $model->rule_key)->first();

        $events = DbScanFindingEvent::query()->where('finding_id', $model->id)->latest('id')->limit(50)->get();
        $actors = User::query()->whereIn('id', $events->pluck('actor_id')->filter())->pluck('name', 'id');
        $suppression = $model->suppression_id ? DbScanSuppression::query()->find($model->suppression_id) : null;
        $this->presenter->prepare([$model], $names);

        return response()->json([
            'finding' => $this->presenter->finding($model, $names),
            'rule' => $rule ? [
                'key' => $rule->key,
                'title' => $rule->title,
                'why' => $rule->why,
                'remediation' => $rule->remediation,
                'references' => $rule->references,
                'kind' => $rule->kind,
                'current_version_hash' => substr((string) $rule->definition_hash, 0, 12),
                'allowlistable' => (bool) $rule->allowlistable,
            ] : null,
            'events' => $events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'note' => $e->note,
                'actor' => $e->actor_id ? ($actors[$e->actor_id] ?? 'User #'.$e->actor_id) : ($e->market_run_id ? 'Scanner' : null),
                'run_id' => $e->market_run_id,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'observations' => DbScanObservation::query()->where('finding_id', $model->id)->latest('id')->limit(20)->get()->map(fn ($o) => [
                'run_id' => $o->market_run_id,
                'confidence' => $o->confidence,
                'payload_sha256' => $o->payload_hash,
                'payload_hash_type' => $o->payload_hash_type,
                'rule_version_hash' => substr((string) $o->rule_version_hash, 0, 12),
                'created_at' => $o->created_at?->toIso8601String(),
            ])->values(),
            'suppression' => $suppression ? [
                'id' => $suppression->id,
                'scope_key' => $suppression->scope_key,
                'reason' => $suppression->reason,
                'expires_at' => $suppression->expires_at?->toIso8601String(),
                'revoked_at' => $suppression->revoked_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function update(Request $request, int $finding): JsonResponse
    {
        $this->ensureOperate($request);
        $model = DbScanFinding::query()->findOrFail($finding);
        $this->abortUnlessInScope($request, (int) $model->platform_id);

        if ($request->input('status') === 'resolved') {
            return response()->json(['message' => 'Findings resolve only when a complete, compatible verification scan no longer sees them.'], 422);
        }

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'acknowledged', 'snoozed', 'false_positive'])],
            'snoozed_until' => ['required_if:status,snoozed', 'nullable', 'date', 'after:now', 'before:'.now()->addDays(90)->toDateString()],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('status', 'active')],
            'note' => ['required_if:status,false_positive', 'nullable', 'string', 'max:1000'],
            'record_remediation' => ['sometimes', 'boolean'],
        ]);

        if ($data['record_remediation'] ?? false) {
            $request->validate(['note' => ['required', 'string', 'min:10', 'max:1000'], 'status' => ['prohibited'], 'assigned_to' => ['prohibited'], 'snoozed_until' => ['prohibited']]);
            abort_if(mb_strlen(trim((string) $request->input('note'))) < 10, 422, 'Describe the remediation and its evidence in at least 10 characters.');
        }

        DB::transaction(function () use ($request, $model, $data) {
            $locked = DbScanFinding::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(['status', 'snoozed_until', 'assigned_to', 'note']);
            $from = $locked->status;

            if ($data['record_remediation'] ?? false) {
                abort_unless(in_array($locked->status, DbScanFinding::OPEN_STATUSES, true), 422, 'Record remediation on an open or acknowledged finding. Reopen a triaged finding before recording further work.');
                $note = (new EvidenceSanitizer(1000))->clean(trim($data['note']));
                $this->recorder->event($locked, null, 'remediation_recorded', $from, $from, $note, (int) $request->user()->id);
                $this->audit->record((int) $request->user()->id, 'finding', $locked->id, 'remediation_report', $before, ['status' => $from, 'note' => $note], (int) $locked->platform_id);

                return;
            }

            if (isset($data['status'])) {
                if ($locked->status === 'resolved' && $data['status'] !== 'open') {
                    abort(409, 'A resolved finding can only be reopened.');
                }
                $locked->status = $data['status'];
                $locked->snoozed_until = $data['status'] === 'snoozed' ? $data['snoozed_until'] : null;
                if ($data['status'] === 'open') {
                    $locked->suppression_id = null;
                    $locked->resolved_at = null;
                }
            }
            if (array_key_exists('assigned_to', $data)) {
                $locked->assigned_to = $data['assigned_to'];
            }
            if (array_key_exists('note', $data) && $data['note'] !== null) {
                $locked->note = (new EvidenceSanitizer(1000))->clean($data['note']);
            }
            $locked->save();

            $type = isset($data['status']) && $data['status'] !== $from ? 'status_changed' : (array_key_exists('assigned_to', $data) ? 'assigned' : 'note');
            $this->recorder->event($locked, null, $type, $from, $locked->status, $locked->note, (int) $request->user()->id);
            $this->audit->record((int) $request->user()->id, 'finding', $locked->id, 'triage', $before, $locked->only(['status', 'snoozed_until', 'assigned_to', 'note']), (int) $locked->platform_id);
        });

        $fresh = $model->fresh();
        $names = $this->marketNames($this->scopeIds($request));
        $this->presenter->prepare([$fresh], $names);

        return response()->json($this->presenter->finding($fresh, $names));
    }

    /**
     * Preview (preview=1) or create an exact, expiring suppression. A
     * network-wide suppression additionally requires the affected-market
     * preview to have been acknowledged by the admin.
     */
    public function suppress(Request $request, int $finding): JsonResponse
    {
        $this->ensureConfigure($request);
        $model = DbScanFinding::query()->findOrFail($finding);
        $this->abortUnlessInScope($request, (int) $model->platform_id);
        $rule = DbScanRule::query()->where('key', $model->rule_key)->firstOrFail();

        $data = $request->validate([
            'scope' => ['required', Rule::in(['finding', 'market', 'network'])],
            'match' => ['required', Rule::in(['subject', 'payload'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:365'],
            'preview' => ['sometimes', 'boolean'],
            'acknowledge_preview' => ['sometimes', 'boolean'],
        ]);

        if (! $rule->allowlistable) {
            return response()->json(['message' => 'This rule cannot be suppressed.'], 422);
        }
        $payload = $model->evidence['payload_sha256'] ?? null;
        if ($data['match'] === 'payload' && (! $payload || ($model->evidence['payload_hash_type'] ?? null) !== 'full')) {
            return response()->json(['message' => 'Payload suppression needs a full-value hash; this finding only has a partial or no hash.'], 422);
        }
        if ($model->category === 'malware' && $model->confidence === 'confirmed') {
            return response()->json(['message' => 'Confirmed malware indicators cannot be suppressed; resolve the payload instead.'], 422);
        }

        $affected = DbScanFinding::query()
            ->where('rule_key', $model->rule_key)
            ->whereIn('status', DbScanFinding::UNRESOLVED_STATUSES)
            ->when($data['scope'] !== 'network', fn ($q) => $q->where('platform_id', $model->platform_id))
            ->when($data['match'] === 'subject', fn ($q) => $q->where('subject_hash', $model->subject_hash), fn ($q) => $q->where('evidence->payload_sha256', $payload));
        $affectedMarkets = (clone $affected)->distinct()->pluck('platform_id')->all();
        $preview = [
            'findings' => (clone $affected)->count(),
            'markets' => count($affectedMarkets),
            'market_names' => array_values($this->marketNames($affectedMarkets)),
        ];

        if (($data['preview'] ?? false) || ($data['scope'] === 'network' && ! ($data['acknowledge_preview'] ?? false))) {
            return response()->json(['preview' => $preview, 'requires_acknowledgement' => $data['scope'] === 'network']);
        }

        $suppression = DB::transaction(function () use ($request, $model, $data, $payload, $affected) {
            $suppression = DbScanSuppression::query()->create([
                'rule_key' => $model->rule_key,
                'scope_key' => $data['scope'] === 'network' ? 'network' : 'platform:'.$model->platform_id,
                'platform_id' => $data['scope'] === 'network' ? null : $model->platform_id,
                'subject_hash' => $data['match'] === 'subject' ? $model->subject_hash : null,
                'value_fingerprint' => $data['match'] === 'payload' ? $payload : null,
                'rule_version_hash' => $model->rule_version_hash,
                'reason' => (new EvidenceSanitizer(500))->clean($data['reason']),
                'actor_id' => $request->user()->id,
                'expires_at' => now()->addDays((int) $data['expires_in_days']),
            ]);

            foreach ((clone $affected)->lockForUpdate()->get() as $finding) {
                $from = $finding->status;
                $finding->forceFill(['status' => 'allowlisted', 'suppression_id' => $suppression->id, 'snoozed_until' => null])->save();
                $this->recorder->event($finding, null, 'status_changed', $from, 'allowlisted', 'Suppressed: '.$suppression->reason, (int) $request->user()->id);
            }

            $this->audit->record((int) $request->user()->id, 'suppression', $suppression->id, 'create', null, $suppression->only(['rule_key', 'scope_key', 'subject_hash', 'value_fingerprint', 'reason', 'expires_at']), $suppression->platform_id);

            return $suppression;
        });

        return response()->json(['suppression_id' => $suppression->id, 'preview' => $preview], 201);
    }

    public function revokeSuppression(Request $request, int $suppression): JsonResponse
    {
        $this->ensureConfigure($request);
        $model = DbScanSuppression::query()->findOrFail($suppression);
        if ($model->platform_id) {
            $this->abortUnlessInScope($request, (int) $model->platform_id);
        }

        DB::transaction(function () use ($request, $model) {
            $model->forceFill(['revoked_at' => now(), 'revoked_by' => $request->user()->id])->save();
            foreach (DbScanFinding::query()->where('suppression_id', $model->id)->where('status', 'allowlisted')->lockForUpdate()->get() as $finding) {
                $finding->forceFill(['status' => 'open', 'suppression_id' => null])->save();
                $this->recorder->event($finding, null, 'reopened', 'allowlisted', 'open', 'Suppression revoked.', (int) $request->user()->id);
            }
            $this->audit->record((int) $request->user()->id, 'suppression', $model->id, 'revoke', ['revoked_at' => null], ['revoked_at' => now()->toIso8601String()], $model->platform_id);
        });

        return response()->json(['revoked' => true]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->ensureView($request);
        $names = $this->marketNames($this->scopeIds($request));
        $query = $this->filtered($request);
        $total = (clone $query)->count();
        $truncated = $total > self::EXPORT_CAP;

        $response = new StreamedResponse(function () use ($query, $names) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'market', 'rule', 'category', 'behavior', 'severity', 'confidence', 'status', 'title', 'table', 'row_id', 'field', 'item', 'first_seen_at', 'last_seen_at', 'occurrences', 'excerpt', 'payload_sha256', 'pack_version']);
            $written = 0;
            foreach ((clone $query)->limit(self::EXPORT_CAP)->cursor() as $f) {
                $row = [
                    $f->id, $names[$f->platform_id] ?? $f->platform_id, $f->rule_key, $f->category, $f->behavior, $f->severity, $f->confidence,
                    $f->status, $f->title, $f->subject['table'] ?? '', $f->subject['row_id'] ?? '', $f->subject['field'] ?? '', $f->subject['item'] ?? '',
                    $f->first_seen_at?->toIso8601String(), $f->last_seen_at?->toIso8601String(), $f->occurrences,
                    (string) (($f->evidence['excerpts'] ?? [])[0] ?? ''), $f->evidence['payload_sha256'] ?? '', $f->pack.' '.$f->pack_version,
                ];
                fputcsv($out, array_map(fn ($v) => EvidenceSanitizer::csvSafe($v), $row));
                $written++;
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="db-observatory-findings-'.now()->format('Ymd-His').'.csv"');
        $response->headers->set('X-Export-Total', (string) $total);
        $response->headers->set('X-Export-Truncated', $truncated ? '1' : '0');
        $response->headers->set('X-Selection-Sampled', $request->attributes->get('review_selection_sampled', false) ? '1' : '0');
        $response->headers->set('Access-Control-Expose-Headers', 'X-Export-Total, X-Export-Truncated, X-Selection-Sampled, Content-Disposition');

        return $response;
    }

    private function filtered(Request $request): Builder
    {
        $request->validate([
            'status' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', 'string', 'max:120'],
            'confidence' => ['nullable', 'string', 'max:60'],
            'behavior' => ['nullable', 'string', 'max:200'],
            'definition_hash' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'rule_key' => ['nullable', 'string', 'max:100'],
            'identity' => ['nullable', 'string', 'max:200'],
            'platform_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
            'run_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::in(['signature', 'ioc_list', 'schema_object', 'threshold', 'drift', 'structured'])],
            'family' => ['nullable', Rule::in(array_keys(FindingInterpretation::FAMILIES))],
            'workflow' => ['nullable', Rule::in(['needs_follow_up', 'awaiting_verification'])],
            'review_group' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);

        if ($request->filled('platform_id')) {
            $this->abortUnlessInScope($request, $request->integer('platform_id'));
        }

        $list = fn (string $key) => array_values(array_filter(array_map('trim', explode(',', (string) $request->string($key)))));
        $statuses = $request->filled('status') ? $list('status') : null;
        if ($statuses === ['unresolved']) {
            $statuses = DbScanFinding::UNRESOLVED_STATUSES;
        } elseif ($statuses === ['active']) {
            $statuses = DbScanFinding::OPEN_STATUSES;
        } elseif ($statuses === ['suppressed']) {
            $statuses = DbScanFinding::SUPPRESSED_STATUSES;
        }

        $query = $this->scoped(DbScanFinding::query(), $request)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($request->filled('severity'), fn ($q) => $q->whereIn('severity', $list('severity')))
            ->when($request->filled('category'), fn ($q) => $q->whereIn('category', $list('category')))
            ->when($request->filled('confidence'), fn ($q) => $q->whereIn('confidence', $list('confidence')))
            ->when($request->filled('behavior'), fn ($q) => $q->whereIn('behavior', $list('behavior')))
            ->when($request->filled('family'), fn ($q) => $q->whereRaw('('.FindingInterpretation::familySql().') = ?', [$request->input('family')]))
            ->when($request->filled('workflow'), fn ($q) => FindingInterpretation::filterWorkflow($q, $request->input('workflow')))
            ->when($request->filled('identity'), function ($q) use ($request) {
                $q->where(function ($w) use ($request) {
                    foreach (['evidence->details->plugin', 'evidence->details->name', 'evidence->details->login', 'evidence->details->role', 'evidence->details->option_name', 'subject->label', 'title'] as $field) {
                        $w->orWhere($field, $request->input('identity'));
                    }
                });
            })
            ->when($request->filled('definition_hash'), fn ($q) => $q->where('evidence->details->definition_hash', $request->input('definition_hash')))
            ->when($request->filled('rule_key'), fn ($q) => $q->where('rule_key', $request->string('rule_key')))
            ->when($request->filled('kind'), fn ($q) => $q->whereIn('rule_key', DbScanRule::query()->where('kind', $request->string('kind'))->select('key')))
            ->when($request->filled('platform_id'), fn ($q) => $q->where('platform_id', $request->integer('platform_id')))
            ->when($request->filled('run_id'), fn ($q) => $q->whereIn('id', DbScanObservation::query()->where('market_run_id', $request->integer('run_id'))->select('finding_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $request->string('q')).'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('rule_key', 'like', $term));
            })
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');

        if ($request->filled('review_group')) {
            [$selection, $sampled] = $this->bounded($query);
            $ids = $selection->filter(fn ($f) => hash_equals(FindingInterpretation::groupKey($f), $request->input('review_group')))->pluck('id');
            $request->attributes->set('review_selection_sampled', $sampled);
            $query->whereIn('id', $ids);
        }

        return $query;
    }

    private function bounded(Builder $query): array
    {
        $rows = (clone $query)->limit(FindingInterpretation::GROUP_CAP + 1)->get([
            'id', 'platform_id', 'rule_key', 'subject_hash', 'title', 'severity', 'category', 'confidence', 'behavior',
            'evidence->details as grouping_details', 'subject->label as grouping_label',
        ]);
        $sampled = $rows->count() > FindingInterpretation::GROUP_CAP;
        $selection = $rows->take(FindingInterpretation::GROUP_CAP);
        foreach ($selection as $f) {
            $f->evidence = ['details' => json_decode((string) $f->grouping_details, true) ?: []];
            $f->subject = ['label' => $f->grouping_label];
        }

        return [$selection, $sampled];
    }

    private function facet(Request $request, string $column): array
    {
        return $this->scoped(DbScanFinding::query(), $request)
            ->when($column !== 'status', fn ($q) => $q->whereIn('status', DbScanFinding::UNRESOLVED_STATUSES))
            ->whereNotNull($column)
            ->selectRaw($column.' AS k, COUNT(*) AS n')
            ->groupBy($column)
            ->pluck('n', 'k')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
