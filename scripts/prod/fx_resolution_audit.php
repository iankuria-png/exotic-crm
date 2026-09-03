<?php

/**
 * FX resolution audit — find every currency the reporting layer cannot convert,
 * and show the blast radius (which markets/metrics go null or get mis-summed).
 *
 * READ-ONLY. Makes no writes of any kind.
 *
 * Usage (from the app root, ~/crm.exotic-online.com):
 *   php scripts/prod/fx_resolution_audit.php
 *   php scripts/prod/fx_resolution_audit.php --days=30     # payment window to sample (default 90)
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\CurrencyCanonicalizer;
use App\Services\ReportingCurrencyService;
use Illuminate\Support\Facades\DB;

$days = 90;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--days=')) {
        $days = max(1, (int) substr($arg, 7));
    }
}

$canonicalizer = app(CurrencyCanonicalizer::class);
$reporting = app(ReportingCurrencyService::class);
$settings = $reporting->settings();
$target = $settings['target_currency'] ?? 'USD';

$line = fn (string $c = '=') => print(str_repeat($c, 100) . PHP_EOL);

echo PHP_EOL;
$line();
echo "FX RESOLUTION AUDIT  ·  " . now()->toDateTimeString() . PHP_EOL;
$line();
printf("  target currency : %s\n", $target);
printf("  provider        : %s\n", $settings['provider'] ?? '?');
printf("  enabled         : %s\n", ($settings['enabled'] ?? false) ? 'yes' : 'NO');
printf("  api key         : %s\n", ($settings['api_key_configured'] ?? false) ? 'configured' : 'MISSING');
printf("  stale window    : %d days\n", $settings['stale_days'] ?? 0);
printf("  sample window   : last %d days of payments\n", $days);

// ---------------------------------------------------------------------------
// 1. Platform currency codes that cannot be canonicalised
// ---------------------------------------------------------------------------
echo PHP_EOL;
$line('-');
echo "1. PLATFORM CURRENCY CODES\n";
$line('-');
printf("%-4s | %-24s | %-24s | %-6s | %-8s | %s\n", 'ID', 'NAME', 'COUNTRY', 'CODE', 'RESOLVES', 'REASON');

$badPlatforms = [];
foreach (DB::table('platforms')->select('id', 'name', 'country', 'currency_code')->orderBy('id')->get() as $p) {
    $r = $canonicalizer->resolve($p->currency_code, [
        'platform_country' => $p->country,
        'platform_name' => $p->name,
    ]);
    $ok = $r['code'] !== null;
    if (! $ok) {
        $badPlatforms[] = $p;
    }
    printf(
        "%-4d | %-24s | %-24s | %-6s | %-8s | %s\n",
        $p->id,
        mb_substr((string) $p->name, 0, 24),
        mb_substr((string) $p->country, 0, 24),
        (string) $p->currency_code,
        $ok ? $r['code'] : '** NO **',
        $ok ? '' : (string) $r['reason']
    );
}

// ---------------------------------------------------------------------------
// 2. Distinct payment currencies that cannot be canonicalised
// ---------------------------------------------------------------------------
echo PHP_EOL;
$line('-');
echo "2. PAYMENT CURRENCIES IN THE WINDOW\n";
$line('-');
printf("%-8s | %-4s | %-24s | %8s | %18s | %-8s | %s\n", 'CURRENCY', 'PLAT', 'COUNTRY', 'ROWS', 'NATIVE AMOUNT', 'RESOLVES', 'REASON');

$rows = DB::table('payments')
    ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
    ->where('payments.created_at', '>=', now()->subDays($days))
    ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$target}') as currency")
    ->selectRaw('payments.platform_id as platform_id')
    ->selectRaw('platforms.country as platform_country')
    ->selectRaw('platforms.name as platform_name')
    ->selectRaw('COUNT(*) as entries')
    ->selectRaw('SUM(payments.amount) as amount')
    ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$target}')")
    ->groupBy('payments.platform_id', 'platforms.country', 'platforms.name')
    ->orderByDesc('amount')
    ->get();

$unresolved = [];
foreach ($rows as $r) {
    $res = $canonicalizer->resolve($r->currency, [
        'platform_country' => $r->platform_country,
        'platform_name' => $r->platform_name,
    ]);
    $ok = $res['code'] !== null;
    if (! $ok) {
        $unresolved[] = $r;
    }
    printf(
        "%-8s | %-4s | %-24s | %8d | %18s | %-8s | %s\n",
        (string) $r->currency,
        (string) $r->platform_id,
        mb_substr((string) $r->platform_country, 0, 24),
        $r->entries,
        number_format((float) $r->amount, 2),
        $ok ? $res['code'] : '** NO **',
        $ok ? '' : (string) $res['reason']
    );
}

// ---------------------------------------------------------------------------
// 3. Canonical currencies with no usable FX rate
// ---------------------------------------------------------------------------
echo PHP_EOL;
$line('-');
echo "3. FX RATE COVERAGE (canonical currency -> {$target})\n";
$line('-');
printf("%-8s | %-10s | %-12s | %-12s | %s\n", 'CODE', 'RATES', 'NEWEST', 'OLDEST', 'STATUS');

$canonical = [];
foreach ($rows as $r) {
    $res = $canonicalizer->resolve($r->currency, [
        'platform_country' => $r->platform_country,
        'platform_name' => $r->platform_name,
    ]);
    if ($res['code'] !== null) {
        $canonical[$res['code']] = true;
    }
}

foreach (array_keys($canonical) as $code) {
    if ($code === $target) {
        printf("%-8s | %-10s | %-12s | %-12s | %s\n", $code, '-', '-', '-', 'identity');
        continue;
    }

    $agg = DB::table('reporting_fx_rates')
        ->where('source_currency', $code)
        ->where('target_currency', $target)
        ->selectRaw('COUNT(*) as entries')
        ->selectRaw('MAX(rate_date) as newest')
        ->selectRaw('MIN(rate_date) as oldest')
        ->first();

    $count = (int) ($agg->entries ?? 0);
    $status = $count === 0
        ? '** NO RATE AT ALL **'
        : (now()->diffInDays(\Carbon\Carbon::parse($agg->newest)) > ($settings['stale_days'] ?? 7)
            ? 'stale beyond window'
            : 'ok');

    printf(
        "%-8s | %-10d | %-12s | %-12s | %s\n",
        $code,
        $count,
        (string) ($agg->newest ?? '-'),
        (string) ($agg->oldest ?? '-'),
        $status
    );
}

// ---------------------------------------------------------------------------
// 4. Blast radius
// ---------------------------------------------------------------------------
echo PHP_EOL;
$line();
echo "VERDICT\n";
$line();

if (! $unresolved && ! $badPlatforms) {
    echo "  No unresolved currencies. Dashboard totals should normalise cleanly.\n";
} else {
    echo "  Unresolved currency/market pairs found. Each one:\n";
    echo "    - forces normalized_total = NULL for EVERY aggregate it appears in\n";
    echo "      (one bad market blanks converted revenue for all markets in that widget)\n";
    echo "    - is then re-added to share/pie totals as a RAW NATIVE amount via the\n";
    echo "      `normalized_total ?? array_sum(source_breakdown)` fallback, inflating its share\n\n";

    foreach ($unresolved as $r) {
        printf(
            "    · platform %-3s %-22s  %s %s  (%d payments)  -> %s\n",
            (string) $r->platform_id,
            mb_substr((string) $r->platform_name, 0, 22),
            (string) $r->currency,
            number_format((float) $r->amount, 2),
            $r->entries,
            'counted as ' . number_format((float) $r->amount, 2) . ' ' . $target . ' in share maths'
        );
    }
    echo PHP_EOL;
}

$line();
echo PHP_EOL;
