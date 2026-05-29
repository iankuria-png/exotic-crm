<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageAttempt;
use App\Models\WhatsAppSender;
use App\Services\AuditService;
use App\Services\Messaging\Inbound\InboundMessagePipeline;
use App\Services\Messaging\Sidecar\RestoreTokenService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class MessagingSidecarController extends Controller
{
    public function __construct(
        private readonly RestoreTokenService $restoreTokens,
        private readonly AuditService $auditService,
        private readonly InboundMessagePipeline $inboundPipeline,
    ) {
    }

    public function restoreSessions(Request $request)
    {
        $senders = WhatsAppSender::query()
            ->with('providerProfile:id,market_id,engine,active,kill_switch_enabled')
            ->active()
            ->whereHas('providerProfile', fn ($query) => $query->where('engine', 'baileys')->where('active', true))
            ->whereNotNull('auth_state_encrypted')
            ->orderBy('id')
            ->get();

        return response()->json([
            'senders' => $senders->map(fn (WhatsAppSender $sender) => $this->restoreTokens->issue($sender))->values(),
        ]);
    }

    public function authBlob(Request $request, WhatsAppSender $sender)
    {
        $token = (string) $request->header('X-Restore-Token', $request->query('restore_token', ''));
        $rateKey = "whatsapp-auth-blob:{$sender->id}:" . $request->ip();
        $limit = (int) config('services.whatsapp.auth_blob_fetch_limit_per_hour', 3);
        $allowed = RateLimiter::attempt($rateKey, $limit, fn () => true, 3600);

        if (!$allowed) {
            $this->auditAuthBlobFetch($request, $sender, false, 'rate_limited', $token);

            return response()->json(['message' => 'Auth blob fetch rate limit exceeded.'], 429);
        }

        if (!$token || !$this->restoreTokens->consume($token, $sender)) {
            $this->auditAuthBlobFetch($request, $sender, false, 'invalid_restore_token', $token);

            return response()->json(['message' => 'Invalid or expired restore token.'], 403);
        }

        if ($sender->retired_at || !$sender->auth_state_encrypted) {
            $this->auditAuthBlobFetch($request, $sender, false, 'sender_not_restorable', $token);

            return response()->json(['message' => 'Sender is not restorable.'], 422);
        }

        $this->auditAuthBlobFetch($request, $sender, true, null, $token);

        return response()->json([
            'sender_id' => (int) $sender->id,
            'auth_state' => $sender->auth_state_encrypted,
        ]);
    }

    public function baileysWebhook(Request $request)
    {
        $payload = $request->validate([
            'event' => 'required|string',
            'event_id' => 'required|string|max:120',
            'sender_id' => 'nullable|integer|exists:whatsapp_senders,id',
            'attempt_uuid' => 'nullable|string|max:64',
            'message_id' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:32',
            'error_code' => 'nullable|string|max:255',
            'error_message' => 'nullable|string',
            'from' => 'nullable|string|max:32',
            'to' => 'nullable|string|max:32',
            'name' => 'nullable|string|max:255',
            'message' => 'nullable|string',
            'timestamp' => 'nullable|string',
            'auth_state' => 'nullable|string',
            'reason' => 'nullable|string|max:255',
            'will_retry' => 'nullable|boolean',
        ]);

        $eventKey = 'baileys:' . $payload['event_id'];
        $event = \App\Models\MessagingWebhookEvent::firstOrCreate(
            ['engine' => 'baileys', 'external_event_id' => $eventKey],
            [
                'received_at' => now(),
                'payload_hash' => hash('sha256', $request->getContent()),
            ]
        );

        if (!$event->wasRecentlyCreated) {
            return response()->json(['ok' => true, 'deduped' => true]);
        }

        return match ($payload['event']) {
            'message.status' => $this->handleMessageStatus($payload),
            'message.received' => $this->handleInboundMessage($payload),
            'session.creds.update' => $this->handleCredsUpdate($payload),
            'session.disconnected' => $this->handleDisconnected($payload),
            'session.banned' => $this->handleBanned($payload),
            'pairing_code.update' => response()->json(['ok' => true]),
            default => response()->json(['message' => 'Unsupported sidecar event.'], 422),
        };
    }

    private function handleMessageStatus(array $payload)
    {
        $attempt = $this->attemptFromPayload($payload);
        $message = $attempt?->message;

        if (!$message && !empty($payload['message_id'])) {
            $message = WhatsAppMessage::query()->where('provider_message_id', $payload['message_id'])->first();
        }

        if (!$message) {
            return response()->json(['ok' => true, 'message' => 'No matching message.']);
        }

        $status = (string) ($payload['status'] ?? 'sent');
        $message->forceFill([
            'provider_message_id' => $payload['message_id'] ?? $message->provider_message_id,
            'sender_id' => $payload['sender_id'] ?? $message->sender_id,
            'status' => $status,
            'error_code' => $payload['error_code'] ?? $message->error_code,
            'error_message' => $payload['error_message'] ?? $message->error_message,
            'sent_at' => in_array($status, ['sent', 'delivered', 'read'], true) ? ($message->sent_at ?: now()) : $message->sent_at,
            'delivered_at' => $status === 'delivered' ? now() : $message->delivered_at,
            'read_at' => $status === 'read' ? now() : $message->read_at,
            'failed_at' => $status === 'failed' ? now() : $message->failed_at,
        ])->save();

        if ($attempt) {
            $attempt->forceFill([
                'status' => in_array($status, ['sent', 'delivered', 'read'], true) ? WhatsAppMessageAttempt::STATUS_ACCEPTED : WhatsAppMessageAttempt::STATUS_FAILED,
                'error_code' => $payload['error_code'] ?? null,
                'error_message' => $payload['error_message'] ?? null,
                'finished_at' => now(),
            ])->save();
        }

        return response()->json(['ok' => true]);
    }

    private function handleInboundMessage(array $payload)
    {
        $sender = !empty($payload['sender_id']) ? WhatsAppSender::with('providerProfile')->find($payload['sender_id']) : null;
        $profile = $sender?->providerProfile;
        $platformId = $profile?->market_id ?: Platform::query()->orderBy('id')->value('id');
        $phone = (string) ($payload['from'] ?? '');

        $message = WhatsAppMessage::create([
            'platform_id' => $platformId,
            'direction' => 'inbound',
            'engine' => 'baileys',
            'provider_profile_id' => $profile?->id,
            'sender_id' => $sender?->id,
            'phone_e164' => preg_replace('/\D+/', '', $phone),
            'body' => $payload['message'] ?? '',
            'provider_message_id' => $payload['message_id'] ?? ('baileys-in-' . Str::uuid()),
            'status' => 'received',
        ]);

        if ($profile) {
            $this->inboundPipeline->handle($message, $profile, $payload);
        }

        return response()->json(['ok' => true]);
    }

    private function handleCredsUpdate(array $payload)
    {
        $sender = !empty($payload['sender_id']) ? WhatsAppSender::find($payload['sender_id']) : null;
        if ($sender && !empty($payload['auth_state'])) {
            $sender->forceFill(['auth_state_encrypted' => $payload['auth_state']])->save();
        }

        return response()->json(['ok' => true]);
    }

    private function handleDisconnected(array $payload)
    {
        $sender = !empty($payload['sender_id']) ? WhatsAppSender::find($payload['sender_id']) : null;
        $sender?->markDisconnected($payload['reason'] ?? null);

        return response()->json(['ok' => true]);
    }

    private function handleBanned(array $payload)
    {
        $sender = !empty($payload['sender_id']) ? WhatsAppSender::find($payload['sender_id']) : null;
        if ($sender) {
            $sender->markBanned($payload['reason'] ?? 'sender_banned');
            $this->auditService->record([
                'platform_id' => $sender->providerProfile?->market_id,
                'action' => CrmAuditAction::WHATSAPP_SENDER_BANNED,
                'entity_type' => 'whatsapp_sender',
                'entity_id' => $sender->id,
                'after_state' => ['retired_at' => optional($sender->retired_at)->toISOString(), 'reason' => $payload['reason'] ?? 'sender_banned'],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function attemptFromPayload(array $payload): ?WhatsAppMessageAttempt
    {
        if (empty($payload['attempt_uuid'])) {
            return null;
        }

        return WhatsAppMessageAttempt::query()
            ->with('message')
            ->where('attempt_uuid', $payload['attempt_uuid'])
            ->first();
    }

    private function auditAuthBlobFetch(Request $request, WhatsAppSender $sender, bool $success, ?string $reason, string $token): void
    {
        $this->auditService->record([
            'platform_id' => $sender->providerProfile?->market_id,
            'action' => CrmAuditAction::WHATSAPP_AUTH_BLOB_FETCH,
            'entity_type' => 'whatsapp_sender',
            'entity_id' => $sender->id,
            'after_state' => [
                'success' => $success,
                'sidecar_ip' => $request->ip(),
                'token_hash' => $token ? hash('sha256', $token) : null,
                'reason' => $reason,
            ],
            'reason' => $reason,
        ]);
    }
}
