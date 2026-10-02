<?php

namespace App\Services\DbScanner\Engine;

use App\Services\DbScanner\Malware\BoundedDecoder;
use App\Services\DbScanner\Malware\ContentAnalysis;
use App\Services\DbScanner\Malware\HostContext;
use App\Services\DbScanner\Malware\SerializedParser;
use App\Services\DbScanner\Reader\MarketDbReader;
use App\Services\DbScanner\Rules\RowContext;
use App\Services\DbScanner\Rules\RowMatchers;
use App\Services\DbScanner\Rules\RuleSet;
use App\Services\DbScanner\Surfaces\SchemaInfo;
use App\Services\DbScanner\Surfaces\SecretPolicy;
use App\Services\DbScanner\Surfaces\Surface;

/**
 * Processes one bounded key range of a row surface:
 *
 * 1. index-only range end (cursor .. high water, at most N keys);
 * 2. enumerate keys, label columns and value byte lengths inside the range
 *    with the surface's reviewed predicates — never values;
 * 3. drop secret-named cells whole before any value is selected;
 * 4. fetch remaining values in sub-batches whose projected bytes fit the
 *    4 MiB chunk budget, each value sliced to 64 KiB by bytes;
 * 5. run every active row rule over each value (or each string leaf of a
 *    serialized/JSON value), one value in memory at a time.
 *
 * The cursor advances to the enumerated range end even when nothing in the
 * range matched the predicates, so empty stretches always make progress.
 */
class SurfaceScanner
{
    private const ROW_OVERHEAD = 512;

    /** Matchers that judge a cell by its name or structure, not its text leaves. */
    public const WHOLE_VALUE_MATCHERS = ['ioc_option_names', 'lookalike_options', 'serialized_objects'];

    public function __construct(
        private readonly RowMatchers $matchers,
        private readonly SecretPolicy $secrets,
    ) {}

    /**
     * @param  callable  $checkpoint  called between matcher batches (pause/time checks)
     */
    public function processChunk(
        MarketDbReader $reader,
        Surface $surface,
        SchemaInfo $schema,
        int $cursor,
        int $highWater,
        int $batchRows,
        RuleSet $rules,
        HostContext $hosts,
        callable $checkpoint,
        ?int $sampleLimit = null,
    ): ChunkResult {
        $c = $reader->compiler();
        $table = $schema->table($surface->table);
        $valueCap = (int) config('db_scanner.envelope.value_bytes', 65536);
        $chunkBytes = (int) config('db_scanner.envelope.chunk_bytes', 4 * 1024 * 1024);

        $range = $reader->select($c->rangeEnd($table, $surface->pk, $cursor, $highWater, $batchRows))[0] ?? [];
        $end = $range['range_end'] ?? null;
        $result = new ChunkResult($cursor, $end === null ? $highWater : (int) $end);
        if ($end === null || (int) ($range['n'] ?? 0) === 0) {
            $result->exhausted = true;

            return $result;
        }
        $result->exhausted = (int) $end >= $highWater;

        $labels = array_values(array_filter($surface->labels, fn ($l) => $schema->hasColumn($surface->table, $l)));
        $values = array_values(array_filter($surface->values, fn ($v) => $schema->hasColumn($surface->table, $v)));
        $predicates = array_values(array_filter($surface->predicates, fn ($p) => isset($p['or']) || $schema->hasColumn($surface->table, (string) $p[0])));

        $enumerated = $reader->select($c->enumerate($table, $surface->pk, $labels, $values, $cursor, (int) $end, $predicates));
        $result->rows = count($enumerated);

        $ruleKeys = $rules->rulesForSurface($surface->key);
        if ($ruleKeys === [] || $values === []) {
            return $result;
        }

        // Secret filtering happens on names, before any value is fetched.
        $rows = [];
        foreach ($enumerated as $row) {
            if ($surface->secretLabel && $this->secrets->isProtected((string) ($row[$surface->secretLabel] ?? ''))) {
                $result->excluded++;
                // Name and byte length only: bulk autoloaded secrets (per-user
                // login tokens and the like) load into every request.
                if ($surface->key === 'options.values' && $rules->active('hygiene.autoloaded_secret_options')
                    && in_array(strtolower((string) ($row['autoload'] ?? '')), ['yes', 'on', 'auto-on', 'auto'], true)) {
                    $pattern = preg_replace('/[0-9a-f]{8,}|\d+/i', '#', (string) $row[$surface->secretLabel]) ?? '';
                    $bytes = 0;
                    foreach ($values as $column) {
                        $bytes += (int) ($row['__len_'.$column] ?? 0);
                    }
                    $result->accumulator->add('hygiene.autoloaded_secret_options', mb_substr($pattern, 0, 120), (int) $row['__k'], $bytes);
                }

                continue;
            }
            $rows[] = $row;
        }
        $result->candidates = count($rows);

        // Byte-bounded sub-batches from enumerated lengths.
        $batches = [];
        $current = [];
        $currentBytes = 0;
        foreach ($rows as $row) {
            $projected = self::ROW_OVERHEAD;
            foreach ($values as $column) {
                $projected += min($valueCap, (int) ($row['__len_'.$column] ?? 0));
            }
            if ($current !== [] && ($currentBytes + $projected > $chunkBytes || count($current) >= 500)) {
                $batches[] = $current;
                $current = [];
                $currentBytes = 0;
            }
            $current[] = $row;
            $currentBytes += $projected;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        $decoder = new BoundedDecoder;
        $matcherStarted = hrtime(true);

        foreach ($batches as $batch) {
            $byKey = [];
            foreach ($batch as $row) {
                $byKey[(int) $row['__k']] = $row;
            }
            $fetched = $reader->select($c->fetchValues($table, $surface->pk, $values, array_keys($byKey), $valueCap));

            foreach ($fetched as $valueRow) {
                $key = (int) $valueRow['__k'];
                $meta = $byKey[$key] ?? null;
                if ($meta === null) {
                    continue;
                }
                $labelValues = array_intersect_key($meta, array_flip($labels));

                foreach ($values as $column) {
                    $raw = $valueRow[$column] ?? null;
                    if (! is_string($raw) || $raw === '') {
                        unset($valueRow[$column]);

                        continue;
                    }
                    $length = (int) ($meta['__len_'.$column] ?? strlen($raw));
                    $fullyRead = $length <= $valueCap;
                    if (! $fullyRead) {
                        $result->truncated++;
                    }
                    $result->bytes += strlen($raw);
                    $text = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_scrub($raw, 'UTF-8');
                    unset($valueRow[$column], $raw);

                    foreach ($this->leaves($text, $column) as [$field, $leaf, $view]) {
                        $ctx = new RowContext(
                            surface: $surface->key,
                            table: $table,
                            rowId: $key,
                            field: $field,
                            labels: $labelValues,
                            objectType: $surface->objectType,
                            objectId: $this->objectId($surface, $key, $labelValues),
                            fullyRead: $fullyRead,
                            octetLength: $length,
                            valueHash: hash('sha256', $leaf),
                        );
                        $analysis = new ContentAnalysis($leaf, $decoder, $hosts);

                        foreach ($ruleKeys as $ruleKey) {
                            $matcher = (string) ($rules->rule($ruleKey)['matcher'] ?? '');
                            $wholeMatcher = in_array($matcher, self::WHOLE_VALUE_MATCHERS, true);
                            if (($view === 'whole' && ! $wholeMatcher) || ($view === 'leaf' && $wholeMatcher)) {
                                continue;
                            }
                            try {
                                $hit = $this->matchers->run($matcher, $ruleKey, $rules, $ctx, $analysis, $result->accumulator);
                            } catch (\Throwable) {
                                $result->matcherErrors++;

                                continue;
                            }
                            if ($hit) {
                                $result->hits[] = $hit;
                                $result->ruleMatches[$ruleKey] = ($result->ruleMatches[$ruleKey] ?? 0) + 1;
                            }
                        }
                        if ($analysis->decoded()->capped()) {
                            $result->decodeCapped++;
                        }
                        unset($analysis);

                        if ((hrtime(true) - $matcherStarted) / 1e6 > 50) {
                            $checkpoint();
                            $matcherStarted = hrtime(true);
                        }
                        if ($sampleLimit !== null && count($result->hits) >= $sampleLimit) {
                            return $result;
                        }
                    }
                    unset($text);
                }
            }
            unset($fetched, $byKey);
        }

        return $result;
    }

    /**
     * Re-read one row/field and re-run one rule (resolution point recheck).
     *
     * @return array{hit: bool, fully_read: bool, missing: bool}
     */
    public function recheck(MarketDbReader $reader, Surface $surface, SchemaInfo $schema, int $rowId, string $field, string $ruleKey, RuleSet $rules, HostContext $hosts): array
    {
        $c = $reader->compiler();
        $table = $schema->table($surface->table);
        $column = preg_replace('/\[.*$/', '', $field);
        if (! $schema->hasColumn($surface->table, (string) $column)) {
            return ['hit' => false, 'fully_read' => false, 'missing' => false];
        }
        $valueCap = (int) config('db_scanner.envelope.value_bytes', 65536);
        $labels = array_values(array_filter($surface->labels, fn ($l) => $schema->hasColumn($surface->table, $l)));

        $meta = $reader->select($c->enumerate($table, $surface->pk, $labels, [$column], $rowId - 1, $rowId, []));
        if ($meta === []) {
            return ['hit' => false, 'fully_read' => true, 'missing' => true];
        }
        $meta = $meta[0];
        if ($surface->secretLabel && $this->secrets->isProtected((string) ($meta[$surface->secretLabel] ?? ''))) {
            return ['hit' => false, 'fully_read' => false, 'missing' => false];
        }
        $length = (int) ($meta['__len_'.$column] ?? 0);
        $row = $reader->select($c->fetchValues($table, $surface->pk, [$column], [$rowId], $valueCap))[0] ?? null;
        $raw = is_string($row[$column] ?? null) ? $row[$column] : '';
        $text = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_scrub($raw, 'UTF-8');
        $labelValues = array_intersect_key($meta, array_flip($labels));
        $matcher = (string) ($rules->rule($ruleKey)['matcher'] ?? '');
        $decoder = new BoundedDecoder;
        $acc = new \App\Services\DbScanner\Rules\Accumulator;

        $wholeMatcher = in_array($matcher, self::WHOLE_VALUE_MATCHERS, true);
        foreach ($this->leaves($text, (string) $column) as [$leafField, $leaf, $view]) {
            if ($leafField !== $field || ($view === 'whole' && ! $wholeMatcher) || ($view === 'leaf' && $wholeMatcher)) {
                continue;
            }
            $ctx = new RowContext($surface->key, $table, $rowId, $leafField, $labelValues, $surface->objectType, $this->objectId($surface, $rowId, $labelValues), $length <= $valueCap, $length, hash('sha256', $leaf));
            if ($this->matchers->run($matcher, $ruleKey, $rules, $ctx, new ContentAnalysis($leaf, $decoder, $hosts), $acc)) {
                return ['hit' => true, 'fully_read' => $length <= $valueCap, 'missing' => false];
            }
        }

        return ['hit' => false, 'fully_read' => $length <= $valueCap, 'missing' => false];
    }

    /**
     * The value itself, or each string leaf of a serialized/JSON value with a
     * deterministic path. Never instantiates objects.
     *
     * View "value" is a plain cell (every matcher), "whole" a structured cell
     * seen by name/structure matchers only, "leaf" one string inside it.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public function leaves(string $text, string $column): array
    {
        $trimmed = ltrim($text);
        $tree = null;

        if (SerializedParser::looksSerialized($trimmed)) {
            $parser = new SerializedParser;
            $parsed = $parser->parse($trimmed);
            if ($parser->error === null && (is_array($parsed) || is_string($parsed))) {
                $tree = $parsed;
            }
        } elseif ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && strlen($trimmed) < 2_000_000) {
            $decoded = json_decode($trimmed, true, 64);
            if (is_array($decoded)) {
                $tree = $decoded;
            }
        }

        if ($tree === null || is_string($tree)) {
            return [[$column, is_string($tree) ? $tree : $text, 'value']];
        }

        $out = [];
        $nodes = 0;
        $walk = function ($node, string $path) use (&$walk, &$out, &$nodes): void {
            if (++$nodes > 10000) {
                return;
            }
            if (is_array($node)) {
                foreach ($node as $k => $v) {
                    $walk($v, $path.'['.mb_substr((string) $k, 0, 60).']');
                }

                return;
            }
            if (is_string($node) && strlen($node) >= 4) {
                $out[] = [$path, $node, 'leaf'];
            }
        };
        $walk($tree, $column);

        array_unshift($out, [$column, $text, 'whole']);

        return $out;
    }

    private function objectId(Surface $surface, int $key, array $labels): int|string|null
    {
        return match ($surface->objectType) {
            'postmeta' => isset($labels['post_id']) ? (int) $labels['post_id'] : null,
            'usermeta' => isset($labels['user_id']) ? (int) $labels['user_id'] : null,
            'comment' => isset($labels['comment_post_ID']) ? (int) $labels['comment_post_ID'] : $key,
            'revision' => isset($labels['post_parent']) ? (int) $labels['post_parent'] : $key,
            default => $key,
        };
    }
}
