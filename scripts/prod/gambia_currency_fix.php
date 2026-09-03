<?php

/**
 * Gambia market currency correction.
 *
 * The Gambia platform was configured with currency_code = 'CFA'. Gambia is in
 * neither franc zone, so CurrencyCanonicalizer cannot resolve it, which forces
 * normalized_total = NULL on every reporting aggregate the market appears in —
 * and the `normalized_total ?? array_sum(source_breakdown)` fallback then counts
 * the raw native amount as target currency, inflating Gambia's revenue share.
 *
 * This relabels the currency CODE only. It never changes an amount.
 *
 * Stages: DRY-RUN (default) -> BACKUP -> APPLY -> VERIFY.
 *
 * Usage (from the app root, ~/crm.exotic-online.com):
 *   php scripts/prod/gambia_currency_fix.php
 *       Read-only. Shows the platform row, the product catalogue, every payment,
 *       and — critically — what the payment gateway actually charged
 *       (billing_provider_transactions.settled_currency / charge_currency).
 *
 *   php scripts/prod/gambia_currency_fix.php --apply --currency=GMD
 *       Backs up to storage/, then rewrites 'CFA' -> GMD on the platform,
 *       its products, its product prices and its payments.
 *
 *   php scripts/prod/gambia_currency_fix.php --restore=storage/gambia_currency_backup_YYYYmmdd_HHiiss.json
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\CurrencyCanonicalizer;
use App\Services\ReportingCurrencyService;
use Illuminate\Support\Facades\DB;

const MARKET_MATCH = 'Gambia';
const WRONG_CODE = 'CFA';

$apply = in_array('--apply', $argv, true);
$newCurrency = null;
$restore = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--currency=')) {
        $newCurrency = strtoupper(trim(substr($arg, 11)));
    }
    if (str_starts_with($arg, '--restore=')) {
        $restore = substr($arg, 10);
    }
}

$line = fn (string $c = '=') => print(str_repeat($c, 96) . PHP_EOL);
$canonicalizer = app(CurrencyCanonicalizer::class);
$reporting = app(ReportingCurrencyService::class);
$target = $reporting->settings()['target_currency'] ?? 'USD';

// ---------------------------------------------------------------------------
// RESTORE
// ---------------------------------------------------------------------------
if ($restore !== null) {
    $path = str_starts_with($restore, '/') ? $restore : base_path($restore);

    if (! is_file($path)) {
        fwrite(STDERR, "Backup not found: {$path}\n");
        exit(1);
    }

    $backup = json_decode((string) file_get_contents($path), true);

    if (! is_array($backup) || ! isset($backup['platforms'])) {
        fwrite(STDERR, "Backup file is not readable as a Gambia currency backup.\n");
        exit(1);
    }

    DB::transaction(function () use ($backup) {
        foreach ($backup['platforms'] as $row) {
            DB::table('platforms')->where('id', $row['id'])->update(['currency_code' => $row['currency_code']]);
        }
        foreach ($backup['products'] as $row) {
            DB::table('products')->where('id', $row['id'])->update(['currency' => $row['currency']]);
        }
        foreach ($backup['product_prices'] as $row) {
            DB::table('product_prices')->where('id', $row['id'])->update(['currency' => $row['currency']]);
        }
        foreach ($backup['payments'] as $row) {
            DB::table('payments')->where('id', $row['id'])->update(['currency' => $row['currency']]);
        }
    });

    echo "Restored from {$path}\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// LOCATE THE MARKET
// ---------------------------------------------------------------------------
$platforms = DB::table('platforms')
    ->where('country', 'like', '%' . MARKET_MATCH . '%')
    ->orWhere('name', 'like', '%' . MARKET_MATCH . '%')
    ->get();

if ($platforms->isEmpty()) {
    fwrite(STDERR, "No platform matching '" . MARKET_MATCH . "' found.\n");
    exit(1);
}

if ($platforms->count() > 1) {
    fwrite(STDERR, "Multiple platforms match '" . MARKET_MATCH . "'; refusing to guess:\n");
    foreach ($platforms as $p) {
        fwrite(STDERR, "  id={$p->id} name={$p->name} country={$p->country} currency={$p->currency_code}\n");
    }
    exit(1);
}

$platform = $platforms->first();
$platformId = (int) $platform->id;

echo PHP_EOL;
$line();
echo 'GAMBIA CURRENCY CORRECTION  ·  ' . ($apply ? 'APPLY' : 'DRY RUN') . '  ·  ' . now()->toDateTimeString() . PHP_EOL;
$line();
printf("  platform      : #%d  %s\n", $platformId, (string) $platform->name);
printf("  country       : %s\n", (string) $platform->country);
printf("  timezone      : %s\n", (string) ($platform->timezone ?? '-'));
printf("  phone prefix  : %s\n", (string) ($platform->phone_prefix ?? '-'));
printf("  currency_code : %s\n", (string) $platform->currency_code);

$current = $canonicalizer->resolve($platform->currency_code, [
    'platform_country' => $platform->country,
    'platform_name' => $platform->name,
]);
printf(
    "  resolves to   : %s   (%s)\n",
    $current['code'] ?? '** UNRESOLVED **',
    (string) ($current['reason'] ?: $current['status'])
);

// ---------------------------------------------------------------------------
// WHAT THE GATEWAY ACTUALLY CHARGED  — the authoritative record
// ---------------------------------------------------------------------------
echo PHP_EOL;
$line('-');
echo "PAYMENTS — and what the provider actually charged\n";
$line('-');

$payments = DB::table('payments')
    ->where('platform_id', $platformId)
    ->orderBy('created_at')
    ->get();

if ($payments->isEmpty()) {
    echo "  No payments on this market.\n";
} else {
    foreach ($payments as $pay) {
        printf(
            "  payment #%-6s  %s %-12s  status=%-10s  ref=%s\n",
            $pay->id,
            (string) $pay->currency,
            number_format((float) $pay->amount, 2),
            (string) $pay->status,
            (string) ($pay->transaction_reference ?: $pay->reference_number ?: '-')
        );

        $txns = DB::table('billing_provider_transactions')->where('payment_id', $pay->id)->get();

        if ($txns->isEmpty()) {
            echo "      provider record : none (no gateway currency to compare against)\n";
        }

        foreach ($txns as $t) {
            printf(
                "      provider=%-14s requested=%s %s  charged=%s %s  settled=%s %s\n",
                (string) $t->provider_type_key,
                (string) ($t->requested_currency ?: '-'),
                $t->requested_amount !== null ? number_format((float) $t->requested_amount, 2) : '-',
                (string) ($t->charge_currency ?: '-'),
                $t->charge_amount !== null ? number_format((float) $t->charge_amount, 2) : '-',
                (string) ($t->settled_currency ?: '-'),
                $t->settled_amount !== null ? number_format((float) $t->settled_amount, 2) : '-'
            );
        }

        // payment_data often carries the raw gateway payload including its currency.
        $blob = (string) ($pay->payment_data ?? '');
        if ($blob !== '' && preg_match_all('/"?(currency|Currency|CURRENCY)"?\s*[:=]\s*"?([A-Za-z]{3})"?/', $blob, $mm)) {
            $found = array_values(array_unique(array_map('strtoupper', $mm[2])));
            echo "      payment_data hints: " . implode(', ', $found) . "\n";
        }
    }
}

// ---------------------------------------------------------------------------
// CATALOGUE — does the price sheet make sense in the proposed currency?
// ---------------------------------------------------------------------------
echo PHP_EOL;
$line('-');
echo "PRODUCT CATALOGUE\n";
$line('-');

$prices = DB::table('product_prices')
    ->join('products', 'products.id', '=', 'product_prices.product_id')
    ->where('products.platform_id', $platformId)
    ->select(
        'product_prices.id',
        'product_prices.price',
        'product_prices.currency',
        'product_prices.duration_label',
        'products.name as product_name'
    )
    ->orderBy('products.sort_order')
    ->orderBy('product_prices.duration_days')
    ->get();

if ($prices->isEmpty()) {
    echo "  No catalogue rows.\n";
} else {
    foreach ($prices as $pr) {
        printf(
            "  %-12s %-12s  %s %s\n",
            (string) $pr->product_name,
            (string) $pr->duration_label,
            (string) $pr->currency,
            number_format((float) $pr->price, 2)
        );
    }
}

// A price sanity check against the proposed currency, so an 8x mispricing is
// obvious before anyone relabels the catalogue.
if ($newCurrency !== null && ! $prices->isEmpty()) {
    $rate = DB::table('reporting_fx_rates')
        ->where('source_currency', $newCurrency)
        ->where('target_currency', $target)
        ->orderByDesc('rate_date')
        ->value('rate');

    echo PHP_EOL;
    if ($rate === null) {
        printf("  !! No %s -> %s rate cached. Conversion cannot be sanity-checked,\n", $newCurrency, $target);
        printf("     and the market will stay unresolved until a rate exists.\n");
    } else {
        printf("  Priced as %s at %s/%s = %.8f:\n", $newCurrency, $newCurrency, $target, (float) $rate);
        foreach ($prices as $pr) {
            printf(
                "     %-12s %-12s -> %s %s\n",
                (string) $pr->product_name,
                (string) $pr->duration_label,
                $target,
                number_format((float) $pr->price * (float) $rate, 2)
            );
        }
        echo "     (compare against other markets: a VIP week runs about USD 11-40 network-wide)\n";
    }
}

// ---------------------------------------------------------------------------
// APPLY
// ---------------------------------------------------------------------------
$productIds = DB::table('products')->where('platform_id', $platformId)->pluck('id');

echo PHP_EOL;
$line();

if (! $apply) {
    echo "DRY RUN — nothing was written.\n\n";
    echo "  Would rewrite '" . WRONG_CODE . "' on:\n";
    printf("    platforms.currency_code   : 1 row\n");
    printf("    products.currency         : %d rows\n", DB::table('products')->where('platform_id', $platformId)->where('currency', WRONG_CODE)->count());
    printf("    product_prices.currency   : %d rows\n", DB::table('product_prices')->whereIn('product_id', $productIds)->where('currency', WRONG_CODE)->count());
    printf("    payments.currency         : %d rows\n", DB::table('payments')->where('platform_id', $platformId)->where('currency', WRONG_CODE)->count());
    echo "\n  Re-run with:  --apply --currency=GMD   (or whichever code is correct)\n";
    $line();
    echo PHP_EOL;
    exit(0);
}

if ($newCurrency === null) {
    fwrite(STDERR, "--apply requires --currency=XXX\n");
    exit(1);
}

$check = $canonicalizer->resolve($newCurrency, [
    'platform_country' => $platform->country,
    'platform_name' => $platform->name,
]);

if ($check['code'] === null) {
    fwrite(STDERR, "Refusing to apply: '{$newCurrency}' does not resolve ({$check['reason']}).\n");
    exit(1);
}

$backup = [
    'generated_at' => now()->toIso8601String(),
    'platform_id' => $platformId,
    'new_currency' => $newCurrency,
    'platforms' => DB::table('platforms')->where('id', $platformId)->get(['id', 'currency_code'])->map(fn ($r) => (array) $r)->all(),
    'products' => DB::table('products')->where('platform_id', $platformId)->get(['id', 'currency'])->map(fn ($r) => (array) $r)->all(),
    'product_prices' => DB::table('product_prices')->whereIn('product_id', $productIds)->get(['id', 'currency'])->map(fn ($r) => (array) $r)->all(),
    'payments' => DB::table('payments')->where('platform_id', $platformId)->get(['id', 'currency'])->map(fn ($r) => (array) $r)->all(),
];

$backupPath = storage_path('gambia_currency_backup_' . now()->format('Ymd_His') . '.json');
file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "BACKUP written: {$backupPath}\n\n";

$counts = DB::transaction(function () use ($platformId, $productIds, $newCurrency) {
    return [
        'platform' => DB::table('platforms')->where('id', $platformId)->update(['currency_code' => $newCurrency]),
        'products' => DB::table('products')->where('platform_id', $platformId)->where('currency', WRONG_CODE)->update(['currency' => $newCurrency]),
        'prices' => DB::table('product_prices')->whereIn('product_id', $productIds)->where('currency', WRONG_CODE)->update(['currency' => $newCurrency]),
        'payments' => DB::table('payments')->where('platform_id', $platformId)->where('currency', WRONG_CODE)->update(['currency' => $newCurrency]),
    ];
});

echo "APPLIED\n";
foreach ($counts as $what => $n) {
    printf("  %-10s : %d row(s) updated\n", $what, $n);
}

// ---------------------------------------------------------------------------
// VERIFY
// ---------------------------------------------------------------------------
echo PHP_EOL;
$line('-');
echo "VERIFY\n";
$line('-');

$after = DB::table('platforms')->where('id', $platformId)->first();
$res = $canonicalizer->resolve($after->currency_code, [
    'platform_country' => $after->country,
    'platform_name' => $after->name,
]);

printf("  platforms.currency_code = %s -> resolves to %s\n", (string) $after->currency_code, $res['code'] ?? '** STILL UNRESOLVED **');

$leftover = DB::table('payments')->where('platform_id', $platformId)->where('currency', WRONG_CODE)->count()
    + DB::table('product_prices')->whereIn('product_id', $productIds)->where('currency', WRONG_CODE)->count()
    + DB::table('products')->where('platform_id', $platformId)->where('currency', WRONG_CODE)->count();

printf("  remaining '%s' rows on this market: %d\n", WRONG_CODE, $leftover);

// Build the event rows directly rather than going through normalizePaymentQuery():
// that helper groups by correlated subqueries, which trips only_full_group_by on
// stricter MySQL builds. A verify step must never explode after a committed write.
try {
    $verifyRows = DB::table('payments')
        ->where('platform_id', $platformId)
        ->get(['id', 'amount', 'currency', 'completed_at', 'created_at'])
        ->map(fn ($p) => (object) [
            'event_date' => ($p->completed_at ?: $p->created_at)
                ? \Carbon\Carbon::parse($p->completed_at ?: $p->created_at)->toDateString()
                : now()->toDateString(),
            'currency' => $p->currency ?: $after->currency_code,
            'amount' => (float) $p->amount,
            'platform_id' => $platformId,
            'platform_country' => $after->country,
            'platform_name' => $after->name,
        ]);

    $normalized = $reporting->normalizeEventRows($verifyRows, $target);
    $meta = $normalized['normalization_meta'] ?? [];

    printf(
        "  market normalises to: %s   (partial=%s, missing=%s)\n",
        $normalized['normalized_display'] ?? 'NULL',
        ($meta['partial'] ?? false) ? 'yes' : 'no',
        implode(',', $meta['missing_currencies'] ?? []) ?: 'none'
    );

    if (($meta['partial'] ?? false) === true) {
        echo "  !! Still partial. The currency code now resolves, but no usable FX rate\n";
        echo "     exists for it yet. Check section 3 of fx_resolution_audit.php.\n";
    }
} catch (\Throwable $e) {
    echo "  (currency rewrite committed; the normalisation preview could not run: {$e->getMessage()})\n";
}

echo "\n  Rollback if needed:\n";
echo "    php scripts/prod/gambia_currency_fix.php --restore=" . str_replace(base_path() . '/', '', $backupPath) . "\n";
$line();
echo PHP_EOL;
