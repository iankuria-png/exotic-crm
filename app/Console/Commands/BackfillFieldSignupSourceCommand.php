<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use Illuminate\Console\Command;

class BackfillFieldSignupSourceCommand extends Command
{
    protected $signature = 'crm:backfill-field-signup-source
        {--apply : Persist updates. Without this flag the command is preview-only.}';

    protected $description = 'Re-tag clients created by field_sales users whose signup_source was clobbered by a WP re-sync';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $fieldAgentIds = User::query()
            ->where('role', MarketAuthorizationService::ROLE_FIELD_SALES)
            ->pluck('id');

        if ($fieldAgentIds->isEmpty()) {
            $this->info('No field_sales users found.');
            return self::SUCCESS;
        }

        $query = Client::query()
            ->whereIn('created_by', $fieldAgentIds)
            ->where(function ($q) {
                $q->whereNull('signup_source')->orWhere('signup_source', '!=', 'field');
            });

        $count = (clone $query)->count();
        $this->info("Found {$count} field-agent-created clients with non-'field' signup_source.");

        if ($count === 0) {
            return self::SUCCESS;
        }

        (clone $query)
            ->limit(20)
            ->get(['id', 'name', 'phone_normalized', 'signup_source', 'created_by'])
            ->each(function ($client) {
                $this->line(sprintf(
                    '  #%d  %s  source=%s  created_by=%d',
                    $client->id,
                    $client->name ?: '(no name)',
                    $client->signup_source ?: '(null)',
                    $client->created_by
                ));
            });

        if (!$apply) {
            $this->warn('Preview only. Re-run with --apply to persist.');
            return self::SUCCESS;
        }

        $updated = $query->update(['signup_source' => 'field']);
        $this->info("Updated {$updated} client(s) to signup_source='field'.");

        return self::SUCCESS;
    }
}
