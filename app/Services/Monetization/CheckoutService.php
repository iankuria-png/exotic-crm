<?php

namespace App\Services\Monetization;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\PremiumContentOffer;
use App\Models\VisitorContentPurchase;
use App\Services\BillingGatewayService;
use App\Services\MonetizationSettingsService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    public function intent(Platform $platform, array $input, string $attempt, Request $request): array
    {
        $s = app(MonetizationSettingsService::class)->forPlatform($platform);
        $access = app(AccessService::class);
        $device = $access->device($s, $input['session_proof']);
        $hash = hash('sha256', $platform->id.':'.$device.':'.$attempt);
        $created = false;
        $purchase = DB::transaction(function () use ($platform, $input, $hash, $device, $access, $s, &$created) {
            // Serialize both different attempts on one offer and an identical attempt replay.
            $offer = PremiumContentOffer::where('platform_id', $platform->id)->where('public_id', $input['offer_public_id'])->lockForUpdate()->firstOrFail();
            if ($old = VisitorContentPurchase::where('idempotency_key_hash', $hash)->first()) {
                abort_unless($old->offer_id === $offer->id, 409, 'This attempt belongs to another offer.');

                return $old;
            }
            app(MonetizationSettingsService::class)->assertCommerce($s, 'checkout');
            abort_unless(app(OfferService::class)->available($offer, $s, (bool) ($input['test_device'] ?? false)), 409, 'This offer is no longer available.');
            $provider = $input['provider_key'];
            abort_unless(in_array($provider, $s->checkout_policy_json['allowed_providers'] ?? [], true), 422, 'Choose an available payment provider.');
            $phone = PhoneNormalizer::normalize($input['visitor_phone'], (string) $platform->phone_prefix);
            abort_unless($phone && strlen($phone) >= 9 && strlen($phone) <= 15, 422, 'Enter the mobile money number you will pay with.');
            foreach (['device:'.$device, 'phone:'.hash_hmac('sha256', $phone, $s->device_pepper)] as $subject) {
                $limitKey = 'pc-checkout:'.$platform->id.':'.$subject;
                abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limitKey, 10), 429, 'Too many payment requests. Please wait before trying again.');
                \Illuminate\Support\Facades\RateLimiter::hit($limitKey, 60);
            }
            $existing = VisitorContentPurchase::where('platform_id', $platform->id)->where('offer_id', $offer->id)->where('offer_version', $offer->version)->where('status', 'active')->whereIn('id', DB::table('premium_content_purchase_devices')->where('device_hash', $device)->select('purchase_id'))->first();
            if ($existing) {
                return $existing;
            }
            $sandbox = $s->rollout_mode === 'sandbox';
            $reference = 'PC-'.now()->format('ymd').'-'.strtoupper(Str::random(8));
            $payment = Payment::create(['platform_id' => $platform->id, 'client_id' => null, 'user_id' => null, 'escort_post_id' => null, 'phone' => $phone, 'amount' => $offer->amount, 'currency' => $offer->currency, 'transaction_uuid' => Str::uuid(), 'transaction_reference' => $reference, 'reference_number' => $reference, 'status' => 'initiated', 'purpose' => Payment::PURPOSE_PREMIUM_CONTENT_SALE, 'source' => 'website_private_content', 'provider_key' => $provider, 'provider_environment' => $sandbox ? 'sandbox' : 'production', 'payment_data' => ['billing_surface' => 'premium_content', 'test_mode' => $sandbox]]);
            $purchase = VisitorContentPurchase::create([
                'public_id' => Str::uuid(), 'platform_id' => $platform->id, 'client_id' => $offer->client_id, 'offer_id' => $offer->id, 'payment_id' => $payment->id,
                'status' => 'pending_payment', 'offer_kind' => $offer->kind, 'offer_version' => $offer->version, 'currency' => $offer->currency, 'gross_amount' => $offer->amount,
                'entitlement_snapshot_json' => $offer->assets->map(fn ($a) => ['public_id' => $a->public_id, 'content_fingerprint' => $a->content_fingerprint, 'preview_url' => $a->preview_url, 'media_type' => $a->media_type, 'duration_seconds' => $a->duration_seconds])->all(),
                'visitor_phone_hash' => hash_hmac('sha256', $phone, $s->device_pepper), 'visitor_phone_masked' => str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4),
                'first_device_hash' => $device, 'public_token_hash' => hash('sha256', Str::random(64)), 'idempotency_key_hash' => $hash, 'is_sandbox' => $sandbox,
            ]);
            $access->bind($purchase, $device, 3);
            $created = true;

            return $purchase;
        }, 3);
        $action = data_get($purchase->metadata_json, 'action');
        if ($created && app()->environment('local') && config('monetization.local_simulator') && $purchase->is_sandbox) {
            $action = ['type' => 'local_sandbox', 'message' => 'Local simulation: no provider request or wallet credit.'];
            $purchase->update(['metadata_json' => ['action' => $action]]);
        } elseif ($created) {
            // Provider work occurs once, outside the database transaction. A lost response is polled, never recharged.
            try {
                $result = app(BillingGatewayService::class)->initiatePremiumContent($purchase->payment, $input['provider_key'], ['environment' => $purchase->is_sandbox ? 'sandbox' : 'production', 'description' => 'Exotic purchase'], $request);
                $action = $result['action'] ?? $result;
                unset($action['provider_payload']);
                $purchase->update(['metadata_json' => ['action' => $action]]);
            } catch (\Throwable $e) {
                $purchase->update(['metadata_json' => ['initiation_uncertain' => true]]);
                report($e);
                $action = ['type' => 'pending', 'message' => 'Checking the payment request. Do not pay again.'];
            }
        }

        return ['purchase_reference' => $purchase->public_id, 'status' => $purchase->status, 'payment' => ['reference' => $purchase->payment->reference_number, 'amount' => $purchase->gross_amount, 'currency' => $purchase->currency, 'statement_payee' => 'Exotic'], 'action' => $action, 'replayed' => ! $created];
    }
}
