<?php

namespace App\Services\DbScanner\Surfaces;

use App\Services\DbScanner\Reader\MarketDbReader;

/**
 * Enumerate base tables, validate the configured prefix against them and
 * bind column metadata into the reader's compiler.
 *
 * Multiple WordPress prefixes or multisite tables are reported as unsupported
 * scope rather than silently attributing another blog's rows to this market.
 */
class SchemaDiscovery
{
    public const CORE_TABLES = ['options', 'users', 'usermeta', 'posts', 'postmeta'];

    private const KNOWN_TABLES = [
        'options', 'users', 'usermeta', 'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta',
        'term_taxonomy', 'term_relationships', 'links', 'snippets', 'actionscheduler_actions', 'actionscheduler_claims',
        'actionscheduler_groups', 'actionscheduler_logs', 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups',
        'registration_log', 'blog_versions', 'aryo_activity_log', 'email_log',
    ];

    public function discover(MarketDbReader $reader, string $prefix): SchemaInfo
    {
        $compiler = $reader->compiler();
        $rows = $reader->select($compiler->baseTables());

        $tables = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || ! preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
                continue;
            }
            $tables[$name] = [
                'engine' => $row['engine'] ?? null,
                'row_estimate' => isset($row['row_estimate']) ? (int) $row['row_estimate'] : null,
                'bytes' => isset($row['bytes']) ? (int) $row['bytes'] : null,
                'collation' => $row['collation'] ?? null,
            ];
        }

        // Candidate WordPress prefixes: anything that owns an *options table
        // alongside *posts and *users.
        $prefixes = [];
        foreach (array_keys($tables) as $name) {
            if (str_ends_with($name, 'options')) {
                $candidate = substr($name, 0, -strlen('options'));
                if (isset($tables[$candidate.'posts'], $tables[$candidate.'users'])) {
                    $prefixes[] = $candidate;
                }
            }
        }

        $info = new SchemaInfo($prefix, $tables, $prefixes);

        foreach (self::CORE_TABLES as $core) {
            if (! isset($tables[$prefix.$core])) {
                $info->missingCore[] = $core;
            }
        }

        if (isset($tables[$prefix.'blogs']) || isset($tables[$prefix.'sitemeta'])) {
            $info->multisite = true;
        }

        $others = array_values(array_diff($prefixes, [$prefix]));
        // Multisite child blogs look like "<prefix><n>_options"; foreign
        // prefixes are separate installs sharing this schema.
        $info->otherPrefixes = array_values(array_filter($others, fn ($p) => ! preg_match('/^'.preg_quote($prefix, '/').'\d+_$/', $p)));

        $wanted = array_values(array_filter(
            array_map(fn ($t) => $prefix.$t, self::KNOWN_TABLES),
            fn ($t) => isset($tables[$t])
        ));

        $columns = [];
        if ($wanted !== []) {
            foreach ($reader->select($compiler->columns($wanted)) as $row) {
                $columns[(string) $row['tbl']][(string) $row['col']] = (string) ($row['type'] ?? '');
            }
        }
        $info->columns = $columns;
        $compiler->bindSchema($columns);

        foreach (array_keys($tables) as $name) {
            if (! str_starts_with($name, $prefix)) {
                $info->unprefixed[] = $name;

                continue;
            }
            $suffix = substr($name, strlen($prefix));
            if (! in_array($suffix, self::KNOWN_TABLES, true)) {
                $info->customTables[] = $name;
            }
        }

        return $info;
    }
}
