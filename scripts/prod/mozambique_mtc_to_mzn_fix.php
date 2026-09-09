<?php

/**
 * Mozambique MTC -> MZN currency relabel.
 *
 * This is a data correction only. It changes exact currency codes from MTC to
 * MZN for the Mozambique market and does not alter any amount, status, phone,
 * reference, product, client, or subscription date.
 *
 * Stages: DRY RUN (default) -> BACKUP -> APPLY -> VERIFY.
 *
 * Usage from the Laravel app root on cPanel:
 *   php scripts/prod/mozambique_mtc_to_mzn_fix.php
 *       Read-only. Shows the matched market, row counts, and payment samples.
 *
 *   php scripts/prod/mozambique_mtc_to_mzn_fix.php --apply
 *       Writes a rollback JSON backup to storage/, then rewrites MTC -> MZN.
 *
 *   php scripts/prod/mozambique_mtc_to_mzn_fix.php --platform-id=12 --apply
 *       Use this if more than one Mozambique-like platform is found.
 *
 *   php scripts/prod/mozambique_mtc_to_mzn_fix.php --restore=storage/mozambique_mtc_to_mzn_backup_YYYYmmdd_HHiiss.json
 *       Restores all backed-up currency fields from a previous run.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MARKET_MATCH = 'Mozambique';
const WRONG_CODE = 'MTC';
const CORRECT_CODE = 'MZN';

$args = array_slice($_SERVER['argv'], 1);
$apply = in_array('--apply', $args, true);
$restoreFile = optionValue($args, '--restore');
$platformIdOption = optionValue($args, '--platform-id');

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    say('Usage:');
    say('  php scripts/prod/mozambique_mtc_to_mzn_fix.php');
    say('  php scripts/prod/mozambique_mtc_to_mzn_fix.php --apply');
    say('  php scripts/prod/mozambique_mtc_to_mzn_fix.php --platform-id=12 --apply');
    say('  php scripts/prod/mozambique_mtc_to_mzn_fix.php --restore=storage/mozambique_mtc_to_mzn_backup_YYYYmmdd_HHiiss.json');
    exit(0);
}

if ($restoreFile !== null) {
    restoreBackup($restoreFile);
    exit(0);
}

$platform = resolvePlatform($platformIdOption);
$platformId = (int) $platform->id;
$productIds = collect();

if (hasTableAndColumn('products', 'platform_id')) {
    $productIds = DB::table('products')->where('platform_id', $platformId)->pluck('id');
}

$targets = collectTargets($platformId, $productIds);

line();
say('MOZAMBIQUE CURRENCY RELABEL  -  '.($apply ? 'APPLY' : 'DRY RUN').'  -  '.now()->toDateTimeString());
line();
printf("  platform       : #%d %s\n", $platformId, (string) $platform->name);
printf("  country        : %s\n", (string) ($platform->country ?? '-'));
printf("  domain         : %s\n", (string) ($platform->domain ?? '-'));
printf("  currency_code  : %s\n", (string) ($platform->currency_code ?? '-'));
say('  rewrite        : '.WRONG_CODE.' -> '.CORRECT_CODE);

line('-');
say('ROWS THAT WOULD CHANGE');
line('-');
printCounts($targets);

$paymentRows = $targets['payments'] ?? collect();
if ($paymentRows->isNotEmpty()) {
    say();
    say('Recent payment samples:');
    foreach ($paymentRows->take(15) as $payment) {
        printf(
            "  payment #%-6s  %s %-10s  status=%-10s  date=%-19s  ref=%s\n",
            (string) $payment->id,
            (string) $payment->currency,
            number_format((float) $payment->amount, 2),
            (string) $payment->status,
            (string) ($payment->completed_at ?: $payment->created_at ?: '-'),
            (string) ($payment->transaction_reference ?: $payment->reference_number ?: '-')
        );
    }
}

if (totalTargetRows($targets) === 0) {
    say();
    say('Nothing to change. No exact '.WRONG_CODE.' currency values were found for this market.');
    line();
    exit(0);
}

if (! $apply) {
    say();
    say('DRY RUN COMPLETE - no writes were made.');
    say('Run this to apply after reviewing the counts above:');
    $cmd = 'php scripts/prod/mozambique_mtc_to_mzn_fix.php --apply';
    if ($platformIdOption !== null) {
        $cmd .= ' --platform-id='.$platformId;
    }
    say('  '.$cmd);
    line();
    exit(0);
}

$backupPath = writeBackup($platformId, $targets);
say();
say('BACKUP written: '.$backupPath);

$counts = DB::transaction(function () use ($platformId, $productIds, $targets) {
    $updated = [];

    $platformUpdate = [];
    foreach (($targets['platforms'] ?? collect()) as $row) {
        if (hasTableAndColumn('platforms', 'currency_code') && strtoupper(trim((string) $row->currency_code)) === WRONG_CODE) {
            $platformUpdate['currency_code'] = CORRECT_CODE;
        }
        foreach (['supported_currencies', 'wallet_settings'] as $column) {
            $replacement = replacementJsonValue($row->{$column} ?? null);
            if ($replacement['changed']) {
                $platformUpdate[$column] = $replacement['encoded'];
            }
        }
    }
    $updated['platforms'] = $platformUpdate === []
        ? 0
        : DB::table('platforms')->where('id', $platformId)->update($platformUpdate);

    $updated['products'] = updateSimpleCurrency(
        'products',
        'currency',
        fn ($query) => $query->where('platform_id', $platformId)
    );

    $updated['product_prices'] = $productIds->isEmpty()
        ? 0
        : updateSimpleCurrency(
            'product_prices',
            'currency',
            fn ($query) => $query->whereIn('product_id', $productIds)
        );

    $updated['deals'] = updateSimpleCurrency(
        'deals',
        'currency',
        fn ($query) => $query->where('platform_id', $platformId)
    );

    $updated['payments'] = updateSimpleCurrency(
        'payments',
        'currency',
        fn ($query) => $query->where('platform_id', $platformId)
    );

    $updated['clients'] = updateSimpleCurrency(
        'clients',
        'wallet_currency',
        fn ($query) => $query->where('platform_id', $platformId)
    );

    $updated['wallet_transactions'] = updateSimpleCurrency(
        'wallet_transactions',
        'currency_code',
        fn ($query) => $query->where('platform_id', $platformId)
    );

    $updated['payment_reconciliation_rows'] = updateSimpleCurrency(
        'payment_reconciliation_rows',
        'external_currency',
        fn ($query) => $query->whereIn(
            'batch_id',
            DB::table('payment_reconciliation_batches')
                ->where('platform_id', $platformId)
                ->select('id')
        )
    );

    $updated['billing_wallet_rules'] = 0;
    foreach (($targets['billing_wallet_rules'] ?? collect()) as $row) {
        $update = [];
        if (strtoupper(trim((string) ($row->currency_code ?? ''))) === WRONG_CODE) {
            $update['currency_code'] = CORRECT_CODE;
        }
        foreach (['supported_currencies_json', 'topup_preset_json', 'limit_json', 'auto_renew_json', 'ui_json'] as $column) {
            $replacement = replacementJsonValue($row->{$column} ?? null);
            if ($replacement['changed']) {
                $update[$column] = $replacement['encoded'];
            }
        }
        if ($update !== []) {
            $updated['billing_wallet_rules'] += DB::table('billing_wallet_rules')
                ->where('id', $row->id)
                ->update($update);
        }
    }

    return $updated;
});

say();
say('APPLIED');
foreach ($counts as $table => $count) {
    printf("  %-28s %d row(s) updated\n", $table.':', (int) $count);
}

say();
line('-');
say('VERIFY');
line('-');
$remaining = collectTargets($platformId, $productIds);
printCounts($remaining);
say();
say('Rollback command if anything looks wrong:');
say('  php scripts/prod/mozambique_mtc_to_mzn_fix.php --restore=storage/'.basename($backupPath));
line();

function optionValue(array $args, string $name): ?string
{
    $prefix = $name.'=';
    foreach ($args as $arg) {
        if (str_starts_with($arg, $prefix)) {
            $value = trim(substr($arg, strlen($prefix)));

            return $value === '' ? null : $value;
        }
    }

    return null;
}

function say(string $message = ''): void
{
    echo $message.PHP_EOL;
}

function line(string $character = '='): void
{
    echo str_repeat($character, 96).PHP_EOL;
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

function hasTableAndColumn(string $table, string $column): bool
{
    return Schema::hasTable($table) && Schema::hasColumn($table, $column);
}

function resolvePlatform(?string $platformIdOption): object
{
    if ($platformIdOption !== null) {
        $platform = DB::table('platforms')->where('id', (int) $platformIdOption)->first();
        if (! $platform) {
            fail('No platform found for --platform-id='.$platformIdOption);
        }

        return $platform;
    }

    $platforms = DB::table('platforms')
        ->where(function ($query) {
            $query->where('country', 'like', '%'.MARKET_MATCH.'%')
                ->orWhere('name', 'like', '%'.MARKET_MATCH.'%');
        })
        ->orderBy('id')
        ->get();

    if ($platforms->isEmpty()) {
        fail("No platform matching '".MARKET_MATCH."' was found.");
    }

    if ($platforms->count() > 1) {
        say("Multiple platforms match '".MARKET_MATCH."'; refusing to guess. Re-run with --platform-id=ID.");
        foreach ($platforms as $platform) {
            printf(
                "  id=%s name=%s country=%s currency=%s\n",
                (string) $platform->id,
                (string) $platform->name,
                (string) ($platform->country ?? '-'),
                (string) ($platform->currency_code ?? '-')
            );
        }
        exit(1);
    }

    return $platforms->first();
}

function collectTargets(int $platformId, \Illuminate\Support\Collection $productIds): array
{
    $targets = [];

    if (Schema::hasTable('platforms')) {
        $platformRows = DB::table('platforms')->where('id', $platformId)->get();
        $targets['platforms'] = $platformRows->filter(function ($row): bool {
            if (strtoupper(trim((string) ($row->currency_code ?? ''))) === WRONG_CODE) {
                return true;
            }

            foreach (['supported_currencies', 'wallet_settings'] as $column) {
                if (jsonWouldChange($row->{$column} ?? null)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    $targets['products'] = rowsForSimpleCurrency('products', 'currency', fn ($query) => $query->where('platform_id', $platformId));

    $targets['product_prices'] = $productIds->isEmpty()
        ? collect()
        : rowsForSimpleCurrency('product_prices', 'currency', fn ($query) => $query->whereIn('product_id', $productIds));

    $targets['deals'] = rowsForSimpleCurrency('deals', 'currency', fn ($query) => $query->where('platform_id', $platformId));
    $targets['payments'] = rowsForSimpleCurrency('payments', 'currency', fn ($query) => $query->where('platform_id', $platformId), ['amount', 'status', 'completed_at', 'created_at', 'transaction_reference', 'reference_number']);
    $targets['clients'] = rowsForSimpleCurrency('clients', 'wallet_currency', fn ($query) => $query->where('platform_id', $platformId));
    $targets['wallet_transactions'] = rowsForSimpleCurrency('wallet_transactions', 'currency_code', fn ($query) => $query->where('platform_id', $platformId));

    if (Schema::hasTable('payment_reconciliation_rows') && Schema::hasTable('payment_reconciliation_batches')) {
        $targets['payment_reconciliation_rows'] = rowsForSimpleCurrency(
            'payment_reconciliation_rows',
            'external_currency',
            fn ($query) => $query->whereIn(
                'batch_id',
                DB::table('payment_reconciliation_batches')
                    ->where('platform_id', $platformId)
                    ->select('id')
            )
        );
    }

    if (Schema::hasTable('billing_wallet_rules')) {
        $targets['billing_wallet_rules'] = DB::table('billing_wallet_rules')
            ->where('market_id', $platformId)
            ->get()
            ->filter(function ($row): bool {
                if (strtoupper(trim((string) ($row->currency_code ?? ''))) === WRONG_CODE) {
                    return true;
                }

                foreach (['supported_currencies_json', 'topup_preset_json', 'limit_json', 'auto_renew_json', 'ui_json'] as $column) {
                    if (jsonWouldChange($row->{$column} ?? null)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    return $targets;
}

function rowsForSimpleCurrency(string $table, string $column, callable $scope, array $extraColumns = []): \Illuminate\Support\Collection
{
    if (! hasTableAndColumn($table, $column)) {
        return collect();
    }

    $columns = array_values(array_unique(array_merge(['id', $column], $extraColumns)));
    $query = DB::table($table);
    $scope($query);

    return $query
        ->whereRaw('UPPER(TRIM('.$column.')) = ?', [WRONG_CODE])
        ->orderByDesc('id')
        ->get($columns);
}

function updateSimpleCurrency(string $table, string $column, callable $scope): int
{
    if (! hasTableAndColumn($table, $column)) {
        return 0;
    }

    $query = DB::table($table);
    $scope($query);

    return $query
        ->whereRaw('UPPER(TRIM('.$column.')) = ?', [WRONG_CODE])
        ->update([$column => CORRECT_CODE]);
}

function printCounts(array $targets): void
{
    foreach ($targets as $table => $rows) {
        printf("  %-28s %d\n", $table.':', $rows->count());
    }
}

function totalTargetRows(array $targets): int
{
    $total = 0;
    foreach ($targets as $rows) {
        $total += $rows->count();
    }

    return $total;
}

function writeBackup(int $platformId, array $targets): string
{
    $tables = [];
    foreach ($targets as $table => $rows) {
        $columns = restoreColumnsForTable($table);
        $tables[$table] = $rows
            ->map(function ($row) use ($columns): array {
                $source = (array) $row;
                $backupRow = ['id' => $source['id']];

                foreach ($columns as $column) {
                    if (array_key_exists($column, $source)) {
                        $backupRow[$column] = $source[$column];
                    }
                }

                return $backupRow;
            })
            ->values()
            ->all();
    }

    $backup = [
        'script' => 'mozambique_mtc_to_mzn_fix.php',
        'generated_at' => now()->toIso8601String(),
        'platform_id' => $platformId,
        'from_currency' => WRONG_CODE,
        'to_currency' => CORRECT_CODE,
        'tables' => $tables,
    ];

    $path = storage_path('mozambique_mtc_to_mzn_backup_'.now()->format('Ymd_His').'.json');
    file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $path;
}

function restoreColumnsForTable(string $table): array
{
    return match ($table) {
        'platforms' => ['currency_code', 'supported_currencies', 'wallet_settings'],
        'products', 'product_prices', 'deals', 'payments' => ['currency'],
        'clients' => ['wallet_currency'],
        'wallet_transactions' => ['currency_code'],
        'payment_reconciliation_rows' => ['external_currency'],
        'billing_wallet_rules' => [
            'currency_code',
            'supported_currencies_json',
            'topup_preset_json',
            'limit_json',
            'auto_renew_json',
            'ui_json',
        ],
        default => [],
    };
}

function restoreBackup(string $restoreFile): void
{
    $path = str_starts_with($restoreFile, '/') ? $restoreFile : base_path($restoreFile);
    if (! is_file($path)) {
        fail('Backup file not found: '.$path);
    }

    $backup = json_decode((string) file_get_contents($path), true);
    if (! is_array($backup) || ($backup['script'] ?? null) !== 'mozambique_mtc_to_mzn_fix.php') {
        fail('Backup file is not a valid Mozambique MTC -> MZN backup.');
    }

    DB::transaction(function () use ($backup): void {
        foreach (($backup['tables'] ?? []) as $table => $rows) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! array_key_exists('id', $row)) {
                    continue;
                }
                $id = $row['id'];
                unset($row['id']);
                $row = array_filter(
                    $row,
                    fn (string $column): bool => Schema::hasColumn($table, $column),
                    ARRAY_FILTER_USE_KEY
                );
                if ($row === []) {
                    continue;
                }
                DB::table($table)->where('id', $id)->update($row);
            }
        }
    });

    say('RESTORE COMPLETE from: '.$path);
}

function jsonWouldChange(mixed $value): bool
{
    return replacementJsonValue($value)['changed'];
}

function replacementJsonValue(mixed $value): array
{
    if ($value === null || $value === '') {
        return ['changed' => false, 'encoded' => $value];
    }

    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
    if (! is_array($decoded)) {
        return ['changed' => false, 'encoded' => $value];
    }

    $replaced = replaceExactCurrency($decoded);

    return [
        'changed' => $replaced !== $decoded,
        'encoded' => json_encode($replaced, JSON_UNESCAPED_SLASHES),
    ];
}

function replaceExactCurrency(mixed $value): mixed
{
    if (is_string($value)) {
        return strtoupper(trim($value)) === WRONG_CODE ? CORRECT_CODE : $value;
    }

    if (! is_array($value)) {
        return $value;
    }

    $result = [];
    foreach ($value as $key => $item) {
        $newKey = is_string($key) && strtoupper(trim($key)) === WRONG_CODE ? CORRECT_CODE : $key;
        $result[$newKey] = replaceExactCurrency($item);
    }

    if (array_is_list($result)) {
        $allScalars = collect($result)->every(fn ($item): bool => is_scalar($item) || $item === null);
        if ($allScalars) {
            $result = array_values(array_unique($result, SORT_REGULAR));
        }
    }

    return $result;
}
