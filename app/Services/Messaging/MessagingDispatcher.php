<?php

namespace App\Services\Messaging;

use App\Services\NotificationService;

class MessagingDispatcher
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly WhatsAppGatewayService $whatsAppGatewayService,
    ) {
    }

    public function dispatch(MessageRecipient $to, string $body, string $channelPreference, array $context = []): DispatchResult
    {
        return match ($channelPreference) {
            'sms' => $this->dispatchSms($to, $body, $context),
            'whatsapp' => $this->dispatchWhatsApp($to, $body, $context),
            'whatsapp_with_sms_fallback' => $this->dispatchWhatsAppWithFallback($to, $body, $context),
            default => new DispatchResult(false, $channelPreference, 'failed', errorCode: 'unsupported_channel', errorMessage: 'Unsupported channel preference.'),
        };
    }

    private function dispatchSms(MessageRecipient $to, string $body, array $context): DispatchResult
    {
        $smsResult = $this->notificationService->sendSms($to->phoneE164, $body, array_merge($context, [
            'platform_id' => $to->platformId,
            'client_id' => $to->clientId,
            'lead_id' => $to->leadId,
            'deal_id' => $to->dealId,
            'payment_id' => $to->paymentId,
        ]));

        return new DispatchResult(
            success: (bool) ($smsResult['success'] ?? false),
            channel: 'sms',
            status: (string) ($smsResult['status'] ?? 'failed'),
            smsResult: $smsResult,
        );
    }

    private function dispatchWhatsApp(MessageRecipient $to, string $body, array $context): DispatchResult
    {
        return $this->whatsAppGatewayService->send($this->sendRequest($to, $body, $context));
    }

    private function dispatchWhatsAppWithFallback(MessageRecipient $to, string $body, array $context): DispatchResult
    {
        $whatsAppResult = $this->dispatchWhatsApp($to, $body, $context);

        if ($whatsAppResult->success) {
            return $whatsAppResult;
        }

        $smsResult = $this->dispatchSms($to, $body, $context);

        return new DispatchResult(
            success: $smsResult->success,
            channel: $smsResult->channel,
            status: $smsResult->status,
            whatsAppMessage: $whatsAppResult->whatsAppMessage,
            smsResult: $smsResult->smsResult,
            errorCode: $whatsAppResult->errorCode,
            errorMessage: $whatsAppResult->errorMessage,
            fallbackAttempted: true,
        );
    }

    private function sendRequest(MessageRecipient $to, string $body, array $context): SendRequest
    {
        return new SendRequest(
            recipient: $to,
            body: $body,
            messageType: (string) ($context['message_type'] ?? 'transactional'),
            idempotencyKey: $context['idempotency_key'] ?? null,
            templateId: isset($context['template_id']) ? (int) $context['template_id'] : null,
            templateName: $context['template_name'] ?? null,
            templateLanguage: (string) ($context['template_language'] ?? 'en_US'),
            templateComponents: (array) ($context['template_components'] ?? []),
            mediaUrl: $context['media_url'] ?? null,
            context: $context,
        );
    }
}
