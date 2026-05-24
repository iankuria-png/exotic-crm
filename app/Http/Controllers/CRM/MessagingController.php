<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppProviderProfile;
use App\Models\WhatsAppRoutingRule;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\Messaging\MessageRecipient;
use App\Services\Messaging\MessagingDispatcher;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class MessagingController extends Controller
{
    private const MESSAGE_TYPES = [
        'transactional',
        'marketing',
        'otp',
        'conversation',
        'renewal',
        'payment_link',
        'credential',
    ];

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly MessagingDispatcher $messagingDispatcher,
        private readonly AuditService $auditService,
    ) {
    }

    public function profiles(Request $request)
    {
        $query = WhatsAppProviderProfile::query()->with('market:id,name,country,phone_prefix');
        $this->applyMarketScope($query, $request);

        return response()->json([
            'profiles' => $query->orderBy('market_id')->orderBy('profile_name')->get()->map(fn (WhatsAppProviderProfile $profile) => $this->serializeProfile($profile))->values(),
            'message_types' => self::MESSAGE_TYPES,
            'meta_default_api_version' => config('services.whatsapp.meta_default_api_version'),
        ]);
    }

    public function storeProfile(Request $request)
    {
        $validated = $this->validateProfile($request);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $validated['market_id']);

        $profile = WhatsAppProviderProfile::create($this->profilePayload($validated, true));
        $profile->load('market:id,name,country,phone_prefix');

        $this->auditService->fromRequest(
            $request,
            (int) $profile->market_id,
            CrmAuditAction::WHATSAPP_PROFILE_UPDATED,
            'whatsapp_provider_profile',
            (int) $profile->id,
            null,
            $this->auditProfileState($profile)
        );

        return response()->json($this->serializeProfile($profile), 201);
    }

    public function updateProfile(Request $request, WhatsAppProviderProfile $profile)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $profile->market_id);

        $validated = $this->validateProfile($request, $profile);
        $targetMarketId = (int) ($validated['market_id'] ?? $profile->market_id);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), $targetMarketId);

        $before = $this->auditProfileState($profile);
        $profile->update($this->profilePayload($validated, false));
        $profile->load('market:id,name,country,phone_prefix');

        $this->auditService->fromRequest(
            $request,
            (int) $profile->market_id,
            CrmAuditAction::WHATSAPP_PROFILE_UPDATED,
            'whatsapp_provider_profile',
            (int) $profile->id,
            $before,
            $this->auditProfileState($profile)
        );

        return response()->json($this->serializeProfile($profile));
    }

    public function destroyProfile(Request $request, WhatsAppProviderProfile $profile)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $profile->market_id);

        $before = $this->auditProfileState($profile);
        $marketId = (int) $profile->market_id;
        $profileId = (int) $profile->id;
        $profile->delete();

        $this->auditService->fromRequest(
            $request,
            $marketId,
            CrmAuditAction::WHATSAPP_PROFILE_UPDATED,
            'whatsapp_provider_profile',
            $profileId,
            $before,
            ['deleted' => true]
        );

        return response()->json(['message' => 'WhatsApp profile deleted.']);
    }

    public function testProfile(Request $request, WhatsAppProviderProfile $profile)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $profile->market_id);

        $validated = $request->validate([
            'phone' => 'required|string|max:32',
            'body' => 'required_without:template_name|nullable|string|max:4096',
            'template_name' => 'nullable|string|max:255',
            'template_language' => 'nullable|string|max:16',
        ]);

        $recipient = MessageRecipient::fromPhone(
            $validated['phone'],
            (int) $profile->market_id,
            (string) ($profile->market?->phone_prefix ?: '254')
        );

        $result = $this->messagingDispatcher->dispatch(
            $recipient,
            (string) ($validated['body'] ?? ''),
            'whatsapp',
            [
                'message_type' => 'transactional',
                'provider_profile_id' => $profile->id,
                'template_name' => $validated['template_name'] ?? null,
                'template_language' => $validated['template_language'] ?? 'en_US',
                'actor_id' => optional($request->user())->id,
                'idempotency_key' => 'profile-test-' . $profile->id . '-' . sha1($recipient->phoneE164 . '|' . ($validated['body'] ?? '') . '|' . now()->timestamp),
            ]
        );

        if ($result->success) {
            $profile->forceFill(['tested_at' => now()])->save();
        }

        return response()->json($result->toArray() + [
            'message' => $result->success ? 'WhatsApp test sent.' : 'WhatsApp test failed.',
        ], $result->success ? 200 : 422);
    }

    public function toggleKillSwitch(Request $request, WhatsAppProviderProfile $profile)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $profile->market_id);

        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
        ]);

        $before = ['kill_switch_enabled' => (bool) $profile->kill_switch_enabled];
        $profile->forceFill([
            'kill_switch_enabled' => array_key_exists('enabled', $validated)
                ? (bool) $validated['enabled']
                : !((bool) $profile->kill_switch_enabled),
        ])->save();

        $this->auditService->fromRequest(
            $request,
            (int) $profile->market_id,
            CrmAuditAction::WHATSAPP_PROFILE_KILL_SWITCH_TOGGLED,
            'whatsapp_provider_profile',
            (int) $profile->id,
            $before,
            ['kill_switch_enabled' => (bool) $profile->kill_switch_enabled]
        );

        return response()->json($this->serializeProfile($profile->fresh('market:id,name,country,phone_prefix')));
    }

    public function showRouting(Request $request, Platform $market, string $messageType)
    {
        $this->assertMessageType($messageType);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $market->id);

        $rule = WhatsAppRoutingRule::query()
            ->with('primaryProfile')
            ->where('market_id', $market->id)
            ->where('message_type', $messageType)
            ->first();

        return response()->json([
            'market' => Arr::only($market->toArray(), ['id', 'name', 'country']),
            'message_type' => $messageType,
            'rule' => $rule ? $this->serializeRoutingRule($rule) : null,
            'profiles' => WhatsAppProviderProfile::query()
                ->where('market_id', $market->id)
                ->where('engine', 'meta_cloud_api')
                ->where('active', true)
                ->orderBy('profile_name')
                ->get()
                ->map(fn (WhatsAppProviderProfile $profile) => $this->serializeProfile($profile))
                ->values(),
        ]);
    }

    public function updateRouting(Request $request, Platform $market, string $messageType)
    {
        $this->assertMessageType($messageType);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $market->id);

        $validated = $request->validate([
            'primary_profile_id' => [
                'nullable',
                'integer',
                Rule::exists('whatsapp_provider_profiles', 'id')->where('market_id', $market->id),
            ],
            'fallback_to_sms' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
        ]);

        $rule = WhatsAppRoutingRule::updateOrCreate(
            [
                'market_id' => $market->id,
                'message_type' => $messageType,
            ],
            [
                'primary_profile_id' => $validated['primary_profile_id'] ?? null,
                'fallback_profile_id' => null,
                'fallback_to_sms' => (bool) ($validated['fallback_to_sms'] ?? true),
                'enabled' => (bool) ($validated['enabled'] ?? true),
            ]
        );

        $this->auditService->fromRequest(
            $request,
            (int) $market->id,
            CrmAuditAction::WHATSAPP_ROUTING_RULE_UPDATED,
            'whatsapp_routing_rule',
            (int) $rule->id,
            null,
            $this->serializeRoutingRule($rule->fresh('primaryProfile'))
        );

        return response()->json($this->serializeRoutingRule($rule->fresh('primaryProfile')));
    }

    public function messages(Request $request)
    {
        $query = WhatsAppMessage::query()
            ->with(['platform:id,name,country', 'providerProfile:id,profile_name,engine', 'client:id,name', 'lead:id,name', 'payment:id'])
            ->orderByDesc('created_at');
        $this->applyMarketScope($query, $request, 'platform_id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function testSend(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'phone' => 'required|string|max:32',
            'body' => 'required|string|max:4096',
            'message_type' => ['nullable', Rule::in(self::MESSAGE_TYPES)],
            'channel_preference' => 'nullable|in:whatsapp,whatsapp_with_sms_fallback',
        ]);

        $platform = Platform::findOrFail((int) $validated['platform_id']);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $platform->id);

        $recipient = MessageRecipient::fromPhone(
            $validated['phone'],
            (int) $platform->id,
            (string) ($platform->phone_prefix ?: '254')
        );

        $result = $this->messagingDispatcher->dispatch(
            $recipient,
            $validated['body'],
            $validated['channel_preference'] ?? 'whatsapp',
            [
                'message_type' => $validated['message_type'] ?? 'transactional',
                'actor_id' => optional($request->user())->id,
                'idempotency_key' => 'admin-test-' . sha1($platform->id . '|' . $recipient->phoneE164 . '|' . $validated['body'] . '|' . now()->timestamp),
            ]
        );

        return response()->json($result->toArray(), $result->success ? 200 : 422);
    }

    private function validateProfile(Request $request, ?WhatsAppProviderProfile $profile = null): array
    {
        $profileId = $profile?->id;

        return $request->validate([
            'market_id' => 'required|integer|exists:platforms,id',
            'engine' => 'nullable|in:meta_cloud_api',
            'profile_name' => [
                'required',
                'string',
                'max:160',
                Rule::unique('whatsapp_provider_profiles', 'profile_name')
                    ->where('market_id', (int) $request->input('market_id'))
                    ->where('engine', 'meta_cloud_api')
                    ->ignore($profileId),
            ],
            'environment' => 'required|string|in:sandbox,production',
            'kill_switch_enabled' => 'nullable|boolean',
            'meta_phone_number_id' => 'nullable|string|max:255',
            'meta_business_account_id' => 'nullable|string|max:255',
            'meta_access_token' => 'nullable|string',
            'meta_webhook_verify_token' => 'nullable|string',
            'meta_app_secret' => 'nullable|string',
            'meta_api_version' => 'nullable|string|max:16',
            'active' => 'nullable|boolean',
            'config_json' => 'nullable|array',
        ]);
    }

    private function profilePayload(array $validated, bool $creating): array
    {
        $payload = [
            'market_id' => (int) $validated['market_id'],
            'engine' => 'meta_cloud_api',
            'profile_name' => $validated['profile_name'],
            'environment' => $validated['environment'],
            'meta_phone_number_id' => $validated['meta_phone_number_id'] ?? null,
            'meta_business_account_id' => $validated['meta_business_account_id'] ?? null,
            'meta_api_version' => $validated['meta_api_version'] ?? null,
            'config_json' => $validated['config_json'] ?? null,
        ];

        if ($creating || array_key_exists('kill_switch_enabled', $validated)) {
            $payload['kill_switch_enabled'] = (bool) ($validated['kill_switch_enabled'] ?? false);
        }

        if ($creating || array_key_exists('active', $validated)) {
            $payload['active'] = (bool) ($validated['active'] ?? true);
        }

        foreach (['meta_access_token', 'meta_webhook_verify_token', 'meta_app_secret'] as $secretField) {
            if ($creating || array_key_exists($secretField, $validated)) {
                $secret = trim((string) ($validated[$secretField] ?? ''));
                if ($secret !== '') {
                    $payload[$secretField] = $secret;
                }
            }
        }

        return $payload;
    }

    private function serializeProfile(WhatsAppProviderProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'market_id' => (int) $profile->market_id,
            'market' => $profile->market ? [
                'id' => (int) $profile->market->id,
                'name' => $profile->market->name,
                'country' => $profile->market->country,
                'phone_prefix' => $profile->market->phone_prefix,
            ] : null,
            'engine' => $profile->engine,
            'profile_name' => $profile->profile_name,
            'environment' => $profile->environment,
            'kill_switch_enabled' => (bool) $profile->kill_switch_enabled,
            'meta_phone_number_id' => $profile->meta_phone_number_id,
            'meta_business_account_id' => $profile->meta_business_account_id,
            'meta_api_version' => $profile->apiVersion(),
            'meta_access_token_configured' => filled($profile->meta_access_token),
            'meta_webhook_verify_token_configured' => filled($profile->meta_webhook_verify_token),
            'meta_app_secret_configured' => filled($profile->meta_app_secret),
            'active' => (bool) $profile->active,
            'tested_at' => optional($profile->tested_at)->toISOString(),
            'created_at' => optional($profile->created_at)->toISOString(),
            'updated_at' => optional($profile->updated_at)->toISOString(),
        ];
    }

    private function serializeRoutingRule(WhatsAppRoutingRule $rule): array
    {
        return [
            'id' => (int) $rule->id,
            'market_id' => (int) $rule->market_id,
            'message_type' => $rule->message_type,
            'primary_profile_id' => $rule->primary_profile_id ? (int) $rule->primary_profile_id : null,
            'fallback_to_sms' => (bool) $rule->fallback_to_sms,
            'enabled' => (bool) $rule->enabled,
            'primary_profile' => $rule->primaryProfile ? [
                'id' => (int) $rule->primaryProfile->id,
                'profile_name' => $rule->primaryProfile->profile_name,
                'engine' => $rule->primaryProfile->engine,
                'kill_switch_enabled' => (bool) $rule->primaryProfile->kill_switch_enabled,
            ] : null,
        ];
    }

    private function auditProfileState(WhatsAppProviderProfile $profile): array
    {
        return Arr::except($this->serializeProfile($profile), [
            'meta_access_token_configured',
            'meta_webhook_verify_token_configured',
            'meta_app_secret_configured',
        ]) + [
            'meta_access_token_configured' => filled($profile->meta_access_token),
            'meta_webhook_verify_token_configured' => filled($profile->meta_webhook_verify_token),
            'meta_app_secret_configured' => filled($profile->meta_app_secret),
        ];
    }

    private function applyMarketScope($query, Request $request, string $column = 'market_id'): void
    {
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if (is_array($allowedPlatformIds)) {
            $query->whereIn($column, $allowedPlatformIds);
        }
    }

    private function assertMessageType(string $messageType): void
    {
        abort_unless(in_array($messageType, self::MESSAGE_TYPES, true), 404);
    }
}
