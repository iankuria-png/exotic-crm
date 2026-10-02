<?php

namespace App\Services\DbScanner\Rules;

/**
 * Bounded network-of-rows tallies (outbound domains, shortener hosts,
 * approved comments with links) collected per chunk and merged into the
 * sweep, then evaluated once the compatible sweep completes.
 */
final class Accumulator
{
    /** @var array<string, array<string, array{count: int, samples: array<int, int|string>}>> */
    public array $buckets = [];

    public function add(string $ruleKey, string $bucket, int|string $sample, int $bytes = 0): void
    {
        $bucket = mb_substr($bucket, 0, 190);
        if (! isset($this->buckets[$ruleKey][$bucket])) {
            if (count($this->buckets[$ruleKey] ?? []) >= 2000) {
                return;
            }
            $this->buckets[$ruleKey][$bucket] = ['count' => 0, 'samples' => []];
        }
        $this->buckets[$ruleKey][$bucket]['count']++;
        if ($bytes > 0) {
            $this->buckets[$ruleKey][$bucket]['bytes'] = (int) ($this->buckets[$ruleKey][$bucket]['bytes'] ?? 0) + $bytes;
        }
        if (count($this->buckets[$ruleKey][$bucket]['samples']) < 5) {
            $this->buckets[$ruleKey][$bucket]['samples'][] = $sample;
        }
    }

    /**
     * Merge into a stored sweep tally, keeping the same bounds.
     */
    public static function merge(array $stored, array $incoming): array
    {
        foreach ($incoming as $ruleKey => $buckets) {
            foreach ($buckets as $bucket => $data) {
                if (! isset($stored[$ruleKey][$bucket])) {
                    if (count($stored[$ruleKey] ?? []) >= 2000) {
                        continue;
                    }
                    $stored[$ruleKey][$bucket] = ['count' => 0, 'samples' => []];
                }
                $stored[$ruleKey][$bucket]['count'] += (int) $data['count'];
                if (isset($data['bytes'])) {
                    $stored[$ruleKey][$bucket]['bytes'] = (int) ($stored[$ruleKey][$bucket]['bytes'] ?? 0) + (int) $data['bytes'];
                }
                $stored[$ruleKey][$bucket]['samples'] = array_slice(
                    array_values(array_unique(array_merge($stored[$ruleKey][$bucket]['samples'], $data['samples']))),
                    0,
                    5
                );
            }
        }

        return $stored;
    }
}
