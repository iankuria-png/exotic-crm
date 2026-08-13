<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ComplianceEvidenceExport;
use App\Services\Compliance\EvidencePackService;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

class ComplianceController extends Controller
{
    public function __construct(
        private readonly EvidencePackService $evidencePackService,
        private readonly MarketAuthorizationService $marketAuthorizationService,
    ) {}

    public function show(Request $request, Client $client)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $client->platform_id);

        return response()->json($this->evidencePackService->buildPayload($client, $request->user()));
    }

    public function export(Request $request, Client $client)
    {
        $this->marketAuthorizationService->ensureRole($request->user(), ['admin', 'sub_admin'], 'Only admin or sub-admin users can export compliance evidence.');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $client->platform_id);

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        try {
            $export = $this->evidencePackService->generate($client, $request->user(), $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'export_id' => (int) $export->id,
            'download_url' => URL::temporarySignedRoute(
                'api.crm.compliance.evidence-exports.show',
                now()->addMinutes(15),
                ['export' => $export->id]
            ),
            'expires_at' => optional($export->expires_at)->toIso8601String(),
        ], 201);
    }

    public function download(Request $request, ComplianceEvidenceExport $export)
    {
        $this->marketAuthorizationService->ensureRole($request->user(), ['admin', 'sub_admin'], 'Only admin or sub-admin users can download compliance evidence.');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $export->platform_id);

        abort_unless(Storage::disk($export->storage_disk)->exists($export->storage_path), 404, 'Evidence pack not found.');

        return Storage::disk($export->storage_disk)->download(
            $export->storage_path,
            basename($export->storage_path)
        );
    }
}
