<?php

namespace App\Services\DbScanner\Surfaces;

/**
 * A named, bounded place the scanner reads. Surfaces are code; rules only
 * reference them by key.
 *
 * kind "rows": keyset traversal of a table (pk ranges → labels/lengths →
 *              byte-bounded values).
 * kind "inventory": a small fixed set of reviewed metadata/aggregate reads.
 */
final class Surface
{
    /**
     * @param  array<int, string>  $labels  non-secret label columns read during enumeration
     * @param  array<int, string>  $values  value columns fetched byte-bounded after secret filtering
     * @param  array<int, array>  $predicates  QueryCompiler predicate DSL applied inside each range
     * @param  array<int, string>  $profiles
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $table = null,
        public readonly ?string $pk = null,
        public readonly array $labels = [],
        public readonly array $values = [],
        public readonly array $predicates = [],
        public readonly array $profiles = ['quick', 'standard', 'deep'],
        public readonly bool $core = true,
        public readonly ?string $secretLabel = null,
        public readonly string $objectType = 'row',
        public readonly int $weight = 1,
        public readonly string $adapterVersion = '1',
    ) {}

    public function isRows(): bool
    {
        return $this->kind === 'rows';
    }

    public function inProfile(string $profile): bool
    {
        return in_array($profile, $this->profiles, true);
    }

    public function physicalTable(string $prefix): ?string
    {
        return $this->table === null ? null : $prefix.$this->table;
    }
}
