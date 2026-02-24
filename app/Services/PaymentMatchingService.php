<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class PaymentMatchingService
{
    /**
     * Attempt to auto-match a payment to a client by phone + amount + platform.
     */
    public function matchPayment(Payment $payment): array
    {
        if ($payment->client_id) {
            return [
                'matched' => true,
                'confidence' => 'already_matched',
                'client_id' => $payment->client_id,
            ];
        }

        $phone = $this->normalizePhone($payment->phone);
        if (!$phone) {
            return ['matched' => false, 'confidence' => 'unmatched', 'reason' => 'No phone number'];
        }

        $clients = Client::where('platform_id', $payment->platform_id)
            ->where('phone_normalized', $phone)
            ->get();

        if ($clients->isEmpty()) {
            return ['matched' => false, 'confidence' => 'unmatched', 'reason' => 'No client with this phone'];
        }

        if ($clients->count() > 1) {
            return [
                'matched' => false,
                'confidence' => 'auto_low',
                'reason' => 'Multiple clients share this phone number',
                'candidates' => $clients->pluck('id')->toArray(),
            ];
        }

        $client = $clients->first();

        // Check if amount matches a product price
        $product = $this->matchProductByAmount($payment->amount, $payment->product_id);
        $amountMatches = $product !== null;

        $payment->update([
            'client_id' => $client->id,
            'match_confidence' => $amountMatches ? 'auto_high' : 'auto_low',
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
        $payments = $query->get();
        $results = ['matched' => 0, 'unmatched' => 0, 'low_confidence' => 0];

        foreach ($payments as $payment) {
            $result = $this->matchPayment($payment);
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

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone)
            return null;
        $phone = preg_replace('/[^\d+]/', '', $phone);
        $phone = ltrim($phone, '+');
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }
        return $phone;
    }

    private function matchProductByAmount(float $amount, ?int $productId): ?Product
    {
        if ($productId) {
            $product = Product::find($productId);
            if ($product)
                return $product;
        }

        return Product::where('monthly_price', $amount)
            ->orWhere('biweekly_price', $amount)
            ->orWhere('weekly_price', $amount)
            ->first();
    }

    /**
     * Create and activate a deal from a completed, matched payment.
     */
    public function createDealFromPayment(Payment $payment, int $actorId): Deal
    {
        if ($payment->status !== 'completed') {
            throw new \InvalidArgumentException('Payment must be completed to create a subscription.');
        }
        if (!$payment->client_id) {
            throw new \InvalidArgumentException('Payment must be matched to a client first.');
        }
        if ($payment->deal_id) {
            throw new \InvalidArgumentException('Payment is already linked to a subscription.');
        }

        $product = $payment->product_id ? Product::find($payment->product_id) : null;
        if (!$product) {
            $product = $this->matchProductByAmount((float) $payment->amount, null);
        }

        // Determine duration from product pricing tier match
        $duration = 'monthly';
        if ($product) {
            $amount = (float) $payment->amount;
            if ($product->weekly_price && abs($product->weekly_price - $amount) < 0.01) {
                $duration = 'weekly';
            } elseif ($product->biweekly_price && abs($product->biweekly_price - $amount) < 0.01) {
                $duration = 'biweekly';
            }
        }

        $durationDays = match ($duration) {
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30,
            default => 30,
        };

        $planType = 'basic';
        if ($product) {
            $nameLower = strtolower($product->name);
            if (str_contains($nameLower, 'vip'))
                $planType = 'vip';
            elseif (str_contains($nameLower, 'premium'))
                $planType = 'premium';
        }

        $deal = Deal::create([
            'platform_id' => (int) $payment->platform_id,
            'client_id' => (int) $payment->client_id,
            'payment_id' => (int) $payment->id,
            'product_id' => $product?->id,
            'plan_type' => $planType,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency ?? 'KES',
            'duration' => $duration,
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => now()->addDays($durationDays),
            'assigned_to' => $actorId,
        ]);

        $payment->update(['deal_id' => $deal->id]);

        return $deal;
    }
}
