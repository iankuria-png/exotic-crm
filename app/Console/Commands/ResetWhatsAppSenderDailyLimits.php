<?php

namespace App\Console\Commands;

use App\Models\WhatsAppSender;
use Illuminate\Console\Command;

class ResetWhatsAppSenderDailyLimits extends Command
{
    protected $signature = 'crm:reset-whatsapp-sender-limits {--dry-run : Show matching senders without updating them}';

    protected $description = 'Reset Baileys WhatsApp sender daily counters when their market-local reset time is due.';

    public function handle(): int
    {
        $now = now();
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        WhatsAppSender::query()
            ->with('providerProfile.market')
            ->active()
            ->where(function ($query) use ($now) {
                $query->whereNull('daily_sent_resets_at')
                    ->orWhere('daily_sent_resets_at', '<=', $now);
            })
            ->orderBy('id')
            ->chunkById(100, function ($senders) use (&$updated, $dryRun, $now) {
                foreach ($senders as $sender) {
                    $market = $sender->providerProfile?->market;
                    $timezone = $market?->timezone ?: config('app.timezone', 'UTC');
                    $nextReset = $now->copy()->timezone($timezone)->addDay()->startOfDay()->timezone('UTC');

                    $updated++;
                    if ($dryRun) {
                        $this->line(sprintf(
                            'Would reset sender #%d (%s), next reset %s',
                            $sender->id,
                            $sender->phone_e164,
                            $nextReset->toIso8601String()
                        ));
                        continue;
                    }

                    $sender->forceFill([
                        'daily_sent_count' => 0,
                        'daily_sent_resets_at' => $nextReset,
                    ])->save();
                }
            });

        $this->info(sprintf('%d WhatsApp sender daily counter(s) %s.', $updated, $dryRun ? 'matched' : 'reset'));

        return self::SUCCESS;
    }
}
