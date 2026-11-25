<?php

namespace App\Services\FlexibleAmounts;

use App\Billing\Support\BillingProviderTransactionRecorder;
use App\Billing\Support\BillingRoutingDecisionRecorder;
use App\Models\Client;
use App\Models\FlexibleCheckoutAttempt;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\BillingModeService;
use App\Services\ClientLifecycleMutationLock;
use App\Services\HostedCheckoutService;
use App\Services\PayLink\WriterGuard;
use App\Services\PaymentLinkService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class FlexibleSelfCheckoutService
{
    public function client(Request $request): Client
    {
        $market = $request->attributes->get('wallet_platform');
        abort_unless($market instanceof Platform, 401, 'Use your signed-in website account to continue.');
        $id = (int) $request->input('wp_user_id');
        abort_unless($id > 0 && $request->input('authenticated_customer') === true, 403, 'Sign in on the website to continue.');

        return Client::where('platform_id', $market->id)->where('wp_user_id', $id)->firstOrFail();
    }

    private function response(FlexibleCheckoutAttempt $attempt): array
    {
        $payment = $attempt->payment;

        return ['status' => true, 'payment_id' => $payment->id, 'transaction_uuid' => $payment->transaction_uuid,
            'action' => $attempt->action ?: ['type' => 'checking', 'message' => 'Your saved payment is still being checked. Please do not pay again.'],
            'payment_method' => data_get($payment->payment_data, 'payment_method', 'pawapay'),
            'activation_state' => data_get($payment->payment_data, 'provisioning_status') ?? data_get($payment->payment_data, 'canonical_state.provisioning_status'),
            'amount' => (string) $payment->amount, 'currency' => $payment->currency,
            'phone_masked' => \App\Services\PayLink\CheckoutService::mask($payment->phone),
            'live_until' => $payment->deal?->expires_at?->toIso8601String(),
            'payment_state' => $payment->status, 'quote' => data_get($payment->payment_data, 'flexible_quote')];
    }

    public function start(Request $request): array
    {
        $client = $this->client($request);
        $market = $client->platform;
        $input = $request->validate(['locale' => 'sometimes|in:en,fr,sw', 'product_id' => 'required|integer', 'amount' => 'required|numeric|min:0|max:999999999',
            'requested_days' => 'sometimes|integer|min:3|max:365', 'rules_version' => 'required|integer', 'catalogue_hash' => 'required|string|size:64',
            'confirmation_hash' => 'required|string|size:64', 'phone' => 'nullable|string|max:30', 'nudge_used' => 'nullable|string|max:30', 'rescue_payment_id' => 'nullable|integer', 'payment_method' => 'sometimes|in:pawapay,kopokopo,wallet']);
        $key = (string) $request->header('X-Idempotency-Key');
        abort_unless(strlen($key) >= 16 && strlen($key) <= 128, 422, 'Reload this page to continue.');
        $hash = hash('sha256', $key);
        $fp = hash('sha256', json_encode([$client->id, $input], JSON_THROW_ON_ERROR));
        $find = fn () => FlexibleCheckoutAttempt::where('platform_id', $market->id)->where('idempotency_hash', $hash)->first();
        if ($old = $find()) {
            abort_unless($old->client_id === $client->id && hash_equals($old->fingerprint, $fp), 409, 'This payment attempt has different details.');

            return $this->response($this->recoverWallet($old));
        }
        abort_unless(RateLimiter::attempt('flexible-start:'.$client->id, 5, fn () => true, 600), 429, 'Please wait before trying again.');
        try {
            $attempt = app(ClientLifecycleMutationLock::class)->run($client->id, function () use ($market, $client, $input, $hash, $fp, $find) {
                return DB::transaction(function () use ($market, $client, $input, $hash, $fp, $find) {
                    Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
                    if ($old = $find()) {
                        abort_unless(hash_equals($old->fingerprint, $fp), 409, 'This payment attempt has different details.');

                        return $old;
                    }
                    app(FlexibleAttemptGuard::class)->releaseTerminal($client->id);
                    app(WriterGuard::class)->assertNoExternalFinancialWork($client);
                    $nativeState = app(FlexibleQuoteService::class)->state($client);
                    $quote = app(FlexibleQuoteService::class)->quote($market, $client, $input, 'dashboard', $nativeState, true);
                    app(FlexibleQuoteService::class)->confirm($input, $quote);
                    app(FlexibleRescueService::class)->validateDashboard($client, $input, $quote);
                    $quote['nudge_used'] = $input['nudge_used'] ?? null;
                    $quote['rescue_payment_id'] = $input['rescue_payment_id'] ?? null;
                    $method = $input['payment_method'] ?? 'pawapay';
                    $route = app(FlexiblePaymentMethodsService::class)->resolve($market, $client, $nativeState, $method);
                    $context = $route['context'];
                    $resolved = $route['resolved'] ?? [];
                    $phone = $method === 'wallet' ? null : PhoneNormalizer::normalize($input['phone'] ?? $client->phone_normalized, $market->phone_prefix);
                    if ($method !== 'wallet' && (!$phone || !preg_match('/^[1-9][0-9]{7,14}$/D', $phone)
                        || !str_starts_with($phone, (string) $market->phone_prefix)
                        || ($method === 'kopokopo' && !preg_match('/^254[17][0-9]{8}$/D', $phone)))) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['phone' => 'Enter a valid mobile-money number for this country.']);
                    }
                    if ($method === 'wallet' && bccomp(app(FlexiblePaymentMethodsService::class)->balance($client, $quote['currency']), $quote['charge'], 2) < 0) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['payment_method' => 'Your wallet balance is too low. Choose another payment method or top up your wallet.']);
                    }
                    $uuid = (string) Str::uuid();
                    $attempt = FlexibleCheckoutAttempt::create(['operation_key' => $uuid, 'platform_id' => $market->id, 'client_id' => $client->id, 'idempotency_hash' => $hash, 'fingerprint' => $fp, 'blocking_client_id' => $client->id]);
                    $deal = app(\App\Services\DealPaymentService::class)->createPendingDealFromCatalog($client, $quote['product_id'], $quote['base_product_price_id'], null, null, null);
                    $deal->update(['amount' => $quote['charge'], 'duration' => 'manual', 'duration_days' => $quote['days']]);
                    $payment = Payment::create(['platform_id' => $market->id, 'client_id' => $client->id, 'user_id' => $client->wp_user_id, 'escort_post_id' => $client->wp_post_id, 'product_id' => $quote['product_id'],
                        'deal_id' => $deal->id, 'phone' => $phone, 'amount' => $quote['charge'], 'currency' => $quote['currency'], 'transaction_uuid' => $uuid, 'transaction_reference' => $uuid, 'reference_number' => 'FA-'.$uuid,
                        'status' => 'pending', 'purpose' => 'subscription', 'source' => $method === 'wallet' ? 'wallet' : 'self_checkout', 'provider_key' => $method, 'provider_environment' => $context['environment'], 'duration' => 'monthly',
                        'raw_payload' => ['method' => $method === 'wallet' ? 'wallet' : ($method === 'kopokopo' ? 'stk' : 'hosted_checkout'), 'billing_surface' => 'self_service_subscription'],
                        'payment_data' => ['test_mode' => $context['environment'] === 'sandbox', 'payment_method' => $method, 'pricing_mode' => 'flexible', 'flexible_quote' => $quote, 'flexible_operation_key' => $uuid, 'initiator' => 'companion', 'billing_surface' => 'self_service_subscription',
                            'product_price_id' => $quote['base_product_price_id'], 'base_product_price_id' => $quote['base_product_price_id'], 'duration_days' => $quote['days'], 'duration_key' => 'flexible',
                            'duration_label' => $quote['days'].' days', 'customer' => ['first_name' => $client->name, 'last_name' => '', 'email' => $client->email, 'phone' => $phone],
                            'self_service_incentive' => $quote['self_service_incentive'] ? ['original_amount' => $quote['original_charge'], 'percent' => $quote['self_service_incentive']['percent'], 'source' => 'self_service_incentive'] : null]]);
                    $deal->update(['payment_id' => $payment->id]);
                    $attempt->update(['payment_id' => $payment->id]);
                    $callback = app(BillingModeService::class)->buildAbsoluteUrl($market, $method === 'kopokopo' ? '/api/billing/mpesa/callback' : '/billing/complete', $method === 'kopokopo' ? [] : ['payment' => $uuid], $context['environment']);
                    $pricing = ['amount' => $quote['charge'], 'currency' => $quote['currency'], 'quoted_amount' => $quote['charge'], 'quoted_currency' => $quote['currency'], 'fx_override' => null];
                    if ($method === 'wallet') {
                        app(BillingRoutingDecisionRecorder::class)->recordWalletSubscription($payment, $pricing,
                            ['environment' => $context['environment'], 'origin' => 'flexible_dashboard', 'idempotency_key' => app(FlexiblePaymentMethodsService::class)->debitKey($attempt)]);
                        $debit = app(\App\Services\WalletService::class)->debit($client, $quote['currency'], (float) $quote['charge'], [
                            'payment' => $payment, 'reference_type' => 'wallet_subscription', 'reference_id' => $payment->id,
                            'idempotency_key' => app(FlexiblePaymentMethodsService::class)->debitKey($attempt),
                            'description' => 'Wallet subscription · '.$quote['plan'].' · '.$quote['days'].' days',
                            'metadata' => ['product_id' => $quote['product_id'], 'duration_days' => $quote['days'], 'flexible_operation_key' => $uuid],
                        ]);
                        $payment->refresh();
                        $attempt->unsetRelation('payment');
                        app(FlexiblePaymentMethodsService::class)->assertDebit($attempt);
                        $payment->update(['status' => 'completed', 'completed_at' => now()]);
                        $attempt->update(['state' => 'wallet_debited', 'action' => ['type' => 'checking', 'message' => 'Wallet payment recorded. Your activation is being checked.']]);
                    } else {
                        app(BillingRoutingDecisionRecorder::class)->recordSelfCheckout($payment, $context + ['provider_key' => $method], $resolved, $pricing, $callback);
                    }

                    return $attempt->fresh('payment');
                });
            });
        } catch (\App\Exceptions\ClientLifecycleMutationException $exception) {
            // Another identical request may have committed while this request waited
            // for the native writer lock. Recover that exact owned attempt only.
            $attempt = $find();
            if (! $attempt || $attempt->client_id !== $client->id || ! hash_equals($attempt->fingerprint, $fp)) {
                throw $exception;
            }
        }
        if ($attempt->payment->provider_key === 'wallet') {
            return $this->response($this->recoverWallet($attempt));
        }
        // A committed claim precedes the only network initialization. Retrying never re-dispatches.
        if (! FlexibleCheckoutAttempt::whereKey($attempt->id)->whereNull('dispatch_started_at')->update(['dispatch_started_at' => now(), 'state' => 'dispatching'])) {
            return $this->response($attempt->fresh());
        }
        $payment = $attempt->payment;
        try {
            $decision = $payment->latestPinnedRoutingDecision();
            abort_unless($decision && $decision->provider_profile_id, 409, 'Pinned checkout connection is unavailable.');
            $profile = \App\Models\BillingProviderProfile::findOrFail($decision->provider_profile_id);
            $context = app(BillingModeService::class)->profileBackedProviderContext($market, $profile, $decision->chosen_binding_id, false, $payment->provider_environment, $payment->provider_key === 'kopokopo' ? 'subscription_push' : 'self_checkout');
            $callback = app(BillingModeService::class)->buildAbsoluteUrl($market, $payment->provider_key === 'kopokopo' ? '/api/billing/mpesa/callback' : '/billing/complete', $payment->provider_key === 'kopokopo' ? [] : ['payment' => $payment->transaction_uuid], $payment->provider_environment);
            $action = app(FlexibleAttemptGuard::class)->owned($payment, fn () => $payment->provider_key === 'kopokopo'
                ? app(\App\Services\Routing\ProviderRoutingDispatcher::class)->dispatch($payment, $context + ['provider_key' => 'kopokopo'], ['phone' => $payment->phone])
                : app(HostedCheckoutService::class)->initializePawaPay($payment, $context, ['callback_url' => $callback, 'description' => 'Subscription payment', 'prefill_phone' => false]));
            $stored = $action;
            unset($stored['provider_payload']);
            $payment->update(['transaction_reference' => $action['provider_reference'] ?? $payment->transaction_reference, 'payment_data' => array_merge($payment->payment_data ?? [], ['resume' => $stored, 'checkout_url' => $action['url'] ?? null])]);
            app(BillingProviderTransactionRecorder::class)->recordInitiation($payment, $context + ['provider_key' => $payment->provider_key], $action, ['reason_code' => 'flexible_self_checkout']);
            $attempt->update(['action' => $stored, 'state' => 'pending']);
        } catch (\Throwable) {
            $attempt->update(['state' => 'uncertain']);
        }

        return $this->response($attempt->fresh());
    }

    private function recoverWallet(FlexibleCheckoutAttempt $attempt): FlexibleCheckoutAttempt
    {
        return $attempt->payment->provider_key === 'wallet'
            ? app(FlexiblePaymentMethodsService::class)->completeWallet($attempt) : $attempt;
    }

    public function status(Request $request): array
    {
        $client = $this->client($request);
        $payment = Payment::where('platform_id', $client->platform_id)->where('client_id', $client->id)->where('transaction_uuid', $request->input('transaction_uuid'))->firstOrFail();
        $state = data_get($payment->payment_data, 'provisioning_status') ?? data_get($payment->payment_data, 'canonical_state.provisioning_status');

        return ['payment_state' => $payment->status, 'activation_state' => $state,
            'payment_method' => data_get($payment->payment_data, 'payment_method', 'pawapay'),
            'amount' => (string) $payment->amount, 'currency' => $payment->currency,
            'phone_masked' => \App\Services\PayLink\CheckoutService::mask($payment->phone), 'quote' => data_get($payment->payment_data, 'flexible_quote'),
            'live_until' => $state === 'completed' ? $payment->deal?->expires_at?->toIso8601String() : null,
            'rescue' => app(FlexibleRescueService::class)->forPayment($payment, 'dashboard')];
    }
}
