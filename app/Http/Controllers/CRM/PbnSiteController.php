<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\RunPbnSeedBatchJob;
use App\Models\PbnSeedBatch;
use App\Models\PbnSeedItem;
use App\Models\PbnSite;
use App\Services\MarketAuthorizationService;
use App\Services\Pbn\PbnOperationsService;
use App\Services\Pbn\PbnSeedPreviewService;
use App\Services\Pbn\PbnSeedProvisioningService;
use App\Services\Pbn\PbnSiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PbnSiteController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly PbnSiteService $siteService,
        private readonly PbnOperationsService $operationsService,
        private readonly PbnSeedPreviewService $previewService,
        private readonly PbnSeedProvisioningService $provisioningService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensurePbnUser($request);

        return response()->json($this->siteService->listFor($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->siteRules());
        $site = $this->siteService->create($validated, $request->user());

        return response()->json([
            'message' => 'PBN site created.',
            'site' => $this->siteService->serialize($site, $request->user()),
        ], 201);
    }

    public function update(Request $request, PbnSite $pbnSite): JsonResponse
    {
        $validated = $request->validate($this->siteRules($pbnSite));
        $site = $this->siteService->update($pbnSite, $validated, $request->user());

        return response()->json([
            'message' => 'PBN site updated.',
            'site' => $this->siteService->serialize($site, $request->user()),
        ]);
    }

    public function testConnection(Request $request, PbnSite $pbnSite): JsonResponse
    {
        $this->marketAuthorizationService->ensureManager(
            $request->user(),
            'Only admin or sub-admin users can test PBN credentials.'
        );

        return response()->json($this->siteService->testReadiness($pbnSite, $request->user()));
    }

    public function locations(Request $request, PbnSite $pbnSite): JsonResponse
    {
        $this->ensurePbnUser($request);

        return response()->json([
            'locations' => $this->siteService->locations($pbnSite),
        ]);
    }

    public function preview(Request $request, PbnSite $pbnSite): JsonResponse
    {
        $this->ensureSeedAllowed($request, $pbnSite, forQueue: false);

        $validated = $request->validate($this->seedRules(requirePreviewToken: false));

        return response()->json($this->previewService->preview($pbnSite, $validated, $request->user()));
    }

    public function storeBatch(Request $request, PbnSite $pbnSite): JsonResponse
    {
        $this->ensureSeedAllowed($request, $pbnSite, forQueue: true);

        $validated = $request->validate($this->seedRules(requirePreviewToken: true));
        $result = $this->previewService->createBatch($pbnSite, $validated, $request->user());

        RunPbnSeedBatchJob::dispatch((int) $result['batch']->id);

        return response()->json([
            'message' => 'PBN seed batch queued.',
            ...$result,
        ], 201);
    }

    public function showBatch(Request $request, PbnSeedBatch $batch): JsonResponse
    {
        $this->ensurePbnUser($request);
        $this->ensureBatchVisible($request, $batch);

        return response()->json($this->previewService->showBatch($batch));
    }

    public function retryBatch(Request $request, PbnSeedBatch $batch): JsonResponse
    {
        $this->ensurePbnUser($request);
        $this->ensureBatchVisible($request, $batch);

        $failed = $batch->items()->where('status', PbnSeedItem::STATUS_FAILED)->count();
        if ($failed < 1) {
            throw new ConflictHttpException('This PBN seed batch has no failed items to retry.');
        }

        $batch->forceFill([
            'status' => PbnSeedBatch::STATUS_QUEUED,
            'completed_at' => null,
        ])->save();

        RunPbnSeedBatchJob::dispatch((int) $batch->id);

        return response()->json([
            'message' => 'Failed PBN seed items queued for retry.',
            ...$this->previewService->showBatch($batch->fresh()),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $this->ensurePbnUser($request);

        return response()->json($this->operationsService->overview($request->user()));
    }

    public function batches(Request $request): JsonResponse
    {
        $this->ensurePbnUser($request);

        $validated = $request->validate($this->listRules());

        return response()->json($this->operationsService->batches($request->user(), $validated));
    }

    public function batch(Request $request, PbnSeedBatch $batch): JsonResponse
    {
        $this->ensurePbnUser($request);

        return response()->json($this->operationsService->batch($request->user(), $batch));
    }

    public function items(Request $request): JsonResponse
    {
        $this->ensurePbnUser($request);

        $validated = $request->validate($this->listRules() + [
            'batch_id' => 'sometimes|nullable|integer|exists:pbn_seed_batches,id',
            'source_platform_id' => 'sometimes|nullable|integer|exists:platforms,id',
        ]);

        return response()->json($this->operationsService->items($request->user(), $validated));
    }

    public function events(Request $request): JsonResponse
    {
        $this->ensurePbnUser($request);

        $validated = $request->validate($this->listRules() + [
            'batch_id' => 'sometimes|nullable|integer|exists:pbn_seed_batches,id',
            'level' => 'sometimes|nullable|in:all,info,warning,error',
        ]);

        return response()->json($this->operationsService->events($request->user(), $validated));
    }

    public function revertPreview(Request $request, PbnSeedBatch $batch): JsonResponse
    {
        $this->ensurePbnUser($request);

        return response()->json($this->operationsService->revertPreview($request->user(), $batch));
    }

    public function revertBatch(Request $request, PbnSeedBatch $batch): JsonResponse
    {
        $this->ensurePbnUser($request);

        $validated = $request->validate([
            'reason' => 'required|string|min:6|max:1000',
        ]);

        return response()->json($this->operationsService->revertBatch(
            $request->user(),
            $batch,
            (string) $validated['reason']
        ));
    }

    private function ensurePbnUser(Request $request): void
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [
                MarketAuthorizationService::ROLE_ADMIN,
                MarketAuthorizationService::ROLE_SUB_ADMIN,
                MarketAuthorizationService::ROLE_SALES,
            ],
            'You do not have permission to access PBN settings.'
        );
    }

    private function ensureSeedAllowed(Request $request, PbnSite $site, bool $forQueue): void
    {
        $this->ensurePbnUser($request);

        if (!$site->is_active) {
            throw new ConflictHttpException('This PBN site is inactive. Activate it before previewing seed candidates.');
        }
        if ($forQueue && $site->last_status !== 'ready') {
            throw new ConflictHttpException('This PBN site is not readiness-approved. Run a passing readiness check before queueing.');
        }
    }

    private function ensureBatchVisible(Request $request, PbnSeedBatch $batch): void
    {
        $sourceIds = $batch->source_platform_ids ?: [];
        foreach ($sourceIds as $sourceId) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $sourceId);
        }
    }

    private function siteRules(?PbnSite $site = null): array
    {
        $domainRule = Rule::unique('pbn_sites', 'domain');
        if ($site) {
            $domainRule->ignore($site->id);
        }

        return [
            'name' => [$site ? 'sometimes' : 'required', 'string', 'max:255'],
            'domain' => [$site ? 'sometimes' : 'required', 'string', 'max:255', $domainRule],
            'default_source_platform_id' => 'sometimes|nullable|integer|exists:platforms,id',
            'source_platform_ids' => 'sometimes|array|max:5',
            'source_platform_ids.*' => 'integer|exists:platforms,id',
            'is_active' => 'sometimes|boolean',
            'country' => 'sometimes|nullable|string|max:255',
            'timezone' => 'sometimes|required|string|max:64',
            'currency_code' => 'sometimes|nullable|string|size:3',
            'phone_prefix' => ['sometimes', 'nullable', 'string', 'max:8', 'regex:/^\d{1,8}$/'],
            'wp_api_url' => 'sometimes|nullable|url|max:255',
            'wp_api_user' => 'sometimes|nullable|string|max:100',
            'wp_api_password' => 'sometimes|nullable|string|max:255',
            'db_host' => 'sometimes|nullable|string|max:255',
            'db_name' => 'sometimes|nullable|string|max:255',
            'db_user' => 'sometimes|nullable|string|max:255',
            'db_pass' => 'sometimes|nullable|string|max:255',
            'db_prefix' => 'sometimes|nullable|string|max:32',
            'copy_policy' => 'sometimes|nullable|array',
            'copy_policy.post_status' => 'sometimes|nullable|in:publish,private,draft,pending',
            'copy_policy.phone' => 'sometimes|nullable|in:copy,strip',
            'copy_policy.media' => 'sometimes|nullable|in:two_stage,none',
            'copy_policy.vip_flags' => 'sometimes|nullable|in:copy,strip',
            'copy_policy.verification' => 'sometimes|nullable|in:copy,strip',
            'copy_policy.seo_fields' => 'sometimes|nullable|in:copy,strip',
            'copy_policy.duplicate_policy' => 'sometimes|nullable|in:skip',
            'copy_policy.update_policy' => 'sometimes|nullable|in:snapshot',
            'reason' => 'nullable|string|max:500',
        ];
    }

    private function seedRules(bool $requirePreviewToken): array
    {
        return [
            'preview_token' => [$requirePreviewToken ? 'required' : 'sometimes', 'string', 'size:64'],
            'source_platform_ids' => 'required|array|min:1|max:5',
            'source_platform_ids.*' => 'integer|exists:platforms,id',
            'target_count' => 'required|integer|min:1|max:200',
            'targets' => 'required|array|min:1|max:40',
            'targets.*.region_id' => 'nullable|integer',
            'targets.*.city_id' => 'nullable|integer',
            'targets.*.region_name' => 'nullable|string|max:160',
            'targets.*.city_name' => 'nullable|string|max:160',
            'targets.*.target_count' => 'required|integer|min:1|max:200',
            'copy_policy' => 'sometimes|nullable|array',
            'selected_client_ids' => 'sometimes|array|max:200',
            'selected_client_ids.*' => 'integer|exists:clients,id',
            'duplicate_acknowledged' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    private function listRules(): array
    {
        return [
            'pbn_site_id' => 'sometimes|nullable|integer|exists:pbn_sites,id',
            'status' => 'sometimes|nullable|string|max:32',
            'q' => 'sometimes|nullable|string|max:160',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:10|max:100',
        ];
    }
}
