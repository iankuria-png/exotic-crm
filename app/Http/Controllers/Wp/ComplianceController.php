<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\ContentComplianceDeclaration;
use App\Services\Compliance\ContentDeclarationService;
use App\Services\Compliance\CreatorAgreementService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ComplianceController extends Controller
{
    public function __construct(
        private readonly CreatorAgreementService $creatorAgreementService,
        private readonly ContentDeclarationService $contentDeclarationService,
    ) {}

    public function currentAgreement()
    {
        $agreement = $this->creatorAgreementService->currentAgreement();

        if (! $agreement) {
            return response()->json([
                'message' => 'No creator agreement version has been published.',
            ], 404);
        }

        return response()->json([
            'version_key' => $agreement->version_key,
            'title' => $agreement->title,
            'body_html' => $agreement->body_html,
            'body_sha256' => $agreement->body_sha256,
            'source_url' => $agreement->source_url,
            'published_at' => optional($agreement->published_at)->toIso8601String(),
        ]);
    }

    public function storeAgreementAcceptance(Request $request)
    {
        $platform = $request->attributes->get('platform');

        try {
            $acceptance = $this->creatorAgreementService->recordAcceptance($request->all(), $platform);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'acceptance_id' => (int) $acceptance->id,
            'client_id' => $acceptance->client_id ? (int) $acceptance->client_id : null,
            'accepted_at' => optional($acceptance->accepted_at)->toIso8601String(),
            'version_key' => $acceptance->version?->version_key,
        ], 201);
    }

    public function storeContentDeclaration(Request $request)
    {
        $platform = $request->attributes->get('platform');

        try {
            $declaration = $this->contentDeclarationService->recordFromWp($request->all(), $platform);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $status = $declaration->status === ContentComplianceDeclaration::STATUS_BLOCKED_PENDING_RELEASE ? 422 : 201;

        return response()->json([
            'success' => $status === 201,
            'declaration_id' => (int) $declaration->id,
            'client_id' => $declaration->client_id ? (int) $declaration->client_id : null,
            'status' => $declaration->status,
            'participant_status' => $declaration->participant_status,
            'message' => $status === 201
                ? 'Content declaration accepted.'
                : 'Uploads with other people require approved model releases before publication.',
        ], $status);
    }
}
