<?php

/**
 * Demo account cleanup — remove sales@exoticnairobi.com from commissions and
 * reassign their subscriptions (deals) to real sales agents by market.
 *
 *   Kenya  -> ben.kiura@exotic-online.com
 *   Ghana  -> lavender.aoko@exotic-online.com
 *
 * Stages: DRY-RUN (default) -> BACKUP -> APPLY -> VERIFY.
 *
 * Usage (from the app root, ~/crm.exotic-online.com):
 *   php scripts/prod/demo_account_cleanup.php                  # dry run, changes nothing
 *   php scripts/prod/demo_account_cleanup.php --apply          # delete EARNED commissions + reassign deals
 *   php scripts/prod/demo_account_cleanup.php --apply --include-paid     # also delete PAID commissions
 *   php scripts/prod/demo_account_cleanup.php --apply --include-clients  # also reassign clients.assigned_to
 *   php scripts/prod/demo_account_cleanup.php --restore=storage/demo_cleanup_backup_YYYYmmdd_HHiiss.json
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const DEMO_EMAIL = 'sales@exoticnairobi.com';
const MARKET_AGENTS = [
    'kenya' => 'ben.kiura@exotic-online.com',
    'ghana' => 'lavender.aoko@exotic-online.com',
];

$args = array_slice($_SERVER['argv'], 1);
$apply = in_array('--apply', $args, true);
$includePaid = in_array('--include-paid', $args, true);
$includeClients = in_array('--include-clients', $args, true);
$restoreFile = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--restore=')) {
        $restoreFile = substr($arg, strlen('--restore='));
    }
}

function say(string $line = ''): void
{
    echo $line . PHP_EOL;
}

/* ---------------------------------------------------------------- RESTORE */

if ($restoreFile !== null) {
    $path = str_starts_with($restoreFile, '/') ? $restoreFile : base_path($restoreFile);
    if (!is_file($path)) {
        say("RESTORE FAILED: backup file not found: {$path}");
        exit(1);
    }

    $backup = json_decode((string) file_get_contents($path), true);
    if (!is_array($backup)) {
        say('RESTORE FAILED: backup file is not valid JSON.');
        exit(1);
    }

    DB::transaction(function () use ($backup) {
        foreach (($backup['commissions'] ?? []) as $row) {
            DB::table('commissions')->updateOrInsert(['id' => $row['id']], $row);
        }
        foreach (($backup['deals'] ?? []) as $row) {
            DB::table('deals')->where('id', $row['id'])->update([
                'assigned_to' => $row['assigned_to'],
                'activated_by_field_agent' => $row['activated_by_field_agent'],
            ]);
        }
        foreach (($backup['clients'] ?? []) as $row) {
            DB::table('clients')->where('id', $row['id'])->update([
                'assigned_to' => $row['assigned_to'],
            ]);
        }
    });

    say('RESTORE COMPLETE.');
    say('  commissions restored: ' . count($backup['commissions'] ?? []));
    say('  deals restored:       ' . count($backup['deals'] ?? []));
    say('  clients restored:     ' . count($backup['clients'] ?? []));
    exit(0);
}

/* ----------------------------------------------------------------- LOOKUP */

$demo = DB::table('users')->where('email', DEMO_EMAIL)->first();
if (!$demo) {
    say('ABORT: demo user not found by email ' . DEMO_EMAIL);
    exit(1);
}

$agents = [];
foreach (MARKET_AGENTS as $marketKey => $email) {
    $agent = DB::table('users')->where('email', $email)->first();
    if (!$agent) {
        say("ABORT: target agent not found: {$email}");
        exit(1);
    }
    if (($agent->status ?? 'active') !== 'active') {
        say("ABORT: target agent {$email} is not active (status: {$agent->status}).");
        exit(1);
    }
    $agents[$marketKey] = $agent;
}

say('=== Demo account cleanup — ' . ($apply ? 'APPLY' : 'DRY RUN (no changes will be made)') . ' ===');
say();
say("Demo user: #{$demo->id} {$demo->name} <{$demo->email}> role={$demo->role} status={$demo->status}");
foreach ($agents as $marketKey => $agent) {
    say('Target for ' . ucfirst($marketKey) . ": #{$agent->id} {$agent->name} <{$agent->email}> role={$agent->role}");
}
say();

/* ------------------------------------------------------------ COMMISSIONS */

$commissions = DB::table('commissions')->where('agent_user_id', $demo->id)->get();
$earned = $commissions->where('status', 'earned');
$paid = $commissions->where('status', 'paid');
$otherStatus = $commissions->whereNotIn('status', ['earned', 'paid']);

say('--- Commissions held by Demo ---');
say('  earned: ' . $earned->count() . ' rows, totals by currency: ' . json_encode(
    $earned->groupBy('currency')->map(fn ($rows) => (float) $rows->sum('amount'))
));
say('  paid:   ' . $paid->count() . ' rows (linked to payout history)' . ($paid->count() > 0 && !$includePaid ? ' — KEPT unless --include-paid' : ''));
if ($otherStatus->count() > 0) {
    say('  other statuses: ' . json_encode($otherStatus->groupBy('status')->map->count()));
}
$commissionsToDelete = $includePaid ? $commissions : $commissions->where('status', '!=', 'paid');
say('  WILL DELETE: ' . $commissionsToDelete->count() . ' commission rows');
say();

/* ------------------------------------------------------------------ DEALS */

$deals = DB::table('deals')
    ->where(function ($query) use ($demo) {
        $query->where('assigned_to', $demo->id)
            ->orWhere('activated_by_field_agent', $demo->id);
    })
    ->get();

$platformIds = $deals->pluck('platform_id')->filter()->unique()->values();
$platforms = DB::table('platforms')->whereIn('id', $platformIds)->get()->keyBy('id');

$resolveMarketKey = function ($platformId) use ($platforms): ?string {
    $platform = $platforms->get($platformId);
    if (!$platform) {
        return null;
    }
    $haystack = strtolower(trim(($platform->country ?? '') . ' ' . ($platform->name ?? '')));
    foreach (array_keys(MARKET_AGENTS) as $marketKey) {
        if (str_contains($haystack, $marketKey)) {
            return $marketKey;
        }
    }
    return null;
};

$dealPlan = ['kenya' => collect(), 'ghana' => collect(), 'unmapped' => collect()];
foreach ($deals as $deal) {
    $marketKey = $resolveMarketKey($deal->platform_id);
    $dealPlan[$marketKey ?? 'unmapped']->push($deal);
}

say('--- Deals (subscriptions) referencing Demo (assigned_to OR activated_by_field_agent) ---');
say('  total: ' . $deals->count());
foreach (['kenya', 'ghana'] as $marketKey) {
    $group = $dealPlan[$marketKey];
    say('  ' . ucfirst($marketKey) . ' -> ' . $agents[$marketKey]->email . ': ' . $group->count() . ' deals '
        . json_encode($group->groupBy('status')->map->count()));
}
if ($dealPlan['unmapped']->count() > 0) {
    say('  UNMAPPED (left untouched, review manually): ' . $dealPlan['unmapped']->count());
    foreach ($dealPlan['unmapped']->groupBy('platform_id') as $pid => $group) {
        $platform = $platforms->get($pid);
        say('    platform #' . $pid . ' (' . ($platform->name ?? 'unknown') . ' / ' . ($platform->country ?? '?') . '): ' . $group->count() . ' deals');
    }
}
say();

/* ---------------------------------------------------------------- CLIENTS */

$clients = DB::table('clients')->where('assigned_to', $demo->id)->get();
$clientPlan = ['kenya' => collect(), 'ghana' => collect(), 'unmapped' => collect()];
if ($clients->count() > 0) {
    $clientPlatformIds = $clients->pluck('platform_id')->filter()->unique()->values();
    $clientPlatforms = DB::table('platforms')->whereIn('id', $clientPlatformIds)->get()->keyBy('id');
    foreach ($clients as $client) {
        $platform = $clientPlatforms->get($client->platform_id);
        $haystack = strtolower(trim(($platform->country ?? '') . ' ' . ($platform->name ?? '')));
        $marketKey = null;
        foreach (array_keys(MARKET_AGENTS) as $candidate) {
            if ($haystack !== '' && str_contains($haystack, $candidate)) {
                $marketKey = $candidate;
                break;
            }
        }
        $clientPlan[$marketKey ?? 'unmapped']->push($client);
    }
}

say('--- Clients assigned to Demo (clients.assigned_to) ---');
say('  total: ' . $clients->count() . ($clients->count() > 0 && !$includeClients ? ' — KEPT unless --include-clients' : ''));
foreach (['kenya', 'ghana'] as $marketKey) {
    if ($clientPlan[$marketKey]->count() > 0) {
        say('  ' . ucfirst($marketKey) . ' -> ' . $agents[$marketKey]->email . ': ' . $clientPlan[$marketKey]->count());
    }
}
if ($clientPlan['unmapped']->count() > 0) {
    say('  unmapped markets: ' . $clientPlan['unmapped']->count() . ' (left untouched)');
}
say();

/* ----------------------------------------------- OTHER REFERENCES (report) */

say('--- Other references to Demo (report only, never modified by this script) ---');
say('  deals.free_trial_approved_by:  ' . DB::table('deals')->where('free_trial_approved_by', $demo->id)->count());
say('  deals.discount_approved_by:    ' . DB::table('deals')->where('discount_approved_by', $demo->id)->count());
say('  payments.confirmed_by:         ' . DB::table('payments')->where('confirmed_by', $demo->id)->count());
say('  audit_log rows (actor):        ' . DB::table('audit_log')->where('actor_id', $demo->id)->count());
say();

if (!$apply) {
    say('DRY RUN COMPLETE — nothing was changed.');
    say('Re-run with --apply to execute. Add --include-paid and/or --include-clients if the numbers above look right.');
    exit(0);
}

/* ----------------------------------------------------------------- BACKUP */

$timestamp = date('Ymd_His');
$backupPath = storage_path("demo_cleanup_backup_{$timestamp}.json");
$backupPayload = [
    'generated_at' => date('c'),
    'demo_user_id' => $demo->id,
    'commissions' => $commissionsToDelete->map(fn ($row) => (array) $row)->values()->all(),
    'deals' => $deals->map(fn ($row) => [
        'id' => $row->id,
        'assigned_to' => $row->assigned_to,
        'activated_by_field_agent' => $row->activated_by_field_agent,
    ])->values()->all(),
    'clients' => $includeClients
        ? $clients->map(fn ($row) => ['id' => $row->id, 'assigned_to' => $row->assigned_to])->values()->all()
        : [],
];
file_put_contents($backupPath, json_encode($backupPayload, JSON_PRETTY_PRINT));
say('BACKUP written: ' . $backupPath);
say('Restore command if anything looks wrong:');
say('  php scripts/prod/demo_account_cleanup.php --restore=storage/' . basename($backupPath));
say();

/* ------------------------------------------------------------------ APPLY */

DB::transaction(function () use ($demo, $agents, $dealPlan, $clientPlan, $commissionsToDelete, $includeClients) {
    $deletedCommissions = DB::table('commissions')
        ->whereIn('id', $commissionsToDelete->pluck('id'))
        ->delete();
    say("APPLY: deleted {$deletedCommissions} commission rows.");

    foreach (['kenya', 'ghana'] as $marketKey) {
        $ids = $dealPlan[$marketKey]->pluck('id');
        if ($ids->isEmpty()) {
            continue;
        }
        $agentId = $agents[$marketKey]->id;
        DB::table('deals')->whereIn('id', $ids)->where('assigned_to', $demo->id)
            ->update(['assigned_to' => $agentId]);
        DB::table('deals')->whereIn('id', $ids)->where('activated_by_field_agent', $demo->id)
            ->update(['activated_by_field_agent' => $agentId]);
        say('APPLY: reassigned ' . $ids->count() . ' ' . ucfirst($marketKey) . ' deals to ' . $agents[$marketKey]->email);
    }

    if ($includeClients) {
        foreach (['kenya', 'ghana'] as $marketKey) {
            $ids = $clientPlan[$marketKey]->pluck('id');
            if ($ids->isEmpty()) {
                continue;
            }
            DB::table('clients')->whereIn('id', $ids)->where('assigned_to', $demo->id)
                ->update(['assigned_to' => $agents[$marketKey]->id]);
            say('APPLY: reassigned ' . $ids->count() . ' ' . ucfirst($marketKey) . ' clients to ' . $agents[$marketKey]->email);
        }
    }

    DB::table('audit_log')->insert([
        'platform_id' => null,
        'actor_id' => $demo->id,
        'action' => 'role_update',
        'entity_type' => 'user',
        'entity_id' => $demo->id,
        'reason' => 'Demo account cleanup: commissions removed, deals reassigned to market sales agents.',
        'created_at' => now(),
    ]);
});
say();

/* ----------------------------------------------------------------- VERIFY */

say('--- VERIFY (post-state) ---');
$remainingCommissions = DB::table('commissions')->where('agent_user_id', $demo->id)->count();
$remainingDeals = DB::table('deals')
    ->where(function ($query) use ($demo) {
        $query->where('assigned_to', $demo->id)->orWhere('activated_by_field_agent', $demo->id);
    })->count();
$remainingClients = DB::table('clients')->where('assigned_to', $demo->id)->count();

say("  commissions still on Demo: {$remainingCommissions}" . ($includePaid ? ' (expected 0)' : ' (expected = paid rows kept)'));
say("  deals still on Demo:       {$remainingDeals} (expected = unmapped market deals only)");
say("  clients still on Demo:     {$remainingClients}" . ($includeClients ? ' (expected = unmapped only)' : ' (untouched by design)'));
foreach (['kenya', 'ghana'] as $marketKey) {
    $agentId = $agents[$marketKey]->id;
    say('  deals now on ' . $agents[$marketKey]->email . ': '
        . DB::table('deals')->where('assigned_to', $agentId)->count() . ' assigned / '
        . DB::table('deals')->where('activated_by_field_agent', $agentId)->count() . ' field-activated');
}
say();
say('DONE. If the VERIFY numbers match expectations, the cleanup succeeded.');
say('Reminder: this script does NOT suspend the Demo login. Do that in Settings -> Roles & Permissions -> Edit -> status inactive.');
