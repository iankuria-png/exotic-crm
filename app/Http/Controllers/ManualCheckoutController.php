<?php

namespace App\Http\Controllers;

use App\Models\BillingManualPaymentMethod;
use App\Models\BillingProxySession;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public, hosted manual-checkout page for markets without a tokenized PSP. A
 * lifecycle SMS links here (signed URL); the client sees their subscription
 * amount and the market's exact paybill/bank/till instructions, then uploads
 * proof — which enters the existing manual-payment review queue.
 */
class ManualCheckoutController extends Controller
{
    public function show(Request $request, Payment $payment)
    {
        $payment->loadMissing(['client.platform', 'product', 'platform']);
        $platform = $payment->platform ?: $payment->client?->platform;

        if (!$platform) {
            abort(404);
        }

        $methods = BillingManualPaymentMethod::query()
            ->where('market_id', (int) $platform->id)
            ->where('enabled', true)
            ->orderBy('method_key')
            ->get()
            ->map(fn (BillingManualPaymentMethod $method) => [
                'key' => (string) $method->method_key,
                'label' => (string) ($method->display_name ?: ucfirst($method->method_key)),
                'intro' => (string) ($method->instruction_intro ?: ''),
                'footer' => (string) ($method->instruction_footer ?: ''),
                'details' => is_array($method->details_json) ? $method->details_json : [],
                'sender_name_required' => (bool) $method->sender_name_required,
                'transaction_id_required' => (bool) $method->transaction_id_required,
            ])
            ->values();

        if ($methods->isEmpty()) {
            abort(404);
        }

        // Record the open once (powers the "opened" signal in lifecycle analytics).
        $this->recordOpen($payment, $platform);

        $client = $payment->client;
        $currency = (string) ($payment->currency ?: ($platform->currency_code ?: 'KES'));
        $nameParts = preg_split('/\s+/', trim((string) ($client?->name ?: 'Client')), 2) ?: ['Client'];

        return view('manual-checkout', [
            'payment' => $payment,
            'platform' => $platform,
            'client' => $client,
            'methods' => $methods,
            'currency' => $currency,
            'amountDisplay' => $currency . ' ' . number_format((float) $payment->amount),
            'productName' => (string) ($payment->product?->display_name ?: $payment->product?->name ?: 'your subscription'),
            'reference' => (string) ($payment->transaction_reference ?: $payment->reference_number),
            'submitContext' => [
                'product_id' => (int) $payment->product_id,
                'product_price_id' => (int) ($payment->deal?->product_price_id ?? 0) ?: null,
                'platform_id' => (int) $platform->id,
                'user_id' => (int) ($client?->wp_user_id ?? 0),
                'first_name' => $nameParts[0] ?: 'Client',
                'last_name' => $nameParts[1] ?? '.',
                'phone' => (string) ($client?->phone_normalized ?: $payment->phone),
                'email' => (string) ($client?->email ?? ''),
                'duration' => (string) ($payment->duration ?: ''),
                'currency' => $currency,
            ],
        ]);
    }

    private function recordOpen(Payment $payment, $platform): void
    {
        try {
            $session = BillingProxySession::query()
                ->where('payment_id', (int) $payment->id)
                ->where('provider_type_key', 'manual_checkout')
                ->first();

            if ($session) {
                $session->forceFill([
                    'opened_at' => $session->opened_at ?: now(),
                    'open_count' => (int) $session->open_count + 1,
                ])->save();

                return;
            }

            BillingProxySession::query()->create([
                'payment_id' => (int) $payment->id,
                'provider_type_key' => 'manual_checkout',
                'environment' => (string) ($payment->provider_environment ?: 'production'),
                'token_hash' => 'manual-' . $payment->id . '-' . substr(sha1((string) $payment->id . '|manual'), 0, 24),
                'token_expires_at' => now()->addDays(30),
                'opened_at' => now(),
                'open_count' => 1,
                'state' => 'opened',
            ]);
        } catch (\Throwable) {
            // Open tracking must never break the checkout page.
        }
    }
}
