<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\PaymentClientIdentityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RetryUnlinkedPaymentClientJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 300;

    public function __construct(public ?int $platformId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->platformId ?? 'all');
    }

    public function handle(PaymentClientIdentityService $identity): void
    {
        $ids = Payment::query()->businessVisible()->where('purpose', Payment::PURPOSE_SUBSCRIPTION)
            ->where('status', 'completed')->where('amount', '>', 0)->whereNull('client_id')
            ->when($this->platformId, fn ($q) => $q->where('platform_id', $this->platformId))
            ->where(fn ($q) => $q->where('user_id', '>', 0)->orWhere('escort_post_id', '>', 0))
            ->whereDoesntHave('attempts', fn ($q) => $q->where('attempt_type', 'client_link_recovery')->where('created_at', '>=', now()->subMinutes(15)))
            ->orderBy('id')->limit(50)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $identity) {
                $payment = Payment::query()->lockForUpdate()->find($id);
                if (! $payment || $payment->client_id || $payment->status !== 'completed'
                    || $payment->purpose !== Payment::PURPOSE_SUBSCRIPTION || $payment->isClassifiedTest() || $payment->isSandboxTest()) {
                    return;
                }
                $result = $identity->resolve($payment);
                if ($result['matched']) {
                    $client = \App\Models\Client::query()->lockForUpdate()->find($result['client']->id);
                    $checked = $identity->resolve($payment);
                    if (! $client || ! $checked['matched'] || $checked['client']->id !== $client->id) {
                        return;
                    }
                    // Association only: never call completion, provisioning, matching-confirmation or benefit services.
                    $updates = ['client_id' => $client->id, 'reconciliation_state' => 'manual_review',
                        'reconciliation_confidence' => 'high', 'match_confidence' => 'auto_high'];
                    DB::table('payments')->where('id', $payment->id)->update($updates);
                    $audit = app(\App\Services\AuditService::class)->record(['platform_id' => $payment->platform_id,
                        'action' => 'payment_client_identity_recovery', 'entity_type' => 'payment', 'entity_id' => $payment->id,
                        'before_state' => ['client_id' => null], 'after_state' => $updates,
                        'reason' => 'Unique same-market WordPress identity; client association only. Paid fulfillment requires review.']);
                    if (! $audit) {
                        throw new \RuntimeException('Payment association audit could not be saved.');
                    }
                }
                $payment->attempts()->create(['attempt_type' => 'client_link_recovery',
                    'status' => $result['matched'] ? 'completed' : 'pending',
                    'response_meta' => ['client_id' => $result['client']?->id, 'reason' => $result['reason'] ?? 'Linked; fulfillment review retained.']]);
            });
        }
    }
}
