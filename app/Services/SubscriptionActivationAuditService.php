<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\DB;

class SubscriptionActivationAuditService
{
    public const EVENT = 'subscription_activation_recorded';

    /** Call only after the subscription effect has succeeded or been verified. */
    public function record(Deal $deal, ?Payment $payment, ?int $actorId, ?string $method, string $source, ?string $reason = null, ?string $activatedAt = null): void
    {
        DB::transaction(function () use ($deal, $payment, $actorId, $method, $source, $reason, $activatedAt): void {
            // Serialize retries of the same effect. The first successful actor survives.
            Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $paymentId = $payment ? (int) $payment->id : null;
            $exists = TimelineEvent::query()
                ->where('entity_type', 'deal')->where('entity_id', $deal->id)
                ->where('event_type', self::EVENT)
                ->where('content->payment_id', $paymentId)
                ->exists();
            if ($exists) {
                return;
            }

            $actor = $actorId ? User::find($actorId) : null;
            TimelineEvent::create([
                'platform_id' => $deal->platform_id,
                'entity_type' => 'deal',
                'entity_id' => $deal->id,
                'event_type' => self::EVENT,
                'actor_id' => $actorId ?: null,
                'content' => [
                    'deal_id' => (int) $deal->id,
                    'payment_id' => $paymentId,
                    'actor' => $actorId ? ['id' => $actorId, 'name' => $actor?->name ?? "CRM user #{$actorId}", 'role' => $actor?->role] : null,
                    'execution' => $actorId ? 'staff' : 'automatic',
                    'activated_at' => $activatedAt ?? $deal->activated_at?->toIso8601String(),
                    'recorded_at' => now()->toIso8601String(),
                    'method' => $method,
                    'source' => $source,
                    'reason' => $reason,
                ],
                'created_at' => now(),
            ]);
        });
    }

    public function forPayment(Payment $payment): array
    {
        $payment->loadMissing(['deal', 'confirmedBy']);
        $deal = $payment->deal;
        $result = [
            'state' => $deal ? 'not_recorded' : 'not_activated',
            'actor' => null,
            'activated_at' => null,
            'recorded_at' => null,
            'method' => null,
            'source' => null,
            'reason' => null,
            'evidence' => null,
            'deal_id' => $deal?->id,
            'payment_confirmation' => $payment->confirmed_by ? [
                'actor' => ['id' => (int) $payment->confirmed_by, 'name' => $payment->confirmedBy?->name ?? 'Former CRM user'],
                'confirmed_at' => $payment->confirmed_at?->toIso8601String(),
            ] : null,
        ];
        if (! $deal || (int) $deal->platform_id !== (int) $payment->platform_id) {
            return $result;
        }

        $event = TimelineEvent::query()
            ->where('platform_id', $payment->platform_id)
            ->where('entity_type', 'deal')->where('entity_id', $deal->id)
            ->where('event_type', self::EVENT)
            ->where('content->payment_id', (int) $payment->id)
            ->oldest('id')->first();
        if ($event) {
            return array_replace($result, $event->content, [
                'state' => $event->content['execution'] === 'staff' ? 'staff' : 'automatic',
                'evidence' => 'Activation record',
            ]);
        }

        // A confirmation/assignment alone never proves who activated a subscription.
        // Require the exact payment link so later renewals cannot borrow an old actor.
        $legacy = TimelineEvent::query()->with('actor:id,name,role')
            ->where('platform_id', $payment->platform_id)
            ->where('entity_type', 'deal')->where('entity_id', $deal->id)
            ->where('event_type', 'deal_activated')
            ->where('content->payment_id', (int) $payment->id)
            ->oldest('id')->first();
        if ($legacy) {
            return array_replace($result, [
                'state' => $legacy->actor_id ? 'staff' : 'not_recorded',
                'actor' => $legacy->actor_id ? ['id' => (int) $legacy->actor_id, 'name' => $legacy->actor?->name ?? 'Former CRM user', 'role' => $legacy->actor?->role] : null,
                'activated_at' => $deal->activated_at?->toIso8601String(),
                'recorded_at' => $legacy->created_at?->toIso8601String(),
                'method' => data_get($legacy->content, 'payment_method'),
                'source' => data_get($legacy->content, 'source'),
                'evidence' => 'Historical activation timeline',
            ]);
        }

        $audit = AuditLog::query()->with('actor:id,name,role')
            ->where('platform_id', $payment->platform_id)
            ->where('entity_type', 'deal')
            ->whereIn('action', [CrmAuditAction::DEAL_ACTIVATE, CrmAuditAction::DEAL_RENEW])
            ->where(function ($query): void {
                $query->where('after_state->deal_status', 'active')->orWhere('after_state->new_status', 'active');
            })
            ->where('after_state->payment_id', (int) $payment->id)
            ->where(function ($query) use ($deal): void {
                $query->where('entity_id', $deal->id)->orWhere('after_state->new_deal_id', (int) $deal->id);
            })
            ->oldest('id')->first();
        if ($audit && $deal->activated_at) {
            return array_replace($result, [
                'state' => $audit->actor_id ? 'staff' : 'not_recorded',
                'actor' => $audit->actor_id ? ['id' => (int) $audit->actor_id, 'name' => $audit->actor?->name ?? 'Former CRM user', 'role' => $audit->actor?->role] : null,
                'activated_at' => $deal->activated_at->toIso8601String(),
                'recorded_at' => $audit->created_at?->toIso8601String(),
                'method' => data_get($audit->after_state, 'payment_method'),
                'source' => $audit->action,
                'reason' => $audit->reason,
                'evidence' => 'Historical activation audit',
            ]);
        }

        return array_replace($result, [
            'state' => $deal->activated_at ? 'not_recorded' : 'not_activated',
            'activated_at' => (int) $deal->payment_id === (int) $payment->id ? $deal->activated_at?->toIso8601String() : null,
        ]);
    }
}
