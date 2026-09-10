<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Laravel\Pulse\Facades\Pulse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Takes the performance findings off the Pulse dashboard and puts them in a file.
 *
 * Pulse renders beautifully and exports nothing, so the slow query that is
 * costing 36 seconds a request can only be worked on by squinting at a card.
 * This reads the same aggregates the dashboard cards read — never the raw
 * `pulse_entries` table, which is over a million rows in production — and
 * writes them out as a flat CSV or a structured JSON snapshot.
 */
class PulseExportController extends Controller
{
    /** Period keys, matching the Pulse dashboard's own selector. */
    private const PERIODS = [
        '1_hour' => 1,
        '6_hours' => 6,
        '24_hours' => 24,
        '7_days' => 168,
    ];

    /** Rows per section. The dashboard shows ~10; this leaves room to dig. */
    private const LIMIT = 100;

    public function export(Request $request): StreamedResponse
    {
        $period = array_key_exists((string) $request->input('period'), self::PERIODS)
            ? (string) $request->input('period')
            : '24_hours';

        $interval = CarbonInterval::hours(self::PERIODS[$period]);
        $sections = $this->collect($interval);
        $stamp = now()->format('Ymd-His');

        return $request->input('format') === 'json'
            ? $this->streamJson($period, $sections, "crm-pulse-{$period}-{$stamp}.json")
            : $this->streamCsv($period, $sections, "crm-pulse-{$period}-{$stamp}.csv");
    }

    /**
     * @return array<string, array{label: string, rows: array<int, array<string, mixed>>}>
     */
    private function collect(CarbonInterval $interval): array
    {
        return [
            'exceptions' => [
                'label' => 'Exceptions',
                'rows' => $this->aggregate('exception', $interval, 'count', function (object $row) {
                    [$class, $location] = $this->decodeKey($row->key, 2);

                    return [
                        'item' => $class,
                        'detail' => $location,
                        'count' => (int) $row->count,
                        'slowest_ms' => null,
                        'latest' => $row->max ? CarbonImmutable::createFromTimestamp((int) $row->max)->toIso8601String() : null,
                    ];
                }),
            ],
            'slow_requests' => [
                'label' => 'Slow requests',
                'rows' => $this->aggregate('slow_request', $interval, 'max', function (object $row) {
                    [$method, $uri, $action] = $this->decodeKey($row->key, 3);

                    return [
                        'item' => trim($method.' '.$uri),
                        'detail' => $action,
                        'count' => (int) $row->count,
                        'slowest_ms' => (int) $row->max,
                        'latest' => null,
                    ];
                }),
            ],
            'slow_queries' => [
                'label' => 'Slow queries',
                'rows' => $this->aggregate('slow_query', $interval, 'max', function (object $row) {
                    [$sql, $location] = $this->decodeKey($row->key, 2);

                    return [
                        'item' => $sql,
                        'detail' => $location,
                        'count' => (int) $row->count,
                        'slowest_ms' => (int) $row->max,
                        'latest' => null,
                    ];
                }),
            ],
            'slow_jobs' => [
                'label' => 'Slow jobs',
                'rows' => $this->aggregate('slow_job', $interval, 'max', fn (object $row) => [
                    'item' => $row->key,
                    'detail' => null,
                    'count' => (int) $row->count,
                    'slowest_ms' => (int) $row->max,
                    'latest' => null,
                ]),
            ],
            'slow_outgoing_requests' => [
                'label' => 'Slow outgoing requests',
                'rows' => $this->aggregate('slow_outgoing_request', $interval, 'max', function (object $row) {
                    [$method, $uri] = $this->decodeKey($row->key, 2);

                    return [
                        'item' => trim($method.' '.$uri),
                        'detail' => null,
                        'count' => (int) $row->count,
                        'slowest_ms' => (int) $row->max,
                        'latest' => null,
                    ];
                }),
            ],
        ];
    }

    /**
     * A recorder that has never fired has no aggregate rows, and on a fresh
     * install it may have no table to read either. Neither is an export
     * failure — the section simply comes back empty.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aggregate(string $type, CarbonInterval $interval, string $orderBy, callable $map): array
    {
        try {
            return Pulse::aggregate($type, ['max', 'count'], $interval, $orderBy, 'desc', self::LIMIT)
                ->map($map)
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Pulse packs composite keys as JSON arrays. Pad short ones rather than
     * throwing: a malformed key should cost one row, not the whole export.
     *
     * @return array<int, string|null>
     */
    private function decodeKey(string $key, int $expected): array
    {
        $parts = json_decode($key, true);

        if (! is_array($parts)) {
            $parts = [$key];
        }

        return array_pad(array_values($parts), $expected, null);
    }

    private function streamCsv(string $period, array $sections, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($period, $sections) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Section', 'Item', 'Detail', 'Count', 'Slowest (ms)', 'Latest', 'Period']);

            foreach ($sections as $section) {
                foreach ($section['rows'] as $row) {
                    fputcsv($handle, [
                        $section['label'],
                        $row['item'],
                        $row['detail'],
                        $row['count'],
                        $row['slowest_ms'],
                        $row['latest'],
                        $period,
                    ]);
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function streamJson(string $period, array $sections, string $filename): StreamedResponse
    {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'app_url' => config('app.url'),
            'period' => $period,
            'period_hours' => self::PERIODS[$period],
            'rows_per_section' => self::LIMIT,
            'sections' => $sections,
        ];

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }, $filename, ['Content-Type' => 'application/json']);
    }
}
