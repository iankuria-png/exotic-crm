<?php

namespace App\Http\Controllers\CRM\DbObservatory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CRM\DbObservatory\Concerns\ScopesObservatory;
use App\Models\DbScanConnection;
use App\Models\DbScanMarketRun;
use App\Models\DbScanRule;
use App\Models\Platform;
use App\Services\DbScanner\DbScanAuditWriter;
use App\Services\DbScanner\Engine\InvalidTransitionException;
use App\Services\DbScanner\Engine\LoadOverride;
use App\Services\DbScanner\Engine\MarketBusyException;
use App\Services\DbScanner\Engine\PassController;
use App\Services\DbScanner\Engine\ScannerGate;
use App\Services\DbScanner\ObservatoryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Scan now, pause, resume, stop and rule tests.
 */
class DbObservatoryPassController extends Controller
{
    use ScopesObservatory;

    public function __construct(
        private readonly PassController $passes,
        private readonly ScannerGate $gate,
        private readonly DbScanAuditWriter $audit,
        private readonly ObservatoryPresenter $presenter,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->ensureOperate($request);
        $data = $request->validate([
            'profile' => ['required', Rule::in(['quick', 'standard', 'deep'])],
            'markets' => ['required'],
            'markets.*' => ['integer'],
            'rules' => ['nullable', 'array', 'max:60'],
            'rules.*' => ['string', Rule::exists('db_scan_rules', 'key')->where('retired', false)],
            'override_reason' => ['nullable', 'string', 'min:10', 'max:500'],
            'verbose' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $eligible = DbScanConnection::query()->where('enabled', true)->get()->filter(fn ($c) => $c->preflightValid())->pluck('platform_id')->map(fn ($id) => (int) $id)->all();
        $requested = $data['markets'] === 'all' ? $eligible : array_values(array_unique(array_map('intval', (array) $data['markets'])));
        if ($requested === []) {
            return response()->json(['message' => 'No market has enabled scanner credentials with a passing preflight.'], 423);
        }

        $override = null;
        if (isset($data['override_reason'])) {
            if (! is_array($data['markets']) || count($requested) !== 1) {
                return response()->json(['message' => 'A load override requires exactly one selected market.'], 422);
            }
            $override = LoadOverride::issue($requested[0], (int) $request->user()->id, 'scan', $data['override_reason']);
        }

        $blocked = [];
        foreach (Platform::query()->whereIn('id', $requested)->get() as $platform) {
            $reason = $this->gate->check($platform, DbScanConnection::query()->where('platform_id', $platform->id)->first(), 'scan', $override);
            if ($reason !== null) {
                $blocked[] = ['platform_id' => $platform->id, 'market' => $platform->name, 'reason' => $reason, 'message' => ScannerGate::describe($reason)];
            }
        }
        if (count($requested) !== Platform::query()->whereIn('id', $requested)->count()) {
            abort(404);
        }
        if ($blocked !== []) {
            return response()->json(['message' => 'Scanner admission is blocked for '.count($blocked).' market(s).', 'blocked' => $blocked, 'load' => $this->gate->loadStatus()], 423);
        }

        try {
            $pass = $this->passes->start(
                $requested,
                $data['profile'],
                'manual',
                (int) $request->user()->id,
                ! empty($data['rules']) ? array_values($data['rules']) : null,
                (bool) ($data['verbose'] ?? false),
                $data['idempotency_key'] ?? null,
                loadOverride: $override,
            );
        } catch (MarketBusyException $e) {
            return response()->json([
                'message' => 'A selected market already has an active scan.',
                'busy' => array_values($this->marketNames($e->platformIds)),
            ], 409);
        }

        $this->audit->record((int) $request->user()->id, 'pass', $pass->id, 'start', null, [
            'profile' => $data['profile'], 'markets' => count($requested), 'rules' => $data['rules'] ?? null,
        ]);

        return response()->json(['pass_id' => $pass->id, 'status' => $pass->status, 'markets' => count($requested)], 202);
    }

    public function pause(Request $request, int $pass): JsonResponse
    {
        return $this->transition($request, $pass, 'pause');
    }

    public function resume(Request $request, int $pass): JsonResponse
    {
        return $this->transition($request, $pass, 'resume');
    }

    public function stop(Request $request, int $pass): JsonResponse
    {
        return $this->transition($request, $pass, 'stop');
    }

    /**
     * Test one rule on one market: same admission, leases and limits; writes
     * samples to the run, never canonical findings.
     */
    public function testRule(Request $request, string $rule): JsonResponse
    {
        $this->ensureConfigure($request);
        $model = DbScanRule::query()->where('key', $rule)->where('retired', false)->firstOrFail();
        $data = $request->validate(['platform_id' => ['required', 'integer', Rule::exists('platforms', 'id')]]);
        $platform = Platform::query()->findOrFail($data['platform_id']);

        $reason = $this->gate->check($platform, DbScanConnection::query()->where('platform_id', $platform->id)->first(), 'scan');
        if ($reason !== null) {
            return response()->json(['message' => ScannerGate::describe($reason), 'reason' => $reason], 423);
        }

        $profiles = (array) $model->profiles;
        $profile = in_array('quick', $profiles, true) ? 'quick' : (in_array('standard', $profiles, true) ? 'standard' : 'deep');

        try {
            $pass = $this->passes->start([$platform->id], $profile, 'manual', (int) $request->user()->id, [$model->key], false, null, null, false, 'test');
        } catch (MarketBusyException) {
            return response()->json(['message' => 'This market already has an active scan.'], 409);
        }
        $run = DbScanMarketRun::query()->where('pass_id', $pass->id)->firstOrFail();
        $this->audit->record((int) $request->user()->id, 'rule', $model->key, 'test', null, ['platform_id' => $platform->id, 'run_id' => $run->id], (int) $platform->id);

        return response()->json(['run_id' => $run->id, 'pass_id' => $pass->id, 'status' => 'queued', 'mode' => 'test'], 202);
    }

    private function transition(Request $request, int $pass, string $action): JsonResponse
    {
        $this->ensureOperate($request);
        $model = $this->scopedPass($request, $pass);

        try {
            $updated = match ($action) {
                'pause' => $this->passes->pause($model),
                'resume' => $this->passes->resume($model),
                'stop' => $this->passes->stop($model, (int) $request->user()->id),
            };
        } catch (InvalidTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $this->audit->record((int) $request->user()->id, 'pass', $model->id, $action, ['status' => $model->status], ['status' => $updated->status]);

        return response()->json(['pass_id' => $updated->id, 'status' => $updated->status]);
    }
}
