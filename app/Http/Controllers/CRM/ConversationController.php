<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\NotificationService;
use App\Services\TemplateService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
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
            'follow_up_at' => 'nullable|date',
        ]);

        $client->loadMissing(['platform', 'activeDeal.product']);

        $template = null;
        if (!empty($validated['template_id'])) {
            $template = Template::query()
                ->where('id', (int) $validated['template_id'])
                ->where('channel', 'sms')
                ->where('status', 'active')
                ->first();

            if (!$template) {
                return response()->json([
                    'message' => 'Selected template is not active or not configured for SMS.',
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

        $delivery = $this->notificationService->sendSmsToClient($client, $messageBody, [
            'template_id' => $template?->id,
            'conversation' => true,
            'phone_prefix' => $client->platform->phone_prefix ?? '254',
        ]);

        $note = ClientNote::create([
            'client_id' => $client->id,
            'author_id' => $request->user()->id,
            'note_type' => 'sms',
            'content' => $messageBody,
            'follow_up_at' => $validated['follow_up_at'] ?? null,
            'created_at' => now(),
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => $delivery['success'] ? 'conversation_sms_sent' : 'conversation_sms_failed',
            'actor_id' => $request->user()->id,
            'content' => [
                'note_id' => $note->id,
                'template_id' => $template?->id,
                'delivery_status' => $delivery['status'],
                'provider_response' => $delivery['provider_response'] ?? null,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            $client->platform_id,
            $delivery['success'] ? CrmAuditAction::CONVERSATION_SMS_SENT : CrmAuditAction::CONVERSATION_SMS_FAILED,
            'client',
            $client->id,
            null,
            [
                'template_id' => $template?->id,
                'delivery_status' => $delivery['status'],
                'note_id' => $note->id,
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
}
