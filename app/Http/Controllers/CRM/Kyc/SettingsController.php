<?php

namespace App\Http\Controllers\CRM\Kyc;

use App\Http\Controllers\Controller;
use App\Services\Kyc\KycSettingsService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SettingsController extends Controller
{
    public function __construct(private readonly KycSettingsService $settingsService)
    {
    }

    public function show()
    {
        $settings = $this->settingsService->get();

        return response()->json([
            'settings' => $settings,
            'total_blob_bytes' => $this->settingsService->totalBlobBytes(),
            's3_health' => $settings->active_storage_driver === 's3'
                ? $this->settingsService->probeS3Connectivity($settings->toArray())
                : ['ok' => false, 'message' => 'S3 not active'],
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(($request->user()->role ?? '') === 'admin' || ($request->user()->role ?? '') === 'sub_admin', 403, 'Unauthorized');

        $validated = $request->validate([
            'enabled_platform_ids' => 'nullable|array',
            'enabled_platform_ids.*' => 'integer|exists:platforms,id',
            'required_document_kinds' => 'nullable|array',
            'required_document_kinds.*' => 'in:id_front,id_back,selfie',
            'max_doc_bytes' => 'nullable|integer|min:1',
            'reject_reason_options' => 'nullable|array',
            'search_boost_enabled' => 'nullable|boolean',
            'active_storage_driver' => 'nullable|in:db,s3',
            's3_bucket' => 'nullable|string|max:255',
            's3_region' => 'nullable|string|max:255',
            's3_kms_key_arn' => 'nullable|string',
            's3_endpoint_override' => 'nullable|string|max:255',
            'exempt_plan_keys' => 'nullable|array',
            'exempt_plan_keys.*' => 'string|max:100',
            'grace_days_default' => 'nullable|integer|min:0',
            'grace_days_per_platform' => 'nullable|array',
            'email_warning_days' => 'nullable|array',
            'escalation_rule_per_platform' => 'nullable|array',
            'reverify_interval_days' => 'nullable|integer|min:1',
            'reverify_auto_sweep_enabled' => 'nullable|boolean',
            'reverify_dispatch_pace_seconds' => 'nullable|integer|min:1',
            'fanout_queue_concurrency' => 'nullable|integer|min:1',
            'reviewer_notification_channels' => 'nullable|array',
            'audit_retention_days' => 'nullable|integer|min:1',
        ]);

        try {
            return response()->json([
                'settings' => $this->settingsService->update($validated, $request->user()),
                'total_blob_bytes' => $this->settingsService->totalBlobBytes(),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function testS3Connectivity(Request $request)
    {
        abort_unless(($request->user()->role ?? '') === 'admin' || ($request->user()->role ?? '') === 'sub_admin', 403, 'Unauthorized');

        return response()->json($this->settingsService->probeS3Connectivity($request->all()));
    }
}
