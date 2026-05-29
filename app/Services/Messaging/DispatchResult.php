<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppMessage;

final class DispatchResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $channel,
        public readonly string $status,
        public readonly ?WhatsAppMessage $whatsAppMessage = null,
        public readonly ?array $smsResult = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $fallbackAttempted = false,
        public readonly bool $shouldFallbackToSms = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'channel' => $this->channel,
            'status' => $this->status,
            'whatsapp_message_id' => $this->whatsAppMessage?->id,
            'sms_result' => $this->smsResult,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'fallback_attempted' => $this->fallbackAttempted,
            'should_fallback_to_sms' => $this->shouldFallbackToSms,
        ];
    }
}
