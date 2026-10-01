<?php

namespace App\Services\DbScanner\Rules;

/**
 * One value cell (or one string leaf inside a serialized/JSON cell) as a
 * matcher sees it.
 */
final class RowContext
{
    /**
     * @param  array<string, mixed>  $labels
     */
    public function __construct(
        public readonly string $surface,
        public readonly string $table,
        public readonly int|string $rowId,
        public readonly string $field,
        public readonly array $labels,
        public readonly string $objectType,
        public readonly int|string|null $objectId,
        public readonly bool $fullyRead,
        public readonly int $octetLength,
        public readonly ?string $valueHash = null,
    ) {}

    public function label(string $key): ?string
    {
        $value = $this->labels[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    /** Option name, meta key, post type, snippet name… whichever names this row. */
    public function name(): string
    {
        return (string) ($this->labels['option_name'] ?? $this->labels['meta_key'] ?? $this->labels['name'] ?? $this->labels['post_type'] ?? $this->labels['taxonomy'] ?? $this->labels['user_login'] ?? '');
    }

    public function subject(?string $item = null): array
    {
        return array_filter([
            'surface' => $this->surface,
            'table' => $this->table,
            'row_id' => $this->rowId,
            'field' => $this->field,
            'object_type' => $this->objectType,
            'object_id' => $this->objectId,
            'label' => $this->name() !== '' ? mb_substr($this->name(), 0, 120) : null,
            'item' => $item,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
