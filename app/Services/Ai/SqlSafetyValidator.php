<?php

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\SqlValidationException;

/**
 * Safety-critical gate for the "Talk to Your Data" (NL->SQL) feature.
 *
 * The model is NEVER trusted to scope or restrict itself. This validator is the
 * primary guard (the SELECT-only mysql_readonly account is defense-in-depth).
 *
 * A candidate statement is accepted only if ALL of the following hold:
 *  - it is a single statement (no stacked queries / trailing statements),
 *  - it begins with SELECT (or a parenthesised SELECT),
 *  - it contains no SQL comments (--, #, block),
 *  - every table reference is an allow-listed vw_* reporting view,
 *  - it contains no DDL/DML/admin keyword anywhere,
 *  - a bounded LIMIT is present (injected/clamped to the configured maximum).
 *
 * When market scoping is active (sub-admins), the validated statement is wrapped
 * in an outer projection that filters on platform_id server-side, so the model
 * cannot widen its own visibility regardless of what it generated.
 */
class SqlSafetyValidator
{
    /**
     * Keywords that must never appear (word-boundary matched, case-insensitive).
     * Covers DDL, DML, transaction/admin, and exfiltration verbs.
     */
    private const FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'replace', 'merge', 'upsert', 'grant', 'revoke', 'rename',
        'call', 'exec', 'execute', 'do', 'handler',
        'lock', 'unlock', 'set', 'use', 'reset', 'flush', 'kill',
        'into', 'load', 'outfile', 'dumpfile', 'infile',
        'attach', 'detach', 'pragma', 'vacuum', 'reindex', 'analyze',
        'commit', 'rollback', 'savepoint', 'begin', 'start',
        'prepare', 'deallocate', 'declare', 'values',
    ];

    public function __construct(
        private readonly AiInsightsSettingsService $settings,
    ) {}

    /**
     * Validate and harden a candidate SQL string.
     *
     * @param  string    $sql                Raw model-generated SQL.
     * @param  int[]|null $allowedPlatformIds null = org-wide (CEO/admin, no scope filter);
     *                                        array = restrict to these platform_ids (sub-admin).
     * @return array{sql:string, limit:int, views:string[], scoped:bool}
     *
     * @throws SqlValidationException
     */
    public function validate(string $sql, ?array $allowedPlatformIds = null): array
    {
        $normalized = $this->normalize($sql);

        if ($normalized === '') {
            throw new SqlValidationException('No SQL statement was provided.', 'empty');
        }

        $this->rejectComments($normalized);
        $this->rejectMultipleStatements($normalized);

        // Strip the single optional trailing semicolon now that we know there is
        // at most one statement.
        $statement = rtrim(rtrim($normalized), ';');

        $this->requireSelect($statement);
        $this->rejectForbiddenKeywords($statement);

        $views = $this->extractAndValidateViews($statement);

        $limit = $this->resolveLimit($statement);

        $scoped = is_array($allowedPlatformIds);

        if ($scoped) {
            $final = $this->wrapWithScope($statement, $allowedPlatformIds, $limit);
        } else {
            $final = $this->applyOuterLimit($statement, $limit);
        }

        return [
            'sql'    => $final,
            'limit'  => $limit,
            'views'  => $views,
            'scoped' => $scoped,
        ];
    }

    private function normalize(string $sql): string
    {
        // Collapse all whitespace (incl. newlines/tabs) to single spaces and trim.
        $collapsed = preg_replace('/\s+/', ' ', trim($sql)) ?? '';

        return trim($collapsed);
    }

    private function rejectComments(string $sql): void
    {
        // Line comments (-- or #) and block comments (/* */), including the
        // optimizer-hint form /*+ ... */.
        if (preg_match('/--|#|\/\*/', $sql)) {
            throw new SqlValidationException('SQL comments are not allowed.', 'comment');
        }
    }

    private function rejectMultipleStatements(string $sql): void
    {
        // Any semicolon that is not the single optional trailing one means a
        // stacked / multi statement payload.
        $trimmed = rtrim($sql);
        $withoutTrailing = rtrim($trimmed, ';');

        if (str_contains($withoutTrailing, ';')) {
            throw new SqlValidationException('Multiple SQL statements are not allowed.', 'multi_statement');
        }
    }

    private function requireSelect(string $statement): void
    {
        // Allow a leading parenthesis for "(SELECT ...)" forms, but the first
        // keyword must be SELECT or WITH (CTE) ... we forbid WITH to keep the
        // surface minimal and predictable.
        $lead = ltrim($statement, " (\t\n");

        if (!preg_match('/^select\b/i', $lead)) {
            throw new SqlValidationException('Only SELECT statements are allowed.', 'not_select');
        }
    }

    private function rejectForbiddenKeywords(string $statement): void
    {
        $lower = mb_strtolower($statement);

        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $lower)) {
                throw new SqlValidationException(
                    "Disallowed keyword in query: {$keyword}.",
                    'forbidden_keyword'
                );
            }
        }
    }

    /**
     * Every table-like reference (FROM x, JOIN x) must be an allow-listed view.
     *
     * @return string[] The distinct views referenced.
     */
    private function extractAndValidateViews(string $statement): array
    {
        $allowed = array_map('strtolower', (array) config('ai.reporting_views', []));

        if (preg_match_all('/\b(?:from|join)\s+([`"\[]?)([a-z_][a-z0-9_]*)\1/i', $statement, $matches)) {
            $referenced = array_map('strtolower', $matches[2]);
        } else {
            $referenced = [];
        }

        if ($referenced === []) {
            throw new SqlValidationException('Query must read from a reporting view.', 'no_table');
        }

        foreach ($referenced as $table) {
            if (!str_starts_with($table, 'vw_') || !in_array($table, $allowed, true)) {
                throw new SqlValidationException(
                    "Table '{$table}' is not an allow-listed reporting view.",
                    'table_not_allowed'
                );
            }
        }

        return array_values(array_unique($referenced));
    }

    /**
     * Pull an existing trailing LIMIT (clamping it) or signal that one must be
     * injected. We never trust an unbounded query.
     */
    private function resolveLimit(string $statement): int
    {
        $max     = $this->settings->maxRowLimit();
        $default = min($this->settings->defaultRowLimit(), $max);

        if (preg_match('/\blimit\s+(\d+)(?:\s*,\s*(\d+))?\s*$/i', $statement, $m)) {
            // Forms: LIMIT n  |  LIMIT offset, count
            $value = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : (int) $m[1];

            return max(1, min($value, $max));
        }

        return $default;
    }

    private function applyOuterLimit(string $statement, int $limit): string
    {
        $withoutLimit = $this->stripTrailingLimit($statement);

        return "{$withoutLimit} LIMIT {$limit}";
    }

    /**
     * Wrap the (validated) inner query and filter on platform_id server-side.
     * Requires the inner projection to expose platform_id (SELECT * or an explicit
     * platform_id column) so scoping cannot be silently bypassed.
     */
    private function wrapWithScope(string $statement, array $allowedPlatformIds, int $limit): string
    {
        if (!$this->projectsPlatformId($statement)) {
            throw new SqlValidationException(
                'Scoped queries must select platform_id so market access can be enforced.',
                'missing_platform_id'
            );
        }

        $inner = $this->stripTrailingLimit($statement);

        $ids = array_values(array_unique(array_map('intval', $allowedPlatformIds)));

        if ($ids === []) {
            // No markets assigned -> deliberately match nothing.
            $predicate = '0 = 1';
        } else {
            $predicate = 'scoped_q.platform_id IN (' . implode(', ', $ids) . ')';
        }

        return "SELECT * FROM ({$inner}) AS scoped_q WHERE {$predicate} LIMIT {$limit}";
    }

    private function projectsPlatformId(string $statement): bool
    {
        // Isolate the projection list between SELECT and FROM.
        if (!preg_match('/^\s*\(?\s*select\b(.*?)\bfrom\b/is', $statement, $m)) {
            return false;
        }

        $projection = mb_strtolower($m[1]);

        return str_contains($projection, '*') || preg_match('/\bplatform_id\b/', $projection) === 1;
    }

    private function stripTrailingLimit(string $statement): string
    {
        return rtrim(preg_replace('/\blimit\s+\d+(?:\s*,\s*\d+)?\s*$/i', '', $statement) ?? $statement);
    }
}
