<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SMS to admins and sub-admins who opted into FX refresh alerts. Repeat suppression
 * happens in ReportingFxRefreshService, which only dispatches on a new issue or once
 * per day while the same issue persists.
 */
class SendFxRefreshAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  array<int, string>  $issues
     */
    public function __construct(
        public readonly string $status,
        public readonly array $issues,
    ) {
        $this->onQueue('alerts');
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(NotificationService $notificationService): void
    {
        $message = $this->buildMessage();

        foreach ($this->recipients() as $recipient) {
            $phone = trim((string) ($recipient->phone ?? ''));
            if ($phone === '') {
                continue;
            }

            $result = $notificationService->sendSms($phone, $message, ['alert_type' => 'fx_refresh']);
            if (! ($result['success'] ?? false)) {
                Log::warning('FX refresh SMS dispatch failed.', [
                    'recipient_id' => (int) $recipient->id,
                    'provider_result' => $result,
                ]);
            }
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        return User::query()
            ->whereIn('role', ['admin', 'sub_admin'])
            ->where('status', 'active')
            ->get(['id', 'name', 'role', 'status', 'phone', 'notification_prefs'])
            ->filter(fn (User $user): bool => $user->fxRefreshSmsEnabled())
            ->values();
    }

    private function buildMessage(): string
    {
        $first = Str::limit((string) ($this->issues[0] ?? 'Unknown issue.'), 110);
        $more = count($this->issues) > 1 ? ' (+'.(count($this->issues) - 1).' more)' : '';

        return "Exotic CRM: FX rate refresh {$this->status}. {$first}{$more} See Settings > Dashboard > Reporting Currency.";
    }
}
