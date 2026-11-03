<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\TourSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TourSettingsController extends Controller
{
    public function __construct(
        private readonly TourSettingsService $settings,
        private readonly MarketAuthorizationService $markets,
        private readonly AuditService $audit,
    ) {}

    public function markets(Request $request): JsonResponse
    {
        $allowed = $this->markets->resolveAccessiblePlatformIds($request->user());
        $query = Platform::orderBy('name');
        if (is_array($allowed)) {
            $query->whereIn('id', $allowed);
        }

        return response()->json(['data' => $query->get(['id', 'name', 'country', 'domain', 'currency_code', 'is_active'])->map(fn ($platform) => $platform->toArray() + ['origin' => $this->settings->originForPlatform($platform)])]);
    }

    public function show(Request $request, Platform $platform): JsonResponse
    {
        $this->markets->ensureUserCanAccessPlatform($request->user(), $platform->id);

        return response()->json($this->settings->read($platform));
    }

    public function update(Request $request, Platform $platform): JsonResponse
    {
        $this->markets->ensureUserCanAccessPlatform($request->user(), $platform->id);
        $validated = $request->validate(['expected_revision' => ['required', 'integer', 'min:0'], 'settings' => ['required', 'array']]);
        $before = $this->settings->read($platform);
        $after = $this->settings->save($platform, $validated['settings'], (int) $validated['expected_revision'], (int) $request->user()->id);
        $this->audit->fromRequest($request, (int) $platform->id, 'tour_settings_saved', 'tour_market_settings', (int) $platform->id, $before, $after);

        return response()->json($after + ['message' => 'Tour settings saved in CRM. Publish them when the website is ready.']);
    }

    public function publish(Request $request, Platform $platform): JsonResponse
    {
        $this->markets->ensureUserCanAccessPlatform($request->user(), $platform->id);
        $validated = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        try {
            $after = $this->settings->publish($platform, (int) $validated['expected_revision']);
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'configuration' => $this->settings->read($platform)], 502);
        }
        $this->audit->fromRequest($request, (int) $platform->id, 'tour_settings_published', 'tour_market_settings', (int) $platform->id, null, $after);

        return response()->json($after + ['message' => 'Published and verified on the website.']);
    }
}
