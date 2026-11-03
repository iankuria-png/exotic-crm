<?php

namespace App\Services\Tours;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\TourMarketSetting;
use App\Models\TourOrder;
use App\Models\TourOrderStop;
use App\Models\TourPaymentAttempt;
use App\Services\BillingGatewayService;
use App\Services\BillingModeService;
use App\Services\Monetization\ListingEligibility;
use App\Services\ProviderStatusQueryOrchestrator;
use App\Services\WpSyncService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CheckoutService
{
    public function owner(Platform $home, array $input): Client
    {
        Validator::make($input, ['wp_post_id' => 'required|integer|min:1', 'wp_user_id' => 'required|integer|min:1'])->validate();

        return app(ListingEligibility::class)->assertOwner($home->id, (int) $input['wp_post_id'], (int) $input['wp_user_id']);
    }

    public function configuration(Platform $home): TourMarketSetting
    {
        $row = TourMarketSetting::where('platform_id', $home->id)->firstOrFail();
        abort_unless(! empty($row->settings['enabled']), 403, 'Tours is unavailable.');

        return $row;
    }

    public function methods(Platform $home, string $environment): array
    {
        $methods = [];
        if ($environment === 'production') {
            try {
                $context = app(BillingModeService::class)->assertWalletAvailable($home);
                if (($context['environment'] ?? '') === 'production') {
                    $methods[] = 'wallet';
                }
            } catch (\Throwable) {
            }
        }
        foreach (['kopokopo', 'pawapay'] as $provider) {
            try {
                app(BillingModeService::class)->providerContext($home, $provider, true, $environment, 'tour_pass');
                $methods[] = $provider;
            } catch (\Throwable) {
            }
        }

        return $methods;
    }

    public function catalog(Platform $home, array $input): array
    {
        $this->owner($home, $input);
        $config = $this->configuration($home);
        $settings = $config->settings;
        $environment = $settings['environment'];
        $destinations = [];
        $checked = [];
        foreach ($settings['passes'] as $pass) {
            if (! $pass['enabled'] || $pass['currency'] !== $home->primaryCurrency()) {
                continue;
            }
            $ids = $pass['destination_platform_ids'];
            if (! $ids) {
                continue;
            }
            $availableIds = [];
            foreach ($ids as $id) {
                if (! array_key_exists($id, $checked)) {
                    $destination = Platform::find($id);
                    $checked[$id] = $destination ? app(CatalogueService::class)->read($home, $destination, $environment) : null;
                }
                if ($checked[$id] !== null) {
                    $availableIds[] = $id;
                }
            }
            // A regional pass promises every member; country passes can omit an offline country.
            if (! $availableIds || ($pass['region'] !== 'africa' && count($availableIds) !== count($ids))) {
                continue;
            }
            $offers = [['code' => $pass['code'], 'name' => $pass['name'], 'days' => $pass['days'], 'amount_minor' => $pass['amount_minor'], 'currency' => $pass['currency']]];
            if ($pass['region'] === 'africa') {
                foreach ($availableIds as $id) {
                    $destination = Platform::findOrFail($id);
                    $key = 'market_'.$id;
                    if (! isset($destinations[$key])) {
                        $locations = $checked[$id];
                        $destinations[$key] = ['key' => $key, 'name' => $destination->name, 'region' => 'africa', 'platform_id' => $id, 'cities' => $locations['cities'] ?? $locations['locations'] ?? [], 'passes' => []];
                    }$destinations[$key]['passes'] = array_merge($destinations[$key]['passes'], $offers);
                }
            } else {
                $key = 'region_'.$pass['region'];
                $names = ['gulf' => 'Dubai', 'europe' => 'Europe', 'hub' => 'Across Africa'];
                $destinations[$key] ??= ['key' => $key, 'name' => $names[$pass['region']] ?? $pass['name'], 'region' => $pass['region'], 'platform_id' => null, 'cities' => [], 'passes' => []];
                $destinations[$key]['passes'] = array_merge($destinations[$key]['passes'], $offers);
            }
        }

        return ['destinations' => array_values($destinations), 'methods' => $this->methods($home, $environment), 'environment' => $environment, 'revision' => $config->revision];
    }

    public function quote(Platform $home, array $input): array
    {
        $client = $this->owner($home, $input);
        $config = $this->configuration($home);
        Validator::make($input, ['trip_id' => 'required|integer|min:1', 'generation' => 'required|string|max:80'])->validate();
        $snapshot = (new WpSyncService($home))->getTourTrip((int) $input['trip_id']);
        abort_unless(($snapshot['contract_version'] ?? 0) === 1 && (int) ($snapshot['profile_id'] ?? 0) === (int) $client->wp_post_id && (int) ($snapshot['wp_user_id'] ?? 0) === (int) $input['wp_user_id'] && (string) ($snapshot['generation'] ?? '') === (string) $input['generation'] && ($snapshot['environment'] ?? '') === $config->settings['environment'] && empty($snapshot['restricted']) && ($snapshot['status'] ?? '') !== 'cancelled', 409, 'The trip changed or cannot be booked. Refresh it.');
        $existing = TourOrder::where('platform_id', $home->id)->where('trip_id', $input['trip_id'])->where('generation', $input['generation'])->first();
        if (! $existing || ! $existing->winning_attempt_id) {
            abort_unless(($snapshot['purchase_eligible'] ?? false) === true, 409, 'Activate your home profile before buying a tour.');
            abort_unless(app(ListingEligibility::class)->facts($client)['listing_active'], 409, 'Activate your home profile before buying a tour.');
        }
        abort_unless(count($snapshot['media'] ?? []) > 0 && count($snapshot['media']) <= 30, 422, 'Choose approved travelling media.');
        foreach ($snapshot['media'] as $media) {
            abort_unless(preg_match('/^[a-f0-9]{64}$/D', $media['sha256'] ?? '') && in_array($media['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'video/mp4'], true), 409, 'Travelling media is not ready.');
        }
        Money::factor($home->primaryCurrency());
        $quote = app(PricingService::class)->price($home, $config->settings, $snapshot);
        foreach (array_unique(array_column($quote['stops'], 'destination_platform_id')) as $id) {
            app(CapabilityService::class)->requireDestination($home, Platform::findOrFail($id), $config->settings['environment'], $snapshot['media']);
        }
        $token = Str::random(64);
        $order = DB::transaction(function () use ($home, $client, $input, $snapshot, $quote, $config, $token) {
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $old = TourOrder::where('platform_id', $home->id)->where('trip_id', $input['trip_id'])->where('generation', $input['generation'])->lockForUpdate()->first();
            if ($old) {
                abort_unless($old->config_revision === $config->revision && in_array($old->state, ['quoted', 'awaiting_payment'], true) && ! $old->winning_attempt_id && ! $old->attempts()->whereNotIn('state', ['failed'])->exists(), 409, 'This trip already has a payment or its pricing changed. Check its status.');
                $old->update(['quote_token_hash' => hash('sha256', $token), 'quote_expires_at' => now()->addMinutes(15)]);

                return $old;
            }
            $order = TourOrder::create(['public_id' => Str::uuid(), 'platform_id' => $home->id, 'client_id' => $client->id, 'wp_user_id' => $input['wp_user_id'], 'trip_id' => $input['trip_id'], 'generation' => $input['generation'], 'environment' => $config->settings['environment'], 'state' => 'quoted', 'snapshot' => $snapshot, 'quote' => $quote, 'quote_token_hash' => hash('sha256', $token), 'quote_expires_at' => now()->addMinutes(15), 'config_revision' => $config->revision, 'amount_minor' => $quote['amount_minor'], 'currency' => $quote['currency'], 'pass_expires_at' => date('Y-m-d H:i:s', $quote['pass_expires_at'])]);
            foreach ($quote['stops'] as $stop) {
                $key = hash('sha256', $order->public_id.':'.$stop['source_stop_key'].':'.$stop['destination_platform_id']);
                TourOrderStop::create(['tour_order_id' => $order->id, 'destination_platform_id' => $stop['destination_platform_id'], 'source_stop_key' => $stop['source_stop_key'], 'tour_key' => $key, 'starts_at' => date('Y-m-d H:i:s', $stop['start']), 'ends_at' => date('Y-m-d H:i:s', $stop['end']), 'allocation_minor' => $stop['allocation_minor'], 'payload' => $stop]);
            }

            return $order;
        });

        (new WpSyncService($home))->setTourPaymentState($order->trip_id, ['environment' => $order->environment, 'generation' => $order->generation, 'order_reference' => (string) $order->public_id, 'state' => 'pending', 'pass_expires_at' => $order->pass_expires_at->timestamp, 'remote_stops' => []]);

        return $this->present($order) + ['quote_token' => $token, 'total_minor' => $order->amount_minor, 'methods' => $this->methods($home, $order->environment)];
    }

    public function intent(Platform $home, array $input, Request $request): array
    {
        $client = $this->owner($home, $input);
        Validator::make($input, ['trip_id' => 'required|integer|min:1', 'generation' => 'required|string|max:80', 'quote_token' => 'required|string|size:64', 'request_id' => 'required|string|max:100', 'method' => 'required|in:wallet,kopokopo,pawapay', 'phone' => 'nullable|string|max:30'])->validate();
        $order = TourOrder::where('platform_id', $home->id)->where('client_id', $client->id)->where('quote_token_hash', hash('sha256', $input['quote_token']))->firstOrFail();
        abort_unless((int) $input['trip_id'] === (int) $order->trip_id && (string) $input['generation'] === $order->generation, 409, 'This quote belongs to a different trip version.');
        if ($replay = $order->attempts()->where('request_id', $input['request_id'])->first()) {
            abort_unless($replay->method === $input['method'], 409, 'This request key already belongs to another payment method.');

            return $this->present($order);
        }
        abort_unless(app(ListingEligibility::class)->facts($client)['listing_active'], 409, 'Activate your home profile before buying a tour.');
        Money::factor($order->currency);
        $config = $this->configuration($home);
        abort_unless(in_array($input['method'], $this->methods($home, $order->environment), true), 422, 'This payment method is not available.');
        abort_unless($order->environment === $config->settings['environment'] && $order->config_revision === $config->revision, 409, 'Tours pricing or mode changed. Build a fresh quote.');
        $snapshot = (new WpSyncService($home))->getTourTrip($order->trip_id);
        abort_unless((string) ($snapshot['generation'] ?? '') === $order->generation && empty($snapshot['restricted']) && ($snapshot['status'] ?? '') !== 'cancelled', 409, 'The trip changed. Check it before paying.');
        abort_unless(($snapshot['purchase_eligible'] ?? false) === true, 409, 'Activate your home profile before buying a tour.');
        foreach ($order->stops as $stop) {
            app(CapabilityService::class)->requireDestination($home, Platform::findOrFail($stop->destination_platform_id), $order->environment, $order->snapshot['media']);
        }
        // Bind the source to this order before any monetary action. A failed binding cannot charge.
        (new WpSyncService($home))->setTourPaymentState($order->trip_id, ['environment' => $order->environment, 'generation' => $order->generation, 'order_reference' => (string) $order->public_id, 'state' => 'pending', 'pass_expires_at' => $order->pass_expires_at->timestamp, 'remote_stops' => []]);
        $created = false;
        $attempt = DB::transaction(function () use ($order, $input, $home, &$created) {
            $order = TourOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($old = $order->attempts()->where('request_id', $input['request_id'])->first()) {
                abort_unless($old->method === $input['method'], 409, 'This request key already belongs to another payment method.');

                return $old;
            }
            abort_unless(! $order->winning_attempt_id && ! in_array($order->state, ['cancelled', 'paid'], true), 409, 'This order is already resolved.');
            abort_unless($order->quote_expires_at->isFuture(), 409, 'Your quote expired. Refresh before paying.');
            abort_if($order->attempts()->whereIn('state', ['initiating', 'pending', 'review'])->exists(), 409, 'A payment is still being checked. Wait for its result before trying another method.');
            $phone = PhoneNormalizer::normalize((string) ($input['phone'] ?? ''), (string) $home->phone_prefix);
            abort_unless($input['method'] === 'wallet' || ($phone && preg_match('/^\d{9,15}$/D', $phone)), 422, 'Enter a valid payment phone number.');
            $payment = null;
            if ($input['method'] !== 'wallet') {
                $payment = Payment::create(['platform_id' => $home->id, 'client_id' => $order->client_id, 'phone' => $phone, 'amount' => $order->amount_minor / Money::factor($order->currency), 'currency' => $order->currency, 'transaction_uuid' => Str::uuid(), 'reference_number' => 'TR-'.Str::upper(Str::random(12)), 'status' => 'initiated', 'purpose' => Payment::PURPOSE_TOUR_PASS, 'source' => 'website_tour_pass', 'provider_key' => $input['method'], 'provider_environment' => $order->environment, 'payment_data' => ['billing_surface' => 'tour_pass', 'test_mode' => $order->environment === 'sandbox']]);
            }
            $attempt = TourPaymentAttempt::create(['public_id' => Str::uuid(), 'tour_order_id' => $order->id, 'request_id' => $input['request_id'], 'method' => $input['method'], 'payment_id' => $payment?->id, 'state' => 'initiating', 'initiated_at' => now()]);
            $order->update(['state' => 'awaiting_payment']);
            $created = true;

            return $attempt;
        });
        if ($created) {
            if ($attempt->method === 'wallet') {
                try {
                    app(FulfillmentService::class)->wallet($order, $attempt);
                } catch (\Throwable $e) {
                    if ((int) $order->fresh()->winning_attempt_id === (int) $attempt->id) {
                        report($e);

                        return $this->present($order->fresh());
                    }
                    $attempt->update(['state' => 'failed', 'action' => ['type' => 'failed', 'message' => $e->getMessage()]]);
                    throw $e;
                }
            } else {
                try {
                    $result = app(BillingGatewayService::class)->initiateTourPass($attempt->payment, $attempt->method, ['environment' => $order->environment], $request);
                    TourPaymentAttempt::whereKey($attempt->id)->where('state', 'initiating')->update(['state' => 'pending', 'action' => json_encode($result['action'] ?? ['type' => 'pending'])]);
                } catch (\Throwable $e) {
                    report($e);
                    TourPaymentAttempt::whereKey($attempt->id)->where('state', 'initiating')->update(['state' => 'pending', 'action' => json_encode(['type' => 'pending', 'message' => 'Checking your payment request. Do not pay again.'])]);
                }
            }
        }

        return $this->present($order->fresh());
    }

    public function status(Platform $home, string $ref, array $input): array
    {
        $client = $this->owner($home, $input);
        $order = TourOrder::where('platform_id', $home->id)->where('client_id', $client->id)->where('public_id', $ref)->firstOrFail();
        $attempt = $order->attempts()->latest('id')->first();
        if ($attempt?->payment && in_array($attempt->state, ['initiating', 'pending'], true)) {
            try {
                $verification = app(ProviderStatusQueryOrchestrator::class)->verify($attempt->payment);
                $decision = app(ProviderStatusQueryOrchestrator::class)->decideMutation($attempt->payment, $verification);
                if (($decision['decision'] ?? '') === 'apply_completed') {
                    app(\App\Services\PaymentCompletionService::class)->complete($attempt->payment, $verification['data'] ?? []);
                }
                if (($decision['decision'] ?? '') === 'apply_failed') {
                    $this->failAttemptIfUnsettled($order->id, $attempt->id, true);
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $attempt->payment->refresh();
            if ($attempt->payment->status === 'failed') {
                $this->failAttemptIfUnsettled($order->id, $attempt->id, false);
            }
        }

        return $this->present($order->fresh());
    }

    private function failAttemptIfUnsettled(int $orderId, int $attemptId, bool $providerVerified): void
    {
        DB::transaction(function () use ($orderId, $attemptId, $providerVerified) {
            $order = TourOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $attempt = TourPaymentAttempt::whereKey($attemptId)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($attempt->payment_id)->lockForUpdate()->firstOrFail();
            if ($order->winning_attempt_id || $payment->completed_at || in_array($payment->status, Payment::SUCCESSFUL_STATUSES, true) || ! in_array($attempt->state, ['initiating', 'pending'], true)) {
                return;
            }
            if ($providerVerified) {
                $payment->update(['status' => 'failed', 'failure_reason' => 'Provider verified this payment failed.']);
            }
            if ($payment->status === 'failed') {
                $attempt->update(['state' => 'failed', 'action' => ['type' => 'failed', 'message' => 'Payment did not complete. Try again or choose another method.']]);
            }
        }, 3);
    }

    public function cancel(Platform $home, string $ref, array $input): array
    {
        $client = $this->owner($home, $input);
        $order = TourOrder::where('platform_id', $home->id)->where('client_id', $client->id)->where('public_id', $ref)->firstOrFail();
        DB::transaction(function () use ($order) {
            $locked = TourOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if(! $locked->winning_attempt_id && $locked->attempts()->whereIn('state', ['initiating', 'pending', 'review'])->exists(), 409, 'A payment is still being checked. Resolve it before cancelling.');
            $locked->update(['state' => 'cancelled', 'cancelled_at' => now()]);
            $locked->stops()->whereNotIn('state', ['removed', 'refunded'])->update(['state' => 'removing', 'next_attempt_at' => now()]);
        });
        \App\Jobs\ReconcileTourOrder::dispatch($order->id)->afterCommit();

        return $this->present($order->fresh());
    }

    public function present(TourOrder $order): array
    {
        $attempt = $order->attempts()->latest('id')->first();

        return ['order_reference' => (string) $order->public_id, 'state' => $order->state, 'environment' => $order->environment, 'amount_minor' => $order->amount_minor, 'currency' => $order->currency, 'passes' => $order->quote['passes'], 'pass_expires_at' => $order->pass_expires_at->timestamp, 'home_subscription_end' => $order->snapshot['home_subscription_end'] ?? null, 'attempt' => $attempt ? ['reference' => $attempt->public_id, 'method' => $attempt->method, 'state' => $attempt->state, 'action' => $attempt->action] : null, 'remote_stops' => $order->stops()->get()->map(fn ($s) => ['key' => $s->source_stop_key, 'platform_id' => $s->destination_platform_id, 'url' => $s->remote_url, 'state' => $s->state, 'message' => $s->last_error, 'retry_at' => $s->next_attempt_at?->toIso8601String()])->all()];
    }
}
