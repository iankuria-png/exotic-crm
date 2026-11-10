<?php

namespace App\Http\Controllers\CRM;

use App\Exceptions\BannerAdRemoteException;
use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\BannerAdService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BannerAdController extends Controller
{
    private const ROLES = [
        MarketAuthorizationService::ROLE_ADMIN,
        MarketAuthorizationService::ROLE_SUB_ADMIN,
        MarketAuthorizationService::ROLE_SALES,
        MarketAuthorizationService::ROLE_FIELD_SALES,
        MarketAuthorizationService::ROLE_MARKETING,
    ];

    public function __construct(
        private readonly BannerAdService $bannerAds,
        private readonly MarketAuthorizationService $marketAuthorization,
        private readonly AuditService $auditService,
    ) {}

    public function markets(Request $request): JsonResponse
    {
        $this->ensureBannerAdRole($request);

        $validated = $request->validate([
            'platform_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->bannerAds->marketsForUser($request->user(), isset($validated['platform_id']) ? (int) $validated['platform_id'] : null),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureBannerAdRole($request);

        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'status' => ['nullable', Rule::in(['active', 'scheduled', 'paused', 'expired'])],
            'category' => ['nullable', Rule::in(['homepage', 'profile_location'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->remoteResponse(fn () => response()->json(
            $this->bannerAds->list($this->authorizedPlatform($request, (int) $validated['platform_id']), $validated)
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureBannerAdRole($request);

        $validated = $this->validateCampaignPayload($request);
        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);

        return $this->remoteResponse(function () use ($platform, $request, $validated) {
            $payload = $this->wordpressPayload($validated);
            $item = $this->bannerAds->create($platform, $payload);
            $this->audit($request, $platform, CrmAuditAction::BANNER_AD_CREATE, (int) $item['id'], null, $item);

            return response()->json([
                'item' => $item,
                'message' => 'Banner ad created.',
            ], 201);
        });
    }

    public function show(Request $request, int $campaignId): JsonResponse
    {
        $this->ensureBannerAdRole($request);
        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
        ]);

        return $this->remoteResponse(fn () => response()->json([
            'item' => $this->bannerAds->get($this->authorizedPlatform($request, (int) $validated['platform_id']), $campaignId),
        ]));
    }

    public function update(Request $request, int $campaignId): JsonResponse
    {
        $this->ensureBannerAdRole($request);

        $validated = $this->validateCampaignPayload($request, partial: true);
        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);

        return $this->remoteResponse(function () use ($platform, $request, $validated, $campaignId) {
            $before = $this->tryFetchCampaign($platform, $campaignId);
            $item = $this->bannerAds->update($platform, $campaignId, $this->wordpressPayload($validated));
            $this->audit($request, $platform, CrmAuditAction::BANNER_AD_UPDATE, $campaignId, $before, $item);

            return response()->json([
                'item' => $item,
                'message' => 'Banner ad updated.',
            ]);
        });
    }

    public function status(Request $request, int $campaignId): JsonResponse
    {
        $this->ensureBannerAdRole($request);

        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'status' => ['required', Rule::in(['active', 'scheduled', 'paused', 'expired'])],
            'start_date' => ['nullable', 'date_format:Y-m-d H:i:s', 'required_if:status,scheduled'],
            'end_date' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:start_date'],
        ]);

        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);

        return $this->remoteResponse(function () use ($platform, $request, $validated, $campaignId) {
            $before = $this->tryFetchCampaign($platform, $campaignId);
            $payload = array_filter([
                'status' => $validated['status'],
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
            ], static fn ($value) => $value !== null);
            $item = $this->bannerAds->changeStatus($platform, $campaignId, $payload);
            $this->audit($request, $platform, $this->auditActionForStatus((string) $validated['status']), $campaignId, $before, $item);

            return response()->json([
                'item' => $item,
                'message' => 'Banner ad status updated.',
            ]);
        });
    }

    public function destroy(Request $request, int $campaignId): JsonResponse
    {
        $this->ensureBannerAdRole($request);

        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);

        return $this->remoteResponse(function () use ($platform, $request, $validated, $campaignId) {
            $before = $this->tryFetchCampaign($platform, $campaignId);
            $result = $this->bannerAds->delete($platform, $campaignId);
            $this->audit(
                $request,
                $platform,
                CrmAuditAction::BANNER_AD_DELETE,
                $campaignId,
                $before,
                ['deleted_id' => (int) ($result['deleted_id'] ?? $campaignId), 'permanent' => true],
                $validated['reason'] ?? null,
            );

            return response()->json([
                'success' => (bool) ($result['success'] ?? true),
                'deleted_id' => (int) ($result['deleted_id'] ?? $campaignId),
            ]);
        });
    }

    public function settings(Request $request): JsonResponse
    {
        $this->ensureBannerAdRole($request);

        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'shuffle_mode' => ['sometimes', 'required_without:profile_locations', 'boolean'],
            'profile_locations' => ['sometimes', 'required_without:shuffle_mode', 'array:enabled,tracking_enabled'],
            'profile_locations.enabled' => ['sometimes', 'boolean'],
            'profile_locations.tracking_enabled' => ['sometimes', 'boolean'],
        ]);

        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);

        return $this->remoteResponse(function () use ($platform, $request, $validated) {
            $before = $this->bannerAds->settings($platform);
            $settings = $this->bannerAds->updateSettings($platform, $validated);
            $this->audit($request, $platform, CrmAuditAction::BANNER_AD_SETTINGS_UPDATE, (int) $platform->id, $before, $settings);

            return response()->json([
                'settings' => $settings,
                'message' => 'Settings updated.',
            ]);
        });
    }

    public function destinations(Request $request): JsonResponse
    {
        $this->ensureBannerAdRole($request);
        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->remoteResponse(fn () => response()->json(
            $this->bannerAds->destinations($this->authorizedPlatform($request, (int) $validated['platform_id']), $validated)
        ));
    }

    public function report(Request $request): JsonResponse
    {
        $this->ensureBannerAdRole($request);
        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'category' => ['required', Rule::in(['homepage', 'profile_location'])],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.now()->format('Y-m-d')],
        ]);
        abort_if(\Carbon\CarbonImmutable::parse($validated['from'])->diffInDays($validated['to']) > 366, 422, 'Choose a date range of at most 366 days.');

        return $this->remoteResponse(fn () => response()->json(
            $this->bannerAds->report($this->authorizedPlatform($request, (int) $validated['platform_id']), $validated)
        ));
    }

    public function media(Request $request): JsonResponse
    {
        $this->ensureBannerAdRole($request);

        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->remoteResponse(fn () => response()->json(
            $this->bannerAds->media($this->authorizedPlatform($request, (int) $validated['platform_id']), $validated)
        ));
    }

    public function uploadMedia(Request $request): JsonResponse
    {
        $this->ensureBannerAdRole($request);

        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'file' => ['required', 'image', 'max:5120'],
            'alt_text' => ['nullable', 'string', 'max:125'],
        ]);

        $platform = $this->authorizedPlatform($request, (int) $validated['platform_id']);

        return $this->remoteResponse(fn () => response()->json([
            'item' => $this->bannerAds->uploadMedia($platform, $request->file('file'), $validated['alt_text'] ?? null),
            'message' => 'Image uploaded.',
        ], 201));
    }

    private function ensureBannerAdRole(Request $request): void
    {
        $this->marketAuthorization->ensureRole($request->user(), self::ROLES);
    }

    private function authorizedPlatform(Request $request, int $platformId): Platform
    {
        $this->marketAuthorization->ensureUserCanAccessPlatform($request->user(), $platformId);

        return Platform::query()->findOrFail($platformId);
    }

    private function validateCampaignPayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $validated = $request->validate([
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'post_title' => [$required, 'string', 'max:100'],
            '_campaign_format' => [$required, Rule::in(['card', 'image'])],
            '_campaign_category' => ['sometimes', Rule::in(['homepage', 'profile_location'])],
            '_campaign_weight' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            '_campaign_source_location_ids' => ['sometimes', 'array', 'max:100'],
            '_campaign_source_location_ids.*' => ['integer', 'min:1', 'distinct'],
            '_campaign_destination_kind' => ['sometimes', Rule::in(['term', 'page'])],
            '_campaign_destination_id' => ['sometimes', 'integer', 'min:1'],
            '_campaign_approved' => ['sometimes', 'boolean'],
            '_campaign_badge_text' => ['nullable', 'string', 'max:20'],
            '_campaign_description' => ['nullable', 'string', 'max:200'],
            '_campaign_icon_class' => ['nullable', 'string', 'max:60'],
            '_campaign_color_primary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            '_campaign_color_secondary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            '_campaign_image_id' => ['nullable', 'integer', 'min:0'],
            '_campaign_image_alt' => ['nullable', 'string', 'max:125'],
            '_campaign_cta_text' => ['nullable', 'string', 'max:30'],
            '_campaign_cta_url' => ['nullable', 'url', 'starts_with:http://,https://'],
            '_campaign_cta_visible' => ['nullable', 'boolean'],
            '_campaign_status' => [$required, Rule::in(['active', 'scheduled', 'paused', 'expired'])],
            '_campaign_priority' => ['nullable', 'integer', 'min:1'],
            '_campaign_start_date' => ['nullable', 'date_format:Y-m-d H:i:s', 'required_if:_campaign_status,scheduled'],
            '_campaign_end_date' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:_campaign_start_date'],
        ]);

        if (($validated['_campaign_format'] ?? null) === 'image' && empty($validated['_campaign_image_id'])) {
            abort(422, 'Image ads require a WordPress image attachment.');
        }

        return $validated;
    }

    private function wordpressPayload(array $validated): array
    {
        unset($validated['platform_id']);

        return array_filter($validated, static fn ($value) => $value !== null);
    }

    private function remoteResponse(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (BannerAdRemoteException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'remote_status' => $exception->remoteStatus(),
            ], $exception->responseStatus());
        }
    }

    private function tryFetchCampaign(Platform $platform, int $campaignId): ?array
    {
        try {
            return $this->bannerAds->get($platform, $campaignId);
        } catch (BannerAdRemoteException) {
            return null;
        }
    }

    private function audit(
        Request $request,
        Platform $platform,
        string $action,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            $action,
            'banner_ad',
            $entityId,
            $before,
            $after,
            $reason,
        );
    }

    private function auditActionForStatus(string $status): string
    {
        return match ($status) {
            'active' => CrmAuditAction::BANNER_AD_ACTIVATE,
            'scheduled' => CrmAuditAction::BANNER_AD_SCHEDULE,
            'paused' => CrmAuditAction::BANNER_AD_PAUSE,
            'expired' => CrmAuditAction::BANNER_AD_EXPIRE,
            default => CrmAuditAction::BANNER_AD_UPDATE,
        };
    }
}
