<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\Messaging\DispatchResult;
use App\Services\Messaging\MessageRecipient;
use App\Services\Messaging\MessagingDispatcher;
use App\Services\TemplateService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly MessagingDispatcher $messagingDispatcher,
        private readonly TemplateService $templateService,
        private readonly AuditService $auditService,
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function send(Request $request, Client $client)
    {
        if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $client->platform_id)) {
            return response()->json(['message' => 'You do not have access to this client market.'], 403);
        }

        $validated = $request->validate([
            'template_id' => 'nullable|exists:templates,id',
            'message' => 'nullable|string|max:5000|required_without:template_id',
            'channel' => 'nullable|in:sms,whatsapp',
            'follow_up_at' => 'nullable|date',
        ]);

        $client->loadMissing(['platform', 'activeDeal.product']);
        $channel = $validated['channel'] ?? 'sms';
        $channelLabel = $channel === 'whatsapp' ? 'WhatsApp' : 'SMS';

        $template = null;
        if (!empty($validated['template_id'])) {
            $template = Template::query()
                ->where('id', (int) $validated['template_id'])
                ->where('channel', $channel)
                ->where('status', 'active')
                ->first();

            if (!$template) {
                return response()->json([
                    'message' => "Selected template is not active or not configured for {$channelLabel}.",
                ], 422);
            }
        }

        $messageBody = trim((string) ($validated['message'] ?? ''));
        $rendered = null;

        if ($template) {
            if (
                $template->platform_id &&
                (int) $template->platform_id !== (int) $client->platform_id
            ) {
                return response()->json([
                    'message' => 'Selected template does not belong to this client market.',
                ], 422);
            }

            $variables = $this->templateService->buildClientVariables(
                $client,
                $client->activeDeal,
                ['actor_name' => $request->user()->name]
            );

            $rendered = $this->templateService->renderTemplate($template, $variables);
            if (!empty($rendered['missing'])) {
                return response()->json([
                    'message' => 'Template rendering missing variables.',
                    'missing' => $rendered['missing'],
                ], 422);
            }

            $messageBody = trim((string) $rendered['body']);
        }

        if ($messageBody === '') {
            return response()->json(['message' => 'Message content is required.'], 422);
        }

        $dispatch = $this->messagingDispatcher->dispatch(MessageRecipient::fromClient($client), $messageBody, $channel, [
            'template_id' => $template?->id,
            'conversation' => true,
            'phone_prefix' => $client->platform->phone_prefix ?? '254',
            'message_type' => 'conversation',
            'actor_id' => $request->user()->id,
            'suppress_gateway_timeline' => $channel === 'whatsapp',
            'idempotency_key' => 'conversation-' . $client->id . '-' . sha1($channel . '|' . $messageBody . '|' . microtime(true)),
        ]);
        $delivery = $this->serializeDelivery($dispatch);

        $note = ClientNote::create([
            'client_id' => $client->id,
            'author_id' => $request->user()->id,
            'note_type' => $channel,
            'content' => $messageBody,
            'follow_up_at' => $validated['follow_up_at'] ?? null,
            'created_at' => now(),
        ]);

        $eventType = $delivery['success']
            ? ($channel === 'whatsapp' ? CrmAuditAction::CONVERSATION_WHATSAPP_SENT : CrmAuditAction::CONVERSATION_SMS_SENT)
            : ($channel === 'whatsapp' ? CrmAuditAction::CONVERSATION_WHATSAPP_FAILED : CrmAuditAction::CONVERSATION_SMS_FAILED);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => $eventType,
            'actor_id' => $request->user()->id,
            'content' => [
                'note_id' => $note->id,
                'template_id' => $template?->id,
                'channel' => $channel,
                'delivery_status' => $delivery['status'],
                'provider_response' => $delivery['provider_response'] ?? null,
                'whatsapp_message_id' => $delivery['whatsapp_message_id'] ?? null,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            $client->platform_id,
            $eventType,
            'client',
            $client->id,
            null,
            [
                'template_id' => $template?->id,
                'channel' => $channel,
                'delivery_status' => $delivery['status'],
                'note_id' => $note->id,
                'whatsapp_message_id' => $delivery['whatsapp_message_id'] ?? null,
            ],
            'Conversation composer send'
        );

        $note->load('author');

        return response()->json([
            'note' => $note,
            'delivery' => $delivery,
            'template_id' => $template?->id,
            'rendered' => $rendered,
        ], 201);
    }

    private function serializeDelivery(DispatchResult $dispatch): array
    {
        $payload = $dispatch->toArray();
        $payload['provider_response'] = $dispatch->smsResult['provider_response']
            ?? $dispatch->errorMessage
            ?? ($dispatch->success ? 'Message accepted by provider.' : 'Message could not be sent.');

        return $payload;
    }
}
