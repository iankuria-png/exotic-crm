<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppProviderProfile;

final class SendRequest
{
    public function __construct(
        public readonly MessageRecipient $recipient,
        public readonly string $body,
        public readonly string $messageType = 'transactional',
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $templateId = null,
        public readonly ?string $templateName = null,
        public readonly string $templateLanguage = 'en_US',
        public readonly array $templateComponents = [],
        public readonly ?string $mediaUrl = null,
        public readonly array $context = [],
        public readonly ?WhatsAppProviderProfile $profile = null,
    ) {
    }

    public function withProfile(WhatsAppProviderProfile $profile): self
    {
        return new self(
            recipient: $this->recipient,
            body: $this->body,
            messageType: $this->messageType,
            idempotencyKey: $this->idempotencyKey,
            templateId: $this->templateId,
            templateName: $this->templateName,
            templateLanguage: $this->templateLanguage,
            templateComponents: $this->templateComponents,
            mediaUrl: $this->mediaUrl,
            context: $this->context,
            profile: $profile,
        );
    }
}
