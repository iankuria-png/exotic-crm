<?php

namespace App\Services\Collections;

use App\Models\Payment;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\SubscriptionActivationAuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who activated the subscription behind each incoming receipt, resolved for a whole queue page at once.
 * Automatic collection effects are recorded without a person, so the allocation decision source is the
 * authority for automation; staff receipt sales carry their session actor; codes already recorded
 * elsewhere use the subscription activation audit for their exact payment.
 */
class CollectionActivationAttribution
{
    /** @param  array<int, array>  $usage  receipt id => CollectionReceiptStatusService usage */
    public function forReceipts(Collection $receipts, array $usage): array
    {
        if ($receipts->isEmpty()) {
            return [];
        }
        $ids = $receipts->pluck('id')->all();
        $reserved = DB::table('collection_audit')->whereIn('receipt_id', $ids)->whereIn('action', ['collection_allocation_reserved', 'correction_verified'])
            ->whereNotNull('actor_id')->orderByDesc('id')->get(['receipt_id', 'actor_id', 'action', 'created_at'])->unique('receipt_id')->keyBy('receipt_id');
        $actorIds = $reserved->pluck('actor_id');
        foreach ($receipts as $r) {
            if ($r->relationLoaded('sale') && $r->sale) {
                $actorIds->push($r->sale->actor_id);
            }
        }
        $users = User::whereIn('id', $actorIds->filter()->unique()->all())->get(['id', 'name', 'role'])->keyBy('id');
        $recorded = $this->recordedPayments($receipts, $usage);
        $out = [];
        foreach ($receipts as $r) {
            $out[$r->id] = $this->resolve($r, $usage[$r->id] ?? null, $reserved, $users, $recorded);
        }

        return $out;
    }

    private function resolve($r, ?array $usage, Collection $reserved, Collection $users, array $recorded): ?array
    {
        $allocation = $r->allocation;
        if ($allocation) {
            if ($allocation->state === 'unassigned') {
                return null;
            }
            $correction = $reserved[$r->id] ?? null;
            $at = $allocation->effects?->first(fn ($e) => ! $e->superseded_at && $e->state === 'succeeded' && in_array($e->effect_kind, ['primary_paid', 'target_paid', 'verification_only']))?->verified_at ?? $allocation->created_at;
            if (($correction->action ?? null) === 'correction_verified') {
                return $this->staff($users[$correction->actor_id] ?? null, $at?->toIso8601String(), ! $this->confirmed($r), 'Payment correction');
            }
            if ($allocation->decision_source === 'automatic') {
                return ['kind' => 'automatic', 'actor' => null, 'at' => $at?->toIso8601String(), 'pending' => ! $this->confirmed($r), 'via' => 'Automatic activation'];
            }
            $row = $reserved[$r->id] ?? null;

            return $this->staff($row ? $users[$row->actor_id] ?? null : null, $at?->toIso8601String(), ! $this->confirmed($r), 'Incoming payments');
        }
        $sale = $r->relationLoaded('sale') ? $r->sale : null;
        if ($sale && in_array($sale->state, ['prepared', 'activating', 'partial', 'confirmed', 'needs_review'], true)) {
            return $this->staff($users[$sale->actor_id] ?? null, ($sale->confirmed_at ?? $sale->updated_at)?->toIso8601String(), $sale->state !== 'confirmed', 'Incoming payments');
        }
        if (($usage['state'] ?? null) === 'already_recorded') {
            $paymentId = $usage['matches'][0]['payment_id'] ?? null;

            return $paymentId ? ($recorded[$paymentId] ?? null) : null;
        }

        return null;
    }

    private function confirmed($r): bool
    {
        return (bool) $r->allocation?->effects?->contains(fn ($e) => ! $e->superseded_at && $e->state === 'succeeded' && in_array($e->effect_kind, ['primary_paid', 'target_paid', 'verification_only']));
    }

    private function staff(?User $user, ?string $at, bool $pending, string $via): array
    {
        return ['kind' => $user ? 'staff' : 'unknown', 'actor' => $user ? ['id' => $user->id, 'name' => $user->name, 'role' => $user->role] : null,
            'at' => $at, 'pending' => $pending, 'via' => $via];
    }

    /** Batch the activation records for codes already recorded outside this receipt; fall back per payment for legacy rows. */
    private function recordedPayments(Collection $receipts, array $usage): array
    {
        $paymentIds = collect($receipts)->map(fn ($r) => ($usage[$r->id]['state'] ?? null) === 'already_recorded' ? ($usage[$r->id]['matches'][0]['payment_id'] ?? null) : null)
            ->filter()->unique()->values();
        if ($paymentIds->isEmpty()) {
            return [];
        }
        $payments = Payment::with(['deal', 'confirmedBy'])->whereIn('id', $paymentIds)->get()->keyBy('id');
        $events = TimelineEvent::query()->where('entity_type', 'deal')->where('event_type', SubscriptionActivationAuditService::EVENT)
            ->whereIn('entity_id', $payments->pluck('deal_id')->filter()->unique()->all())->orderBy('id')->get()
            ->filter(fn ($e) => isset($payments[(int) data_get($e->content, 'payment_id')]))->unique(fn ($e) => (int) data_get($e->content, 'payment_id'))
            ->keyBy(fn ($e) => (int) data_get($e->content, 'payment_id'));
        $out = [];
        foreach ($payments as $id => $payment) {
            $event = $events[$id] ?? null;
            $result = $event && (int) $event->platform_id === (int) $payment->platform_id
                ? ['state' => data_get($event->content, 'execution') === 'staff' ? 'staff' : 'automatic', 'actor' => data_get($event->content, 'actor'),
                    'activated_at' => data_get($event->content, 'activated_at'), 'source' => data_get($event->content, 'source')]
                : app(SubscriptionActivationAuditService::class)->forPayment($payment);
            $automatic = ($result['state'] ?? null) === 'automatic';
            $staff = ($result['state'] ?? null) === 'staff' && ! empty($result['actor']['name']);
            $out[$id] = ['kind' => $automatic ? 'automatic' : ($staff ? 'staff' : 'unknown'),
                'actor' => $staff ? array_intersect_key($result['actor'], array_flip(['id', 'name', 'role'])) : null,
                'at' => $result['activated_at'] ?? null, 'pending' => false,
                'via' => $automatic ? (($result['source'] ?? null) === 'manual_collection_automation' ? 'Automatic activation' : 'System activation') : 'Subscription record'];
        }

        return $out;
    }
}
