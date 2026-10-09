<?php

namespace App\Http\Controllers\CRM\DbObservatory;

use App\Http\Controllers\Controller;
use App\Models\DbContainmentCampaign;
use App\Models\DbContainmentFileObservation;
use App\Models\DbContainmentMarket;
use App\Models\DbContainmentOperation;
use App\Models\DbScanFinding;
use App\Models\Platform;
use App\Services\DbContainment\ActionCatalog;
use App\Services\DbContainment\BackupVault;
use App\Services\DbContainment\CampaignService;
use App\Services\DbContainment\ContainmentException;
use App\Services\DbContainment\ContainmentPolicy;
use App\Services\DbContainment\ContainmentService;
use App\Services\DbContainment\FilesystemContainment;
use Illuminate\Http\Request;

class DbContainmentController extends Controller
{
    public function __construct(private readonly ContainmentPolicy $policy, private readonly ContainmentService $service) {}

    private function respond(Request $request, callable $work)
    {
        try {
            $this->policy->authorize($request->user());

            return response()->json($work());
        } catch (ContainmentException $e) {
            return response()->json(['message' => str_replace('_', ' ', $e->reason), 'reason' => $e->reason], $e->getCode() >= 400 ? $e->getCode() : 409);
        }
    }

    private function key(Request $request): string
    {
        $data = $request->validate(['request_key' => ['required', 'uuid']]);

        return $data['request_key'];
    }

    public function availability(Request $request, int $finding)
    {
        return $this->respond($request, function () use ($finding) {
            $f = DbScanFinding::query()->findOrFail($finding);
            $market = DbContainmentMarket::query()->find($f->platform_id);
            $enabled = (bool) config('db_containment.enabled') && (bool) $market?->enabled;

            return ['enabled' => $enabled, 'reason' => $enabled ? null : 'Containment is Off. Provision and verify the canary adapters before enabling this market.', 'actions' => app(ActionCatalog::class)->offered($f), 'operations' => DbContainmentOperation::query()->whereIn('id', \Illuminate\Support\Facades\DB::table('db_containment_operation_findings')->where('finding_id', $f->id)->select('operation_id'))->latest()->limit(20)->get()];
        });
    }

    public function preview(Request $request, int $finding)
    {
        return $this->respond($request, function () use ($request, $finding) {
            $data = $request->validate(['actions' => ['required', 'array', 'min:1', 'max:6'], 'actions.*' => ['required', 'string']]);

            return ['operation' => $this->service->preview($request->user(), DbScanFinding::query()->findOrFail($finding), $data['actions'], $this->key($request))];
        });
    }

    public function index(Request $request)
    {
        return $this->respond($request, fn () => ['operations' => DbContainmentOperation::query()->when($request->integer('platform_id'), fn ($q) => $q->where('platform_id', $request->integer('platform_id')))->latest()->limit(100)->get(), 'markets' => DbContainmentMarket::query()->get()->map(fn ($m) => ['platform_id' => $m->platform_id, 'enabled' => $m->enabled, 'filesystem_enabled' => $m->filesystem_enabled, 'quarantine_enabled' => $m->quarantine_enabled])]);
    }

    public function show(Request $request, string $operation)
    {
        return $this->respond($request, fn () => ['operation' => DbContainmentOperation::query()->findOrFail($operation)]);
    }

    public function confirm(Request $request, string $operation)
    {
        return $this->respond($request, function () use ($request, $operation) {
            $data = $request->validate(['confirmation' => ['required', 'string', 'max:200'], 'privilege_confirmation' => ['nullable', 'string', 'max:200'], 'preview_digest' => ['required', 'string', 'size:64']]);

            return ['operation' => $this->service->confirm($request->user(), DbContainmentOperation::query()->findOrFail($operation), $data['confirmation'], $data['privilege_confirmation'] ?? null, $data['preview_digest'])];
        });
    }

    public function restorePreview(Request $request, string $operation)
    {
        return $this->respond($request, fn () => ['operation' => $this->service->restorePreview($request->user(), DbContainmentOperation::query()->findOrFail($operation), $this->key($request))]);
    }

    public function cancel(Request $request, string $operation)
    {
        return $this->respond($request, fn () => ['operation' => $this->service->cancel($request->user(), DbContainmentOperation::query()->findOrFail($operation))]);
    }

    public function retryVerification(Request $request, string $operation)
    {
        return $this->respond($request, function () use ($operation) {
            $op = DbContainmentOperation::query()->findOrFail($operation);
            if (! in_array($op->status, ['cache_pending', 'outcome_unknown', 'recovery_pending', 'committed'], true)) {
                throw new ContainmentException('no_recovery_required');
            }
            \App\Jobs\DbContainment\ContainmentJob::dispatch($op->id)->onQueue(config('db_containment.queue'));

            return ['operation' => $op, 'message' => 'Recovery queued; unknown mutations will not be replayed.'];
        });
    }

    public function purge(Request $request, string $operation)
    {
        return $this->respond($request, function () use ($request, $operation) {
            if ($request->input('confirmation') !== 'PURGE '.$operation) {
                throw new ContainmentException('purge_confirmation_required');
            }
            app(BackupVault::class)->purge(DbContainmentOperation::query()->findOrFail($operation));

            return ['purged' => true];
        });
    }

    public function campaignPreview(Request $request)
    {
        return $this->respond($request, function () use ($request) {
            $d = $request->validate(['targets' => ['required', 'array', 'min:1', 'max:50'], 'targets.*.finding_id' => ['required', 'integer'], 'targets.*.actions' => ['required', 'array', 'min:1']]);

            return app(CampaignService::class)->present(app(CampaignService::class)->preview($request->user(), $d['targets'], $this->key($request)));
        });
    }

    public function campaignShow(Request $request, string $campaign)
    {
        return $this->respond($request, fn () => app(CampaignService::class)->present(DbContainmentCampaign::query()->findOrFail($campaign)));
    }

    public function campaignConfirm(Request $request, string $campaign)
    {
        return $this->respond($request, function () use ($request, $campaign) {
            $d = $request->validate(['confirmation' => ['required', 'string'], 'preview_digest' => ['required', 'string', 'size:64'], 'privilege_confirmations' => ['sometimes', 'array']]);
            $c = app(CampaignService::class)->confirm($request->user(), DbContainmentCampaign::query()->findOrFail($campaign), $d['confirmation'], $d['preview_digest'], $d['privilege_confirmations'] ?? []);

            return app(CampaignService::class)->present($c);
        });
    }

    public function campaignCancel(Request $request, string $campaign)
    {
        return $this->respond($request, fn () => app(CampaignService::class)->present(app(CampaignService::class)->cancel($request->user(), DbContainmentCampaign::query()->findOrFail($campaign))));
    }

    public function staffPreview(Request $request, int $platform)
    {
        return $this->respond($request, fn () => ['operation' => $this->service->staffPreview($request->user(), Platform::query()->findOrFail($platform), $this->key($request))]);
    }

    public function diagnose(Request $request, int $platform)
    {
        return $this->respond($request, fn () => app(FilesystemContainment::class)->diagnose($request->user(), Platform::query()->findOrFail($platform)));
    }

    public function files(Request $request, int $platform)
    {
        return $this->respond($request, fn () => ['observations' => DbContainmentFileObservation::query()->where('platform_id', $platform)->latest()->limit(100)->get()]);
    }

    public function quarantinePreview(Request $request, string $observation)
    {
        return $this->respond($request, fn () => ['operation' => app(FilesystemContainment::class)->preview($request->user(), DbContainmentFileObservation::query()->findOrFail($observation), $this->key($request))]);
    }
}
