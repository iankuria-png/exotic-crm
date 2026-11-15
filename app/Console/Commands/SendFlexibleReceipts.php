<?php

namespace App\Console\Commands;

use App\Models\FlexiblePaymentReceipt;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendFlexibleReceipts extends Command
{
    protected $signature = 'flexible-amounts:receipts {--limit=10}';

    protected $description = 'Deliver bounded flexible subscription receipts without ambiguous retries';

    public function handle(): int
    {
        FlexiblePaymentReceipt::where('state', 'sending')->where('updated_at', '<', now()->subMinutes(5))->update(['state' => 'needs_review', 'outcome' => 'worker_interrupted']);
        foreach (FlexiblePaymentReceipt::where('state', 'pending')->where('next_attempt_at', '<=', now())->orderBy('id')->limit(max(1, min(20, (int) $this->option('limit'))))->pluck('id') as $id) {
            $row = DB::transaction(function () use ($id) {
                $row = FlexiblePaymentReceipt::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($row->state !== 'pending') {
                    return null;
                }
                $p = $row->payment;
                if ($p->provider_environment !== 'production' || $p->status !== 'completed' || data_get($p->payment_data, 'provisioning_status') !== 'completed' || $p->isClassifiedTest()) {
                    $row->update(['state' => 'needs_review', 'outcome' => 'activation_prerequisite']);

                    return null;
                }
                $row->update(['state' => 'sending', 'next_attempt_at' => null]);

                return $row;
            });
            if (! $row) {
                continue;
            }
            try {
                $p = $row->payment;
                $result = app(NotificationService::class)->sendSms($row->phone, $row->body, ['platform_id' => $p->platform_id, 'client_id' => $p->client_id, 'purpose' => 'flexible_subscription_receipt', 'skip_fallback' => true]);
                $disabled = ($result['status'] ?? '') === 'disabled';
                $row->update(['state' => $disabled ? 'pending' : (! empty($result['success']) ? 'sent' : 'needs_review'), 'outcome' => $disabled ? 'transport_disabled' : (! empty($result['success']) ? 'accepted_by_transport' : 'delivery_unconfirmed'), 'next_attempt_at' => $disabled ? now()->addHour() : null]);
            } catch (\Throwable) {
                $row->update(['state' => 'needs_review', 'outcome' => 'ambiguous_transport_result']);
            }
        }

        return self::SUCCESS;
    }
}
