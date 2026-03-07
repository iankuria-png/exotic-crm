<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PaymentMatchingService
{
    private PaymentReconciliationConfidenceService $confidenceService;
    private SubscriptionProvisioningService $subscriptionProvisioningService;

    public function __construct(
        ?PaymentReconciliationConfidenceService $confidenceService = null,
        ?SubscriptionProvisioningService $subscriptionProvisioningService = null
    ) {
        $this->confidenceService = $confidenceService ?? new PaymentReconciliationConfidenceService();
        $this->subscriptionProvisioningService = $subscriptionProvisioningService ?? new SubscriptionProvisioningService();
    }

    /**
     * Attempt to auto-match a payment to a client by phone + amount + platform.
     */
    public function matchPayment(Payment $payment, ?string $prefix = null): array
    {
        if ($payment->client_id) {
            return [
                'matched' => true,
                'confidence' => 'already_matched',
                'client_id' => $payment->client_id,
            ];
        }

        if ($prefix === null) {
            $payment->loadMissing('platform');
            $prefix = (string) ($payment->platform?->phone_prefix ?: '254');
        }

        $phone = PhoneNormalizer::normalize($payment->phone, $prefix);
        if (!$phone) {
            $payment->update([
                'reconciliation_confidence' => 'low',
                'reconciliation_state' => 'manual_review',
            ]);

            return ['matched' => false, 'confidence' => 'unmatched', 'reason' => 'No phone number'];
        }

        $clients = Client::where('platform_id', $payment->platform_id)
            ->where('phone_normalized', $phone)
            ->get();

        if ($clients->isEmpty()) {
            $payment->update([
                'reconciliation_confidence' => 'low',
                'reconciliation_state' => 'manual_review',
            ]);

            return ['matched' => false, 'confidence' => 'unmatched', 'reason' => 'No client with this phone'];
        }

        if ($clients->count() > 1) {
            $payment->update([
                'reconciliation_confidence' => 'medium',
                'reconciliation_state' => 'manual_review',
            ]);

            return [
                'matched' => false,
                'confidence' => 'auto_low',
                'reason' => 'Multiple clients share this phone number',
                'candidates' => $clients->pluck('id')->toArray(),
            ];
        }

        $client = $clients->first();

        // Check if amount matches a product price
        $product = $this->matchProductByAmount(
            (float) $payment->amount,
            $payment->product_id ? (int) $payment->product_id : null,
            $payment->platform_id ? (int) $payment->platform_id : null,
            $payment->currency
        );
        $amountMatches = $product !== null;

        $payment->update([
            'client_id' => $client->id,
            'match_confidence' => $amountMatches ? 'auto_high' : 'auto_low',
            'reconciliation_confidence' => $amountMatches ? 'high' : 'medium',
            'reconciliation_state' => $amountMatches ? 'resolved' : 'open',
        ]);

        return [
            'matched' => true,
            'confidence' => $amountMatches ? 'auto_high' : 'auto_low',
            'client_id' => $client->id,
            'product_id' => $product?->id,
        ];
    }

    /**
     * Manually confirm a payment match to a client.
     */
    public function confirmMatch(Payment $payment, int $clientId, int $confirmedBy): Payment
    {
        $payment->update([
            'client_id' => $clientId,
            'match_confidence' => 'manual',
            'confirmed_by' => $confirmedBy,
            'confirmed_at' => now(),
            'reconciliation_confidence' => $this->confidenceService->fromMatchConfidence('manual'),
            'reconciliation_state' => 'resolved',
        ]);

        $payment = $payment->fresh();

        // Automatically create deal if payment is completed
        if ($payment->status === 'completed' && !$payment->deal_id) {
            try {
                $this->createDealFromPayment($payment, $confirmedBy);
            } catch (\Exception $e) {
                // Log but don't block match confirmation
                \Illuminate\Support\Facades\Log::error('Auto-deal creation failed after manual match', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $payment->fresh();
    }

    /**
     * Run auto-matching on all unmatched completed payments.
     */
    public function batchMatch(?int $platformId = null): array
    {
        $query = Payment::whereNull('client_id')
            ->where('status', 'completed');

        if ($platformId) {
            $query->where('platform_id', $platformId);
        }

        return $this->runBatchMatch($query);
    }

    public function batchMatchForPlatforms(array $platformIds): array
    {
        if (empty($platformIds)) {
            return ['matched' => 0, 'unmatched' => 0, 'low_confidence' => 0];
        }

        $query = Payment::query()
            ->whereNull('client_id')
            ->where('status', 'completed')
            ->whereIn('platform_id', array_values(array_unique(array_map('intval', $platformIds))));

        return $this->runBatchMatch($query);
    }

    private function runBatchMatch(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $payments = $query->with('platform:id,phone_prefix')->get();
        $results = ['matched' => 0, 'unmatched' => 0, 'low_confidence' => 0];

        foreach ($payments as $payment) {
            $prefix = (string) ($payment->platform?->phone_prefix ?: '254');
            $result = $this->matchPayment($payment, $prefix);
            if ($result['matched'] && $result['confidence'] === 'auto_high') {
                $results['matched']++;
            } elseif ($result['matched'] && $result['confidence'] === 'auto_low') {
                $results['low_confidence']++;
            } else {
                $results['unmatched']++;
            }
        }

        return $results;
    }

    /**
     * Dry-run: evaluate a match without writing to the database.
     */
    public function dryRunMatchPayment(Payment $payment, ?string $prefix = null): array
    {
        if ($payment->client_id) {
            return [
                'matched' => true,
                'confidence' => 'already_matched',
                'payment_id' => $payment->id,
                'client_id' => $payment->client_id,
            ];
        }

        if ($prefix === null) {
            $payment->loadMissing('platform');
            $prefix = (string) ($payment->platform?->phone_prefix ?: '254');
        }

        $phone = PhoneNormalizer::normalize($payment->phone, $prefix);
        if (!$phone) {
            return ['matched' => false, 'confidence' => 'unmatched', 'reason' => 'No phone number', 'payment_id' => $payment->id];
        }

        $clients = Client::where('platform_id', $payment->platform_id)
            ->where('phone_normalized', $phone)
            ->get();

        if ($clients->isEmpty()) {
            return ['matched' => false, 'confidence' => 'unmatched', 'reason' => 'No client with this phone', 'payment_id' => $payment->id];
        }

        if ($clients->count() > 1) {
            return [
                'matched' => false,
                'confidence' => 'auto_low',
                'reason' => 'Multiple clients share this phone number',
                'payment_id' => $payment->id,
                'payment_phone' => $payment->phone,
                'payment_amount' => $payment->amount,
                'payment_currency' => $payment->currency,
            ];
        }

        $client = $clients->first();
        $product = $this->matchProductByAmount(
            (float) $payment->amount,
            $payment->product_id ? (int) $payment->product_id : null,
            $payment->platform_id ? (int) $payment->platform_id : null,
            $payment->currency
        );

        return [
            'matched' => true,
            'confidence' => $product ? 'auto_high' : 'auto_low',
            'payment_id' => $payment->id,
            'payment_phone' => $payment->phone,
            'payment_amount' => $payment->amount,
            'payment_currency' => $payment->currency,
            'client_id' => $client->id,
            'client_name' => $client->name,
            'product_id' => $product?->id,
            'product_name' => $product?->name,
        ];
    }

    public function dryRunBatchMatch(?int $platformId = null): array
    {
        $query = Payment::whereNull('client_id')
            ->where('status', 'completed');

        if ($platformId) {
            $query->where('platform_id', $platformId);
        }

        return $this->runDryRunBatchMatch($query);
    }

    public function dryRunBatchMatchForPlatforms(array $platformIds): array
    {
        if (empty($platformIds)) {
            return ['matched' => 0, 'unmatched' => 0, 'low_confidence' => 0, 'proposals' => []];
        }

        $query = Payment::query()
            ->whereNull('client_id')
            ->where('status', 'completed')
            ->whereIn('platform_id', array_values(array_unique(array_map('intval', $platformIds))));

        return $this->runDryRunBatchMatch($query);
    }

    private function runDryRunBatchMatch(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $payments = $query->with('platform:id,phone_prefix')->get();
        $results = ['matched' => 0, 'unmatched' => 0, 'low_confidence' => 0, 'proposals' => []];

        foreach ($payments as $payment) {
            $prefix = (string) ($payment->platform?->phone_prefix ?: '254');
            $result = $this->dryRunMatchPayment($payment, $prefix);
            if ($result['matched'] && $result['confidence'] === 'auto_high') {
                $results['matched']++;
                $results['proposals'][] = $result;
            } elseif ($result['matched'] && $result['confidence'] === 'auto_low') {
                $results['low_confidence']++;
                $results['proposals'][] = $result;
            } else {
                $results['unmatched']++;
            }
        }

        $results['proposals'] = array_slice($results['proposals'], 0, 100);

        return $results;
    }

    private function matchProductByAmount(
        float $amount,
        ?int $productId,
        ?int $platformId = null,
        ?string $currency = null
    ): ?Product
    {
        if ($productId) {
            $product = Product::find($productId);
            if ($product && ($platformId === null || (int) $product->platform_id === $platformId)) {
                return $product;
            }
        }

        return Product::query()
            ->where('is_active', true)
            ->when(
                !empty($platformId),
                fn(Builder $builder) => $builder->where('platform_id', (int) $platformId)
            )
            ->when(
                !empty($currency),
                fn(Builder $builder) => $builder->whereRaw('UPPER(currency) = ?', [strtoupper((string) $currency)])
            )
            ->where(function (Builder $builder) use ($amount): void {
                $builder
                    ->where('monthly_price', $amount)
                    ->orWhere('biweekly_price', $amount)
                    ->orWhere('weekly_price', $amount);
            })
            ->first();
    }

    /**
     * Create and activate a deal from a completed, matched payment.
     */
    public function createDealFromPayment(Payment $payment, int $actorId): Deal
    {
        return DB::transaction(fn () => $this->subscriptionProvisioningService->provisionCompletedPayment($payment, [
            'actor_id' => $actorId,
            'confirmed_by' => $actorId,
            'confirmed_at' => $payment->confirmed_at ?? now(),
            'match_confidence' => $payment->match_confidence ?: 'manual',
            'reconciliation_confidence' => $payment->reconciliation_confidence ?: 'high',
            'reconciliation_state' => 'resolved',
            'emit_payment_received_timeline' => true,
            'emit_profile_activated_timeline' => false,
            'emit_deal_activated_timeline' => true,
        ]));
    }

    /**
     * Estimate product candidates by amount with closest-match and multi-candidate support.
     * Returns all matches within 30% tolerance, sorted by distance.
     */
    public function estimateProductByAmount(
        float $amount,
        ?int $platformId = null,
        ?string $currency = null
    ): array {
        $products = Product::query()
            ->where('is_active', true)
            ->when($platformId, fn(Builder $q) => $q->where('platform_id', $platformId))
            ->when(
                !empty($currency),
                fn(Builder $q) => $q->whereRaw('UPPER(currency) = ?', [strtoupper((string) $currency)])
            )
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $productIds = $products->pluck('id')->all();
        $dynamicPrices = ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->get();

        $candidates = [];
        $durationMap = [
            'weekly_price' => ['key' => 'weekly', 'days' => 7],
            'biweekly_price' => ['key' => 'biweekly', 'days' => 14],
            'monthly_price' => ['key' => 'monthly', 'days' => 30],
        ];

        foreach ($products as $product) {
            foreach ($durationMap as $column => $info) {
                $price = (float) $product->$column;
                if ($price <= 0) {
                    continue;
                }

                $distance = abs($amount - $price);
                $candidates[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $price,
                    'duration_key' => $info['key'],
                    'duration_days' => $info['days'],
                    'distance' => $distance,
                    'exact_match' => $distance < 0.01,
                    'confidence' => $distance < 0.01
                        ? 'exact'
                        : ($distance <= $amount * 0.10 ? 'high' : 'low'),
                    'plan_type' => $this->resolvePlanTypeFromProduct($product),
                ];
            }

            foreach ($dynamicPrices->where('product_id', $product->id) as $dp) {
                $price = (float) $dp->price;
                $distance = abs($amount - $price);
                $candidates[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $price,
                    'duration_key' => $dp->duration_key,
                    'duration_days' => (int) $dp->duration_days,
                    'distance' => $distance,
                    'exact_match' => $distance < 0.01,
                    'confidence' => $distance < 0.01
                        ? 'exact'
                        : ($distance <= $amount * 0.10 ? 'high' : 'low'),
                    'plan_type' => $this->resolvePlanTypeFromProduct($product),
                ];
            }
        }

        if (empty($candidates)) {
            return [];
        }

        usort($candidates, fn($a, $b) => $a['distance'] <=> $b['distance']);

        $maxDistance = $amount * 0.30;
        $filtered = array_filter($candidates, fn($c) => $c['distance'] <= $maxDistance);

        $seen = [];
        $deduped = [];
        foreach ($filtered as $c) {
            $key = $c['product_id'] . ':' . number_format($c['price'], 2, '.', '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $c;
        }

        return array_values($deduped);
    }

    private function resolvePlanTypeFromProduct(?Product $product): string
    {
        if (!$product) {
            return 'basic';
        }

        $nameLower = strtolower((string) $product->name);
        if (str_contains($nameLower, 'vip')) {
            return 'vip';
        }
        if (str_contains($nameLower, 'premium')) {
            return 'premium';
        }

        return 'basic';
    }
}
