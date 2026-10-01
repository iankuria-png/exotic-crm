<?php

namespace App\Services\DbScanner\Rules;

/**
 * One rule match on one subject. Evidence is already sanitized and inert.
 */
final class Hit
{
    /**
     * @param  array<string, mixed>  $subject  surface, table, row_id, field, object_type, object_id, label
     * @param  array<string, mixed>  $evidence  excerpts, signals, transformations, counts
     */
    public function __construct(
        public readonly string $ruleKey,
        public readonly string $title,
        public readonly array $subject,
        public readonly array $evidence,
        public readonly ?string $confidence = null,
        public readonly ?string $behavior = null,
        public readonly ?string $severity = null,
        public readonly ?string $payloadHash = null,
        public readonly ?string $payloadHashType = null,
    ) {}

    /**
     * Canonical subject identity: the same row/field/item always hashes the
     * same, independent of evidence or rule version.
     */
    public function subjectKey(): string
    {
        $parts = [
            $this->subject['surface'] ?? '',
            $this->subject['table'] ?? '',
            (string) ($this->subject['row_id'] ?? ''),
            $this->subject['field'] ?? '',
            $this->subject['item'] ?? '',
        ];

        return implode('|', $parts);
    }

    public function subjectHash(): string
    {
        return hash('sha256', $this->subjectKey());
    }
}
