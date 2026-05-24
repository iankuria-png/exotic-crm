<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Messaging\Inbound\MetaWebhookHandler;
use Illuminate\Http\Request;

class MessagingWebhookController extends Controller
{
    public function __construct(
        private readonly MetaWebhookHandler $metaWebhookHandler,
    ) {
    }

    public function verifyMeta(Request $request)
    {
        $challenge = $this->metaWebhookHandler->verifyChallenge(
            (string) $request->query('hub_mode', $request->query('hub.mode', '')),
            (string) $request->query('hub_verify_token', $request->query('hub.verify_token', '')),
            (string) $request->query('hub_challenge', $request->query('hub.challenge', ''))
        );

        if ($challenge === null) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receiveMeta(Request $request)
    {
        $result = $this->metaWebhookHandler->handle(
            $request->getContent(),
            $request->json()->all(),
            (string) $request->header('X-Hub-Signature-256', '')
        );

        if (!($result['verified'] ?? false)) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
                'error_code' => 'webhook_verification_failed',
            ], 401);
        }

        return response()->json([
            'message' => 'Meta webhook processed.',
            'processed' => $result['processed'] ?? 0,
            'duplicates' => $result['duplicates'] ?? 0,
        ]);
    }
}
