<?php

namespace App\Services\DbScanner\Surfaces;

final class SchemaInfo
{
    /** @var array<int, string> */
    public array $missingCore = [];

    public bool $multisite = false;

    /** @var array<int, string> */
    public array $otherPrefixes = [];

    /** @var array<string, array<string, string>> */
    public array $columns = [];

    /** @var array<int, string> */
    public array $unprefixed = [];

    /** @var array<int, string> custom text-bearing tables discovered for later adapter review */
    public array $customTables = [];

    /**
     * @param  array<string, array>  $tables
     * @param  array<int, string>  $prefixes
     */
    public function __construct(
        public readonly string $prefix,
        public readonly array $tables,
        public readonly array $prefixes,
    ) {}

    public function has(string $suffix): bool
    {
        return isset($this->columns[$this->prefix.$suffix]);
    }

    public function table(string $suffix): string
    {
        return $this->prefix.$suffix;
    }

    public function hasColumn(string $suffix, string $column): bool
    {
        return isset($this->columns[$this->prefix.$suffix][$column]);
    }

    /**
     * A reason the market cannot be scanned as a single-site schema, or null.
     */
    public function unsupportedReason(): ?string
    {
        if ($this->missingCore !== []) {
            return 'missing_core_tables';
        }

        if ($this->multisite) {
            return 'multisite_unsupported';
        }

        return null;
    }

    public function summary(): array
    {
        return [
            'prefix' => $this->prefix,
            'table_count' => count($this->tables),
            'missing_core' => $this->missingCore,
            'multisite' => $this->multisite,
            'other_prefixes' => $this->otherPrefixes,
            'custom_tables' => array_slice($this->customTables, 0, 50),
            'unprefixed_tables' => array_slice($this->unprefixed, 0, 50),
        ];
    }
}
