<?php

namespace App\Support;

final class DeactivationRequest
{
    public function __construct(
        public readonly DealDeactivationReason $reasonCode,
        public readonly ?string $reasonNotes = null,
        public readonly ?LinkedPaymentAction $linkedPaymentAction = null
    ) {}

    public function resolvedLinkedPaymentAction(): LinkedPaymentAction
    {
        return $this->linkedPaymentAction ?? $this->reasonCode->defaultLinkedPaymentAction();
    }

    public function shouldFlagClientHighRisk(): bool
    {
        return $this->reasonCode->shouldFlagClientHighRisk();
    }

    public function auditReason(): string
    {
        $label = str_replace('_', ' ', $this->reasonCode->value);
        $notes = trim((string) ($this->reasonNotes ?? ''));

        return $notes !== '' ? "{$label}: {$notes}" : $label;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reason_code' => $this->reasonCode->value,
            'reason_notes' => $this->reasonNotes,
            'linked_payment_action' => $this->resolvedLinkedPaymentAction()->value,
        ];
    }
}
