<?php

namespace App\Http\Controllers\CRM\Kyc;

use App\Http\Controllers\Controller;
use App\Services\Kyc\KycSettingsService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SettingsController extends Controller
{
    public function __construct(private readonly KycSettingsService $settingsService) {}

    public function show()
    {
        $settings = $this->settingsService->get();

        return response()->json([
            'settings' => array_merge($settings->toArray(), ['ai_review' => $this->settingsService->aiSettings()]),
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
            'private_content_upload_policy_default' => 'sometimes|in:off,prompt,require_approved',
            'private_content_upload_policy_per_platform' => 'sometimes|array',
            'private_content_upload_policy_per_platform.*' => 'in:off,prompt,require_approved',
            'ai_review' => 'sometimes|array:mode,mode_per_platform,paused,reject_requires_second_opinion_per_platform,auto_reject_enabled,auto_reject_enabled_per_platform,reject_requires_second_opinion,ladder,second_opinion_model,approve_threshold,reject_threshold,daily_cap_usd,qa_sample_pct,document_types_per_platform',
            'ai_review.paused' => 'sometimes|boolean',
            'ai_review.reject_requires_second_opinion_per_platform' => 'sometimes|array',
            'ai_review.reject_requires_second_opinion_per_platform.*' => 'boolean',
            'ai_review.mode' => 'required_with:ai_review|in:off,shadow,advisory,auto_approve,full_auto',
            'ai_review.mode_per_platform' => 'sometimes|array',
            'ai_review.mode_per_platform.*' => 'in:off,shadow,advisory,auto_approve,full_auto',
            'ai_review.auto_reject_enabled' => 'sometimes|boolean',
            'ai_review.auto_reject_enabled_per_platform' => 'sometimes|array',
            'ai_review.auto_reject_enabled_per_platform.*' => 'boolean',
            'ai_review.reject_requires_second_opinion' => 'sometimes|boolean',
            'ai_review.ladder' => 'required_with:ai_review|array|min:1|max:2',
            'ai_review.ladder.*' => 'string|max:150|regex:/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.:-]+$/',
            'ai_review.second_opinion_model' => 'required_with:ai_review|string|max:150|regex:/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.:-]+$/',
            'ai_review.approve_threshold' => 'sometimes|numeric|min:0.9|max:1',
            'ai_review.reject_threshold' => 'sometimes|numeric|min:0.95|max:1',
            'ai_review.daily_cap_usd' => 'sometimes|numeric|min:0|max:1000',
            'ai_review.qa_sample_pct' => 'sometimes|integer|min:0|max:100',
            'ai_review.document_types_per_platform' => 'sometimes|array',
            'ai_review.document_types_per_platform.*' => 'array|min:1|max:4',
            'ai_review.document_types_per_platform.*.*' => 'in:national_id,passport,driving_licence,alien_id',
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

        if ($request->user()->role === 'sub_admin') {
            $current = $this->settingsService->get();
            abort_if(isset($validated['private_content_upload_policy_default']) && $validated['private_content_upload_policy_default'] !== $current->private_content_upload_policy_default,403,'Only an admin can change the global private-content KYC policy.');
            $old = $current->private_content_upload_policy_per_platform ?? [];
            if (isset($validated['private_content_upload_policy_per_platform'])) {
                $new = $validated['private_content_upload_policy_per_platform'];
                foreach (array_unique(array_merge(array_keys($old),array_keys($new))) as $id) {
                    if (($old[$id] ?? null) !== ($new[$id] ?? null)) app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($request->user(),(int)$id);
                }
            }
        }
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
