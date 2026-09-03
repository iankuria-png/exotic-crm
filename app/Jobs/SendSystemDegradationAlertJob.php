<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NotificationService;
use App\Services\Ops\LoadShedder;
use App\Services\Ops\OperationsSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Announces a degradation transition. Dispatched on transition only — never per
 * sample — so a system sitting at Limp for an hour pages once, not sixty times.
 *
 * Reuses the market-down alert path: same admin recipient list, same SMS
 * service, `ShouldBeUnique` for dedup, plus a configurable cooldown on top.
 * Push is deliberately not a channel here, because the push lane is itself shed
 * at level 2 and so cannot be trusted to carry news of level 2.
 */
class SendSystemDegradationAlertJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const COOLDOWN_CACHE_KEY = 'ops.alerts.last_sms_at';

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $fromLevel,
        public readonly int $toLevel,
        public readonly string $triggerSignal,
        public readonly ?float $triggerValue = null,
        public readonly ?float $threshold = null,
    ) {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return sprintf('system-degradation:%d:%d:%s', $this->fromLevel, $this->toLevel, $this->triggerSignal);
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(NotificationService $notificationService, OperationsSettingsService $settings): void
    {
        if (! $settings->boolean('ops.alerts.sms_enabled')) {
            return;
        }

        // Recovery to Normal is worth a banner but is not worth a phone call.
        if ($this->toLevel <= LoadShedder::LEVEL_NORMAL) {
            return;
        }

        $critical = $this->toLevel >= LoadShedder::LEVEL_CRITICAL;

        if (! $critical && $this->inQuietHours($settings)) {
            Log::info('Degradation alert suppressed by quiet hours.', [
                'to_level' => $this->toLevel,
                'trigger_signal' => $this->triggerSignal,
            ]);

            return;
        }

        if (! $critical && ! $this->cooldownElapsed($settings)) {
            Log::info('Degradation alert suppressed by cooldown.', [
                'to_level' => $this->toLevel,
                'trigger_signal' => $this->triggerSignal,
            ]);

            return;
        }

        $message = $this->buildMessage();
        $sent = 0;

        foreach ($this->recipients() as $recipient) {
            $phone = trim((string) ($recipient->phone ?? ''));

            if ($phone === '') {
                continue;
            }

            $result = $notificationService->sendSms($phone, $message, [
                'alert_type' => 'system_degradation',
            ]);

            if ($result['success'] ?? false) {
                $sent++;
            } else {
                Log::warning('Degradation SMS dispatch failed.', [
                    'recipient_id' => (int) $recipient->id,
                    'provider_result' => $result,
                ]);
            }
        }

        if ($sent > 0) {
            $this->markSent();
        }
    }

    /**
     * The market-down recipient list: active admins and sub_admins who have
     * opted into market-down SMS.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function recipients(): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereIn('role', ['admin', 'sub_admin'])
            ->where('status', 'active')
            ->get(['id', 'name', 'role', 'status', 'phone', 'notification_prefs', 'is_ceo', 'assigned_market_ids'])
            ->filter(fn (User $user): bool => $user->marketDownSmsEnabled())
            ->values();
    }

    private function buildMessage(): string
    {
        $signal = str_replace('_', ' ', $this->triggerSignal);

        $reading = $this->triggerValue === null
            ? ''
            : sprintf(' %s at %s%s.', ucfirst($signal), rtrim(rtrim(number_format($this->triggerValue, 2, '.', ''), '0'), '.'),
                $this->threshold === null
                    ? ''
                    : ' vs threshold '.rtrim(rtrim(number_format($this->threshold, 2, '.', ''), '0'), '.'));

        return sprintf(
            'CRM system health: %s → %s.%s Check Settings → Operations.',
            LoadShedder::label($this->fromLevel),
            LoadShedder::label($this->toLevel),
            $reading
        );
    }

    private function inQuietHours(OperationsSettingsService $settings): bool
    {
        if (! $settings->boolean('ops.alerts.quiet_hours_enabled')) {
            return false;
        }

        try {
            $now = Carbon::now('Africa/Nairobi');
            $start = $settings->string('ops.alerts.quiet_hours_start');
            $end = $settings->string('ops.alerts.quiet_hours_end');
        } catch (\Throwable) {
            return false;
        }

        $current = $now->format('H:i');

        // A window that wraps past midnight (22:00 → 06:00) is the normal case,
        // so it is handled first rather than as an edge case.
        return $start <= $end
            ? ($current >= $start && $current < $end)
            : ($current >= $start || $current < $end);
    }

    private function cooldownElapsed(OperationsSettingsService $settings): bool
    {
        try {
            $lastSentAt = Cache::get(self::COOLDOWN_CACHE_KEY);
        } catch (\Throwable) {
            return true;
        }

        if (! is_numeric($lastSentAt)) {
            return true;
        }

        return (time() - (int) $lastSentAt) >= ($settings->integer('ops.alerts.cooldown_minutes') * 60);
    }

    private function markSent(): void
    {
        try {
            Cache::put(self::COOLDOWN_CACHE_KEY, time(), now()->addDay());
        } catch (\Throwable) {
            // A lost cooldown marker risks one extra SMS, not a missed one.
        }
    }
}
