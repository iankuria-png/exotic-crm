<?php

namespace App\Services\Messaging;

use App\Models\TimelineEvent;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageAttempt;
use App\Models\WhatsAppProviderProfile;
use App\Models\WhatsAppRoutingRule;
use App\Services\AuditService;
use App\Services\Messaging\Engines\BaileysEngine;
use App\Services\Messaging\Engines\MetaCloudApiEngine;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppGatewayService
{
    /** @var array<string, WhatsAppEngineInterface> */
    private array $engines;

    public function __construct(
        MetaCloudApiEngine $metaCloudApiEngine,
        BaileysEngine $baileysEngine,
        private readonly SuppressionService $suppressionService,
        private readonly AuditService $auditService,
    ) {
        $this->engines = [
            $metaCloudApiEngine->id() => $metaCloudApiEngine,
            $baileysEngine->id() => $baileysEngine,
        ];
    }

    public function send(SendRequest $request): DispatchResult
    {
        $existing = $this->findExistingMessage($request);
        if ($existing) {
            return new DispatchResult(
                success: in_array($existing->status, ['sent', 'delivered', 'read'], true),
                channel: 'whatsapp',
                status: $existing->status,
                whatsAppMessage: $existing,
            );
        }

        [$profiles, $fallbackToSms] = $this->resolveRoute($request);
        $firstProfile = $profiles[0] ?? null;

        if ($this->suppressionService->isSuppressed($request->recipient->phoneE164, 'whatsapp', $request->recipient->platformId)) {
            $message = $this->createMessage($request, $firstProfile, 'suppressed', 'suppressed', 'Recipient has an active WhatsApp opt-out.');

            return new DispatchResult(false, 'whatsapp', 'suppressed', $message, errorCode: 'suppressed', errorMessage: 'Recipient has an active WhatsApp opt-out.');
        }

        if (!$firstProfile) {
            $message = $this->createMessage($request, null, 'rejected', 'no_route', 'No enabled WhatsApp routing profile is configured.');

            return new DispatchResult(
                false,
                'whatsapp',
                'rejected',
                $message,
                errorCode: 'no_route',
                errorMessage: 'No enabled WhatsApp routing profile is configured.',
                shouldFallbackToSms: $fallbackToSms,
            );
        }

        $message = $this->createMessage($request, $firstProfile, 'queued');
        $lastResult = null;

        foreach ($profiles as $index => $profile) {
            $attempt = $this->startAttempt($message, $profile, $index + 1);

            if ($profile->kill_switch_enabled) {
                $lastResult = SendResult::failed('rejected', 'kill_switch_enabled', 'WhatsApp profile kill switch is enabled.');
                $this->finishAttempt($attempt, $lastResult);
                continue;
            }

            $engine = $this->engines[$profile->engine] ?? null;
            if (!$engine) {
                $lastResult = SendResult::failed('failed', 'engine_unavailable', 'WhatsApp engine is not available.');
                $this->finishAttempt($attempt, $lastResult);
                continue;
            }

            try {
                $startedAt = microtime(true);
                $result = $engine->send($request->withProfile($profile)->withContext([
                    'attempt_uuid' => $attempt->attempt_uuid,
                ]));
                $attempt->latency_ms = (int) round((microtime(true) - $startedAt) * 1000);
            } catch (\Throwable $exception) {
                Log::error('WhatsApp dispatch failed', [
                    'profile_id' => $profile->id,
                    'message_id' => $message->id,
                    'attempt_id' => $attempt->id,
                    'error' => $exception->getMessage(),
                ]);

                $result = SendResult::failed('failed', 'exception', $exception->getMessage());
            }

            $lastResult = $result;
            $this->finishAttempt($attempt, $result);

            if ($result->success) {
                $message->forceFill([
                    'engine' => $profile->engine,
                    'provider_profile_id' => $profile->id,
                    'sender_id' => $result->senderId,
                    'status' => $result->status,
                    'provider_message_id' => $result->providerMessageId,
                    'error_code' => null,
                    'error_message' => null,
                    'cost_micros' => $result->costMicros,
                    'sent_at' => now(),
                    'failed_at' => null,
                ])->save();

                $this->recordSentEvents($message, $request);

                return new DispatchResult(
                    success: true,
                    channel: 'whatsapp',
                    status: $result->status,
                    whatsAppMessage: $message->refresh(),
                    errorCode: null,
                    errorMessage: null,
                    shouldFallbackToSms: false,
                );
            }
        }

        $lastResult ??= SendResult::failed('failed', 'unknown_failure', 'WhatsApp dispatch failed.');

        $message->forceFill([
            'status' => $lastResult->status,
            'error_code' => $lastResult->errorCode,
            'error_message' => $lastResult->errorMessage,
            'failed_at' => now(),
        ])->save();

        $this->recordFailureEvents($message, $request, $lastResult->errorMessage);

        return new DispatchResult(
            success: false,
            channel: 'whatsapp',
            status: $lastResult->status,
            whatsAppMessage: $message->refresh(),
            errorCode: $lastResult->errorCode,
            errorMessage: $lastResult->errorMessage,
            shouldFallbackToSms: $fallbackToSms,
        );
    }

    private function findExistingMessage(SendRequest $request): ?WhatsAppMessage
    {
        if (!$request->idempotencyKey) {
            return null;
        }

        return WhatsAppMessage::query()
            ->where('direction', 'outbound')
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();
    }

    /**
     * @return array{0: list<WhatsAppProviderProfile>, 1: bool}
     */
    private function resolveRoute(SendRequest $request): array
    {
        $forcedProfileId = $request->context['provider_profile_id'] ?? null;
        if ($forcedProfileId) {
            $profile = WhatsAppProviderProfile::query()
                ->whereKey((int) $forcedProfileId)
                ->where('active', true)
                ->first();

            return [$profile ? [$profile] : [], false];
        }

        $rule = WhatsAppRoutingRule::query()
            ->with(['primaryProfile', 'fallbackProfile'])
            ->where('market_id', $request->recipient->platformId)
            ->where('message_type', $request->messageType)
            ->where('enabled', true)
            ->first();

        if (!$rule) {
            return [[], false];
        }

        $profiles = collect([$rule->primaryProfile, $rule->fallbackProfile])
            ->filter(fn ($profile) => $profile instanceof WhatsAppProviderProfile && $profile->active)
            ->unique('id')
            ->values()
            ->all();

        return [$profiles, (bool) $rule->fallback_to_sms];
    }

    private function startAttempt(WhatsAppMessage $message, WhatsAppProviderProfile $profile, int $attemptNumber): WhatsAppMessageAttempt
    {
        return WhatsAppMessageAttempt::create([
            'whatsapp_message_id' => $message->id,
            'attempt_number' => $attemptNumber,
            'engine' => $profile->engine,
            'provider_profile_id' => $profile->id,
            'attempt_uuid' => (string) Str::uuid(),
            'status' => WhatsAppMessageAttempt::STATUS_ACCEPTED,
            'started_at' => now(),
        ]);
    }

    private function finishAttempt(WhatsAppMessageAttempt $attempt, SendResult $result): void
    {
        $attempt->forceFill([
            'sender_id' => $result->senderId,
            'status' => $result->success ? WhatsAppMessageAttempt::STATUS_ACCEPTED : $this->failedAttemptStatus($result),
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'latency_ms' => $attempt->latency_ms,
            'finished_at' => now(),
        ])->save();
    }

    private function failedAttemptStatus(SendResult $result): string
    {
        return $result->status === 'rejected'
            ? WhatsAppMessageAttempt::STATUS_REJECTED
            : WhatsAppMessageAttempt::STATUS_FAILED;
    }

    private function createMessage(
        SendRequest $request,
        ?WhatsAppProviderProfile $profile,
        string $status,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): WhatsAppMessage {
        return WhatsAppMessage::create([
            'platform_id' => $request->recipient->platformId,
            'direction' => 'outbound',
            'engine' => $profile?->engine ?? 'meta_cloud_api',
            'provider_profile_id' => $profile?->id,
            'client_id' => $request->recipient->clientId,
            'lead_id' => $request->recipient->leadId,
            'deal_id' => $request->recipient->dealId,
            'payment_id' => $request->recipient->paymentId,
            'template_id' => $request->templateId,
            'phone_e164' => $request->recipient->phoneE164,
            'body' => $request->body,
            'media_url' => $request->mediaUrl,
            'idempotency_key' => $request->idempotencyKey,
            'status' => $status,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'failed_at' => in_array($status, ['failed', 'rejected', 'suppressed'], true) ? now() : null,
        ]);
    }

    private function recordSentEvents(WhatsAppMessage $message, SendRequest $request): void
    {
        $this->recordTimelineEvent($message, $request, CrmAuditAction::WHATSAPP_SENT, [
            'status' => $message->status,
            'provider_message_id' => $message->provider_message_id,
        ]);

        $this->recordAudit($message, $request, CrmAuditAction::WHATSAPP_SENT, [
            'status' => $message->status,
            'provider_message_id' => $message->provider_message_id,
        ]);
    }

    private function recordFailureEvents(WhatsAppMessage $message, SendRequest $request, ?string $reason): void
    {
        $this->recordTimelineEvent($message, $request, CrmAuditAction::WHATSAPP_FAILED, [
            'status' => $message->status,
            'error_code' => $message->error_code,
            'error_message' => $reason,
        ]);

        $this->recordAudit($message, $request, CrmAuditAction::WHATSAPP_FAILED, [
            'status' => $message->status,
            'error_code' => $message->error_code,
            'error_message' => $reason,
        ], $reason);
    }

    private function recordTimelineEvent(WhatsAppMessage $message, SendRequest $request, string $eventType, array $content): void
    {
        if (!empty($request->context['suppress_gateway_timeline'])) {
            return;
        }

        [$entityType, $entityId] = $this->timelineEntity($request);

        if (!$entityType || !$entityId) {
            return;
        }

        TimelineEvent::create([
            'platform_id' => $request->recipient->platformId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_type' => $eventType,
            'actor_id' => $request->context['actor_id'] ?? null,
            'content' => array_merge($content, [
                'whatsapp_message_id' => $message->id,
                'phone_e164' => $message->phone_e164,
            ]),
            'created_at' => now(),
        ]);
    }

    private function recordAudit(WhatsAppMessage $message, SendRequest $request, string $action, array $afterState, ?string $reason = null): void
    {
        $this->auditService->record([
            'platform_id' => $request->recipient->platformId,
            'actor_id' => $request->context['actor_id'] ?? null,
            'action' => $action,
            'entity_type' => 'whatsapp_message',
            'entity_id' => $message->id,
            'after_state' => $afterState,
            'reason' => $reason,
        ]);
    }

    private function timelineEntity(SendRequest $request): array
    {
        if ($request->recipient->clientId) {
            return ['client', $request->recipient->clientId];
        }

        if ($request->recipient->leadId) {
            return ['lead', $request->recipient->leadId];
        }

        if ($request->recipient->dealId) {
            return ['deal', $request->recipient->dealId];
        }

        if ($request->recipient->paymentId) {
            return ['payment', $request->recipient->paymentId];
        }

        return [null, null];
    }
}
