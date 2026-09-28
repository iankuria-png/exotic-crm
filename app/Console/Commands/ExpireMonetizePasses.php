<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientMonetizationPass;
use App\Models\PremiumContentEvent;
use App\Services\Monetization\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireMonetizePasses extends Command
{
    protected $signature = 'monetize:expire-passes';

    protected $description = 'Expire private-content passes, promote queued terms and record renewal reminders.';

    public function handle(): int
    {
        foreach (ClientMonetizationPass::whereIn('status', ['active', 'queued'])->distinct()->pluck('client_id')->filter() as $id) {
            DB::transaction(function () use ($id) {
                $client = Client::whereKey($id)->lockForUpdate()->first();
                if (! $client) {
                    return;
                }
                $passes = ClientMonetizationPass::where('client_id', $id)->whereIn('status', ['active', 'queued'])->orderBy('starts_at')->get();
                foreach ($passes as $pass) {
                    if ($pass->expires_at->lte(now())) {
                        $pass->update(['status' => 'expired', 'active_marker' => null]);
                        if (! $pass->expiry_notified_at) {
                            $this->notice($pass, 'pass_expired');
                            $pass->update(['expiry_notified_at' => now()]);
                        }
                    } elseif ($pass->starts_at->lte(now()) && $pass->status === 'queued') {
                        $pass->update(['status' => 'active', 'active_marker' => 1]);
                    } elseif ($pass->expires_at->lte(now()->addDays(3)) && ! $pass->reminded_at) {
                        $this->notice($pass, 'pass_expiring');
                        $pass->update(['reminded_at' => now()]);
                    }
                }
            });
            if ($client = Client::find($id)) {
                app(SyncService::class)->profile($client);
            }
        }

        return self::SUCCESS;
    }

    private function notice($pass, $kind): void
    {
        $notice = PremiumContentEvent::create(['platform_id' => $pass->platform_id, 'client_id' => $pass->client_id, 'kind' => $kind, 'reason' => 'Your private content pass '.($kind === 'pass_expired' ? 'has expired.' : 'expires in 3 days.'), 'metadata_json' => ['pass_id' => $pass->id, 'is_sandbox' => $pass->is_sandbox]]);
        if (! $pass->is_sandbox) {
            \App\Jobs\SendMonetizationNotice::dispatch($notice->id)->afterCommit();
        }
    }
}
