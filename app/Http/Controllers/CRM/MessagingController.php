<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\MessagingSuppression;
use App\Models\Platform;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppProviderProfile;
use App\Models\WhatsAppRoutingRule;
use App\Models\WhatsAppSender;
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

        $routingQuery = WhatsAppRoutingRule::query()
            ->with(['market:id,name,country', 'primaryProfile:id,profile_name,engine,kill_switch_enabled,active', 'fallbackProfile:id,profile_name,engine,kill_switch_enabled,active']);
        $this->applyMarketScope($routingQuery, $request);

        $messageQuery = WhatsAppMessage::query();
        $this->applyMarketScope($messageQuery, $request, 'platform_id');

        $suppressionQuery = MessagingSuppression::query()->whereNull('revoked_at');
        $this->applyMarketScopeWithGlobals($suppressionQuery, $request);

        $lastSuccessfulMessage = (clone $messageQuery)
            ->where('direction', 'outbound')
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->first(['id', 'engine', 'status', 'platform_id', 'sent_at', 'created_at']);

        return response()->json([
            'profiles' => $query->orderBy('market_id')->orderBy('profile_name')->get()->map(fn (WhatsAppProviderProfile $profile) => $this->serializeProfile($profile))->values(),
            'routing_rules' => $routingQuery->orderBy('market_id')->orderBy('message_type')->get()->map(fn (WhatsAppRoutingRule $rule) => $this->serializeRoutingRule($rule))->values(),
            'stats' => [
                'configured_routes' => (clone $routingQuery)->count(),
                'enabled_routes' => (clone $routingQuery)->where('enabled', true)->count(),
                'active_suppressions' => (clone $suppressionQuery)->count(),
                'failed_messages_24h' => (clone $messageQuery)
                    ->where('direction', 'outbound')
                    ->whereIn('status', ['failed', 'rejected', 'suppressed'])
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                'last_successful_send' => $lastSuccessfulMessage ? [
                    'id' => (int) $lastSuccessfulMessage->id,
                    'engine' => $lastSuccessfulMessage->engine,
                    'status' => $lastSuccessfulMessage->status,
                    'platform_id' => $lastSuccessfulMessage->platform_id ? (int) $lastSuccessfulMessage->platform_id : null,
                    'sent_at' => optional($lastSuccessfulMessage->sent_at ?: $lastSuccessfulMessage->created_at)->toISOString(),
                ] : null,
            ],
            'message_types' => self::MESSAGE_TYPES,
            'meta_default_api_version' => config('services.whatsapp.meta_default_api_version'),
            'sidecar_url' => config('services.whatsapp.sidecar_url'),
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
        $validated['engine'] ??= $profile->engine;
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
            ->with(['primaryProfile', 'fallbackProfile'])
            ->where('market_id', $market->id)
            ->where('message_type', $messageType)
            ->first();

        return response()->json([
            'market' => Arr::only($market->toArray(), ['id', 'name', 'country']),
            'message_type' => $messageType,
            'rule' => $rule ? $this->serializeRoutingRule($rule) : null,
            'profiles' => WhatsAppProviderProfile::query()
                ->where('market_id', $market->id)
                ->where('active', true)
                ->orderBy('engine')
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
            'fallback_profile_id' => [
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
                'fallback_profile_id' => $validated['fallback_profile_id'] ?? null,
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
            $this->serializeRoutingRule($rule->fresh(['primaryProfile', 'fallbackProfile']))
        );

        return response()->json($this->serializeRoutingRule($rule->fresh(['primaryProfile', 'fallbackProfile'])));
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

    public function suppressions(Request $request)
    {
        $query = MessagingSuppression::query()
            ->with(['platform:id,name,country', 'revokedBy:id,name'])
            ->orderByRaw('revoked_at IS NULL DESC')
            ->orderByDesc('opted_out_at');

        $this->applyMarketScopeWithGlobals($query, $request);

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }

        if ($request->boolean('active_only', true)) {
            $query->whereNull('revoked_at');
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function revokeSuppression(Request $request, MessagingSuppression $suppression)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), $suppression->platform_id ? (int) $suppression->platform_id : null);

        if (!$suppression->revoked_at) {
            $suppression->forceFill([
                'revoked_at' => now(),
                'revoked_by' => optional($request->user())->id,
            ])->save();

            $this->auditService->fromRequest(
                $request,
                (int) ($suppression->platform_id ?: Platform::query()->orderBy('id')->value('id')),
                CrmAuditAction::MESSAGING_OPT_OUT_REVOKED,
                'messaging_suppression',
                (int) $suppression->id,
                ['revoked_at' => null],
                [
                    'revoked_at' => optional($suppression->revoked_at)->toISOString(),
                    'revoked_by' => $suppression->revoked_by,
                ]
            );
        }

        return response()->json($suppression->fresh(['platform:id,name,country', 'revokedBy:id,name']));
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

    public function senders(Request $request)
    {
        $query = WhatsAppSender::query()
            ->with(['providerProfile:id,market_id,profile_name,engine', 'providerProfile.market:id,name,country'])
            ->whereHas('providerProfile', function ($profileQuery) use ($request) {
                $profileQuery->where('engine', 'baileys');
                $this->applyMarketScope($profileQuery, $request);
            })
            ->orderBy('provider_profile_id')
            ->orderBy('phone_e164');

        return response()->json($query->get()->map(fn (WhatsAppSender $sender) => $this->serializeSender($sender))->values());
    }

    public function storeSender(Request $request, WhatsAppProviderProfile $profile)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $profile->market_id);
        abort_unless($profile->engine === 'baileys', 422, 'Senders can only be attached to Baileys profiles.');

        $validated = $request->validate([
            'phone_e164' => 'required|string|max:32',
            'display_name' => 'nullable|string|max:120',
            'daily_limit' => 'nullable|integer|min:1|max:2000',
        ]);

        $sender = WhatsAppSender::create([
            'provider_profile_id' => $profile->id,
            'phone_e164' => preg_replace('/\D+/', '', (string) $validated['phone_e164']),
            'display_name' => $validated['display_name'] ?? null,
            'connection_status' => WhatsAppSender::STATUS_PAIRING,
            'daily_limit' => $validated['daily_limit'] ?? WhatsAppSender::limitForWarmupPhase(WhatsAppSender::WARMUP_DAY_1_3),
            'daily_sent_resets_at' => now()->addDay()->startOfDay(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $profile->market_id,
            CrmAuditAction::WHATSAPP_SENDER_PAIRED,
            'whatsapp_sender',
            (int) $sender->id,
            null,
            $this->serializeSender($sender->fresh(['providerProfile.market']))
        );

        return response()->json($this->serializeSender($sender->fresh(['providerProfile.market'])), 201);
    }

    public function repairSender(Request $request, WhatsAppSender $sender)
    {
        $sender->loadMissing('providerProfile');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $sender->providerProfile->market_id);
        abort_if($sender->retired_at, 422, 'Retired senders cannot be repaired.');

        $before = $this->serializeSender($sender->fresh(['providerProfile.market']));
        $sender->forceFill([
            'connection_status' => WhatsAppSender::STATUS_PAIRING,
            'last_disconnect_reason' => null,
            'quarantine_until' => null,
            'consecutive_failures' => 0,
        ])->save();

        $this->auditService->fromRequest(
            $request,
            (int) $sender->providerProfile->market_id,
            CrmAuditAction::WHATSAPP_SENDER_REPAIR_STARTED,
            'whatsapp_sender',
            (int) $sender->id,
            $before,
            $this->serializeSender($sender->fresh(['providerProfile.market']))
        );

        return response()->json($this->serializeSender($sender->fresh(['providerProfile.market'])));
    }

    public function logoutSender(Request $request, WhatsAppSender $sender)
    {
        $sender->loadMissing('providerProfile');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $sender->providerProfile->market_id);

        $before = $this->serializeSender($sender->fresh(['providerProfile.market']));
        $sender->markDisconnected('admin_logout');

        $this->auditService->fromRequest(
            $request,
            (int) $sender->providerProfile->market_id,
            CrmAuditAction::WHATSAPP_SENDER_LOGGED_OUT,
            'whatsapp_sender',
            (int) $sender->id,
            $before,
            $this->serializeSender($sender->fresh(['providerProfile.market']))
        );

        return response()->json($this->serializeSender($sender->fresh(['providerProfile.market'])));
    }

    public function retireSender(Request $request, WhatsAppSender $sender)
    {
        $sender->loadMissing('providerProfile');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $sender->providerProfile->market_id);

        $validated = $request->validate([
            'confirmation' => 'required|string|in:RETIRE',
            'reason' => 'nullable|string|max:255',
        ]);

        $before = $this->serializeSender($sender->fresh(['providerProfile.market']));
        $sender->forceFill([
            'connection_status' => WhatsAppSender::STATUS_RETIRED,
            'retired_at' => now(),
            'retired_reason' => $validated['reason'] ?? 'admin_retired',
            'quarantine_until' => null,
        ])->save();

        $this->auditService->fromRequest(
            $request,
            (int) $sender->providerProfile->market_id,
            CrmAuditAction::WHATSAPP_SENDER_RETIRED,
            'whatsapp_sender',
            (int) $sender->id,
            $before,
            $this->serializeSender($sender->fresh(['providerProfile.market']))
        );

        return response()->json($this->serializeSender($sender->fresh(['providerProfile.market'])));
    }

    private function validateProfile(Request $request, ?WhatsAppProviderProfile $profile = null): array
    {
        $profileId = $profile?->id;
        $engine = (string) $request->input('engine', $profile?->engine ?: 'meta_cloud_api');

        return $request->validate([
            'market_id' => 'required|integer|exists:platforms,id',
            'engine' => 'nullable|in:meta_cloud_api,baileys',
            'profile_name' => [
                'required',
                'string',
                'max:160',
                Rule::unique('whatsapp_provider_profiles', 'profile_name')
                    ->where('market_id', (int) $request->input('market_id'))
                    ->where('engine', $engine)
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
            'baileys_sidecar_url_override' => 'nullable|string|max:255',
            'active' => 'nullable|boolean',
            'config_json' => 'nullable|array',
        ]);
    }

    private function profilePayload(array $validated, bool $creating): array
    {
        $payload = [
            'market_id' => (int) $validated['market_id'],
            'engine' => $validated['engine'] ?? 'meta_cloud_api',
            'profile_name' => $validated['profile_name'],
            'environment' => $validated['environment'],
            'meta_phone_number_id' => $validated['meta_phone_number_id'] ?? null,
            'meta_business_account_id' => $validated['meta_business_account_id'] ?? null,
            'meta_api_version' => $validated['meta_api_version'] ?? null,
            'baileys_sidecar_url_override' => $validated['baileys_sidecar_url_override'] ?? null,
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
            'baileys_sidecar_url_override' => $profile->baileys_sidecar_url_override,
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
            'fallback_profile_id' => $rule->fallback_profile_id ? (int) $rule->fallback_profile_id : null,
            'fallback_to_sms' => (bool) $rule->fallback_to_sms,
            'enabled' => (bool) $rule->enabled,
            'created_at' => optional($rule->created_at)->toISOString(),
            'updated_at' => optional($rule->updated_at)->toISOString(),
            'primary_profile' => $rule->primaryProfile ? [
                'id' => (int) $rule->primaryProfile->id,
                'profile_name' => $rule->primaryProfile->profile_name,
                'engine' => $rule->primaryProfile->engine,
                'kill_switch_enabled' => (bool) $rule->primaryProfile->kill_switch_enabled,
            ] : null,
            'fallback_profile' => $rule->fallbackProfile ? [
                'id' => (int) $rule->fallbackProfile->id,
                'profile_name' => $rule->fallbackProfile->profile_name,
                'engine' => $rule->fallbackProfile->engine,
                'kill_switch_enabled' => (bool) $rule->fallbackProfile->kill_switch_enabled,
            ] : null,
        ];
    }

    private function serializeSender(WhatsAppSender $sender): array
    {
        return [
            'id' => (int) $sender->id,
            'provider_profile_id' => (int) $sender->provider_profile_id,
            'profile' => $sender->providerProfile ? [
                'id' => (int) $sender->providerProfile->id,
                'profile_name' => $sender->providerProfile->profile_name,
                'engine' => $sender->providerProfile->engine,
                'market_id' => (int) $sender->providerProfile->market_id,
                'market' => $sender->providerProfile->market ? [
                    'id' => (int) $sender->providerProfile->market->id,
                    'name' => $sender->providerProfile->market->name,
                    'country' => $sender->providerProfile->market->country,
                ] : null,
            ] : null,
            'phone_e164' => $sender->phone_e164,
            'display_name' => $sender->display_name,
            'connection_status' => $sender->connection_status,
            'warmup_phase' => $sender->warmup_phase,
            'daily_sent_count' => (int) $sender->daily_sent_count,
            'daily_limit' => (int) $sender->daily_limit,
            'daily_sent_resets_at' => optional($sender->daily_sent_resets_at)->toISOString(),
            'queue_depth' => 0,
            'in_flight' => 0,
            'quarantine_until' => optional($sender->quarantine_until)->toISOString(),
            'last_disconnect_reason' => $sender->last_disconnect_reason,
            'last_message_at' => optional($sender->last_message_at)->toISOString(),
            'retired_at' => optional($sender->retired_at)->toISOString(),
            'retired_reason' => $sender->retired_reason,
            'auth_state_configured' => filled($sender->auth_state_encrypted),
            'created_at' => optional($sender->created_at)->toISOString(),
            'updated_at' => optional($sender->updated_at)->toISOString(),
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

    private function applyMarketScopeWithGlobals($query, Request $request): void
    {
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if (is_array($allowedPlatformIds)) {
            $query->where(function ($builder) use ($allowedPlatformIds) {
                $builder->whereNull('platform_id')
                    ->orWhereIn('platform_id', $allowedPlatformIds);
            });
        }
    }

    private function assertMessageType(string $messageType): void
    {
        abort_unless(in_array($messageType, self::MESSAGE_TYPES, true), 404);
    }
}
