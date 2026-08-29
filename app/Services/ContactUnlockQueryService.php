<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\VisitorContactUnlock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ContactUnlockQueryService
{
    public function filtered(array $filters, ?array $platformIds): Builder
    {
        $query = VisitorContactUnlock::query()
            ->with([
                'platform:id,name,currency_code',
                'client:id,name,wp_post_id,wp_profile_permalink',
                'customerClaims:id,customer_account_id,visitor_contact_unlock_id,wp_post_id,status,claimed_at,expires_at,last_revealed_at,source',
                'customerClaims.customerAccount:id,display_name,email',
                'customerClaims.reachabilityFeedback:id,customer_unlock_claim_id,outcome,status,submitted_at,review_reason',
                'payment:id,status,amount,currency,reference_number,transaction_reference,failure_reason,payment_data,provider_key,provider_environment,completed_at,created_at',
            ]);

        if (is_array($platformIds)) {
            if ($platformIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('visitor_contact_unlocks.platform_id', $platformIds);
            }
        }

        if (! empty($filters['platform_id'])) {
            $query->where('visitor_contact_unlocks.platform_id', (int) $filters['platform_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('visitor_contact_unlocks.status', (string) $filters['status']);
        }

        if (! empty($filters['scope'])) {
            $query->where('visitor_contact_unlocks.scope', (string) $filters['scope']);
        }

        if (! empty($filters['payment_status'])) {
            $query->whereHas('payment', fn ($paymentQuery) => $paymentQuery->where('status', (string) $filters['payment_status']));
        }

        if (! empty($filters['from'])) {
            $query->where('visitor_contact_unlocks.created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('visitor_contact_unlocks.created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(function ($searchQuery) use ($like, $search): void {
                if (ctype_digit($search)) {
                    $searchQuery->orWhere('visitor_contact_unlocks.id', (int) $search)
                        ->orWhere('visitor_contact_unlocks.wp_post_id', (int) $search);
                }

                $searchQuery
                    ->orWhere('visitor_contact_unlocks.visitor_phone_masked', 'like', $like)
                    ->orWhere('visitor_contact_unlocks.visitor_email_masked', 'like', $like)
                    ->orWhereHas('client', function ($clientQuery) use ($like): void {
                        $clientQuery
                            ->where('name', 'like', $like)
                            ->orWhere('wp_profile_permalink', 'like', $like);
                    })
                    ->orWhereHas('payment', function ($paymentQuery) use ($like): void {
                        $paymentQuery
                            ->where('reference_number', 'like', $like)
                            ->orWhere('transaction_reference', 'like', $like)
                            ->orWhere('provider_key', 'like', $like);
                    });
            });
        }

        $direction = (string) ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        match ((string) ($filters['sort'] ?? 'id')) {
            'amount' => $query->orderBy(Payment::query()
                ->select('amount')
                ->whereColumn('payments.id', 'visitor_contact_unlocks.payment_id')
                ->limit(1), $direction),
            'payment_status' => $query->orderBy(Payment::query()
                ->select('status')
                ->whereColumn('payments.id', 'visitor_contact_unlocks.payment_id')
                ->limit(1), $direction),
            'profile' => $query->orderBy(Client::query()
                ->select('name')
                ->whereColumn('clients.id', 'visitor_contact_unlocks.client_id')
                ->limit(1), $direction),
            'market' => $query->orderBy(Platform::query()
                ->select('name')
                ->whereColumn('platforms.id', 'visitor_contact_unlocks.platform_id')
                ->limit(1), $direction),
            'created_at' => $query->orderBy('visitor_contact_unlocks.created_at', $direction),
            'status' => $query->orderBy('visitor_contact_unlocks.status', $direction),
            'scope' => $query->orderBy('visitor_contact_unlocks.scope', $direction),
            'visitor' => $query->orderBy('visitor_contact_unlocks.visitor_phone_masked', $direction),
            default => $query->orderBy('visitor_contact_unlocks.id', $direction),
        };

        return $query->orderBy('visitor_contact_unlocks.id', 'desc');
    }
}
