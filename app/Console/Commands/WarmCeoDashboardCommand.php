<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CeoDashboardResponseCache;
use Illuminate\Console\Command;

class WarmCeoDashboardCommand extends Command
{
    protected $signature = 'crm:warm-ceo-dashboard';

    protected $description = 'Pre-compute the CEO dashboard default view so it opens from cache.';

    public function handle(CeoDashboardResponseCache $cache): int
    {
        if (! $cache->enabled()) {
            $this->info('CEO dashboard cache is disabled.');

            return self::SUCCESS;
        }

        // Same rule as EnsureCeoAccess: only active CEO admins can open the dashboard.
        $users = User::query()
            ->where('role', 'admin')
            ->where('is_ceo', true)
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($users as $user) {
            $timings = $cache->warmDefaultView($user);
            $this->line(sprintf(
                'user %d: %s',
                $user->id,
                collect($timings)->map(fn ($seconds, $endpoint) => "{$endpoint}={$seconds}s")->implode(' ')
            ));
        }

        $this->info("Warmed CEO dashboard for {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
