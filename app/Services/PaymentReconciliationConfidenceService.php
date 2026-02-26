<?php

namespace App\Services;

class PaymentReconciliationConfidenceService
{
    public function fromMatchConfidence(?string $matchConfidence): string
    {
        return match ($matchConfidence) {
            'manual', 'auto_high' => 'high',
            'auto_low' => 'medium',
            default => 'low',
        };
    }

    public function fromImportSuggestion(?array $suggestedMatch, array $normalizedRow): string
    {
        $suggested = strtolower((string) ($suggestedMatch['confidence'] ?? ''));
        if ($suggested === 'auto_high') {
            return 'high';
        }

        if ($suggested === 'auto_low') {
            return 'medium';
        }

        $hasPhone = trim((string) ($normalizedRow['phone'] ?? '')) !== '';
        $hasReference = trim((string) ($normalizedRow['transaction_reference'] ?? '')) !== '';

        if ($hasPhone && $hasReference) {
            return 'medium';
        }

        return 'low';
    }
}
