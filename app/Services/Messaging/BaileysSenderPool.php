<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppProviderProfile;
use App\Models\WhatsAppSender;

class BaileysSenderPool
{
    public function pickFor(WhatsAppProviderProfile $profile): ?WhatsAppSender
    {
        if ($profile->engine !== 'baileys' || $profile->kill_switch_enabled || !$profile->active) {
            return null;
        }

        return WhatsAppSender::query()
            ->where('provider_profile_id', $profile->id)
            ->active()
            ->where('connection_status', WhatsAppSender::STATUS_CONNECTED)
            ->where(function ($query) {
                $query->whereNull('quarantine_until')
                    ->orWhere('quarantine_until', '<=', now());
            })
            ->whereColumn('daily_sent_count', '<', 'daily_limit')
            ->orderBy('last_message_at')
            ->orderBy('id')
            ->first();
    }

    public function recordAccepted(WhatsAppSender $sender): void
    {
        $sender->forceFill([
            'daily_sent_count' => (int) $sender->daily_sent_count + 1,
            'last_message_at' => now(),
            'consecutive_failures' => 0,
        ])->save();
    }

    public function recordFailure(WhatsAppSender $sender): void
    {
        $failures = (int) $sender->consecutive_failures + 1;
        $updates = ['consecutive_failures' => $failures];

        if ($failures >= 5) {
            $updates['quarantine_until'] = now()->addHours(6);
        }

        $sender->forceFill($updates)->save();
    }
}
