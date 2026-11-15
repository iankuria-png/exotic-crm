<?php

namespace App\Services\PayLink;

use App\Models\{Client, ClientPayLink, PayLinkCharge, PayLinkSetting, Payment, Platform, BillingRoutingDecision};
use App\Services\{BillingModeService, ClientLifecycleMutationLock, DealPaymentService};
use App\Billing\Providers\PawaPay\PawaPayDirectDepositAdapter;
use App\Billing\Support\{BillingProviderTransactionRecorder, BillingSurface};
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\{DB, RateLimiter};
use Illuminate\Support\Str;

class CheckoutService
{
    public static function mask(?string $phone): string { return $phone ? '••• ••• '.substr($phone, -3) : ''; }

    public function budget(string $kind, string $identity, int $limit, int $seconds = 600): void
    {
        $key = 'pay-link:'.$kind.':'.hash('sha256', $identity);
        abort_unless(RateLimiter::attempt($key, $limit, fn () => true, $seconds), 429, 'Please wait before trying again.');
    }

    private function device(array $input): string
    {
        $proof = (string) ($input['session_proof'] ?? '');
        abort_unless(strlen($proof) >= 32 && strlen($proof) <= 256, 422, 'Reload this page to continue.');
        return hash('sha256', $proof);
    }

    private function gate(PayLinkSetting $s, Client $c, string $device): void
    {
        abort_unless(in_array($s->rollout_mode, ['test', 'live'], true), 404, 'This pay link is not active.');
        if ($s->rollout_mode === 'test') abort_unless(in_array((int) $c->id, array_map('intval', $s->test_client_ids ?? []), true)
            && in_array($device, $s->test_device_hashes ?? [], true), 404, 'This pay link is not active.');
    }

    public function resolve(Platform $p, array $input): array
    {
        $this->budget('resolve', $p->id.':'.($input['visitor_ip'] ?? ''), 30);
        $link = app(LinkService::class)->find($p->id, (string) ($input['code'] ?? ''));
        abort_unless($link, 404, 'This pay link is not active.');
        $s = app(SettingsService::class)->forPlatform($p);
        $this->gate($s, $link->client, $this->device($input));
        $binding=app(SettingsService::class)->binding($p, $s->rollout_mode === 'test' ? 'sandbox' : 'production');
        $context=app(BillingModeService::class)->profileBackedProviderContext($p,$binding->providerProfile,$binding->id,false,$s->rollout_mode==='test'?'sandbox':'production',BillingSurface::PayLink);
        app(PawaPayDirectDepositAdapter::class)->configuration($context);
        $state = app(EligibilityService::class)->snapshot($link->client);
        $offers = app(EligibilityService::class)->offers($p, $s->offers_json ?? [], $state);
        $link->increment('open_count'); $link->update(['last_opened_at' => now()]);
        return ['display_name' => $link->client->name, 'wp_post_id' => $link->client->wp_post_id, 'current_tier' => $state['current_tier'],
            'live' => (bool) $state['live'], 'escort_expire' => $state['escort_expire'], 'offers' => $offers,
            'config_revision' => $s->config_revision, 'phone_masked' => self::mask($link->client->phone_normalized), 'availability' => 'ready',
            'support_whatsapp' => $s->support_whatsapp, 'test_mode' => $s->rollout_mode === 'test',
            'market_timezone' => $p->timezone ?: 'Africa/Nairobi',
            'processing' => PayLinkCharge::where('blocking_client_id', $link->client_id)->exists(),
            'flexible_amounts' => $this->flexibleCatalogue($p,$link,$input,$state)];
    }

    private function flexibleCatalogue(Platform $p, $link, array $input, array $state): array
    {
        $context=app(\App\Services\FlexibleAmounts\FlexibleLinkOfferService::class)->resolve($link,$input['offer_context']??null);
        return app(\App\Services\FlexibleAmounts\FlexibleQuoteService::class)->catalogue($p,$link->client,$context['surface'],$state)+['suggested_amount'=>$context['suggested_amount']];
    }

    public function quote(Platform $p,array $input): array
    {
        $this->budget('quote',$p->id.':'.$this->device($input),90,60);
        $link=app(LinkService::class)->find($p->id,(string)($input['code']??''));
        abort_unless($link,404,'This pay link is not active.');
        $this->gate(app(SettingsService::class)->forPlatform($p),$link->client,$this->device($input));
        $context=app(\App\Services\FlexibleAmounts\FlexibleLinkOfferService::class)->resolve($link,$input['offer_context']??null);
        return app(\App\Services\FlexibleAmounts\FlexibleQuoteService::class)->quote($p,$link->client,$input,$context['surface']);
    }

    private function fingerprint(Platform $p, array $input): string
    {
        $phone = trim((string) ($input['phone'] ?? ''));
        return hash('sha256', json_encode(array_merge([LinkService::canonical((string) ($input['code'] ?? '')), (int) ($input['product_price_id'] ?? 0),
            (int) ($input['config_revision'] ?? 0), $phone !== '' ? PhoneNormalizer::normalize($phone, $p->phone_prefix) : 'saved',
            (bool) ($input['confirm_saved_phone'] ?? false), $input['provider_key'] ?? 'pawapay'],
            ($input['pricing_mode']??'')==='flexible'?array_intersect_key($input,array_flip(['pricing_mode','product_id','amount','requested_days','rules_version','catalogue_hash','confirmation_hash','offer_context','rescue_payment_id','nudge_used','locale'])):[])));
    }

    private function replay(PayLinkCharge $c, string $fingerprint, string $device): array
    {
        abort_unless(hash_equals($c->request_fingerprint, $fingerprint) && hash_equals($c->session_proof_hash, $device), 409, 'This payment attempt has different details.');
        return $this->view($c) + ['recovery_token' => $c->recovery_token_encrypted, 'resumed' => true];
    }

    private function unchanged(bool $condition): void
    {
        if (!$condition) abort(409, 'Plans changed. Refresh before paying.', ['X-Pay-Link-Unreserved' => '1']);
    }

    public function start(Platform $p, array $input, string $key): array
    {
        abort_unless(strlen($key) >= 16 && strlen($key) <= 128, 422, 'Reload this page to continue.');
        $device = $this->device($input); $hash = hash('sha256', $key); $fp = $this->fingerprint($p, $input);
        // Recovery precedes ALL creation gates, including revoked links and changed catalogue/mode.
        if ($old = PayLinkCharge::where('platform_id', $p->id)->where('idempotency_hash', $hash)->first()) return $this->replay($old, $fp, $device);
        $link = app(LinkService::class)->find($p->id, (string) ($input['code'] ?? ''));
        abort_unless($link, 404, 'This pay link is not active.');
        try { $result = app(ClientLifecycleMutationLock::class)->run($link->client_id, function () use ($p, $input, $link, $device, $hash, $fp) {
            if ($old = PayLinkCharge::where('platform_id', $p->id)->where('idempotency_hash', $hash)->first()) return $old;
            $c = $link->client->fresh('platform'); $s = app(SettingsService::class)->forPlatform($p);
            $this->gate($s, $c, $device);
            $this->unchanged((int) $s->config_revision === (int) ($input['config_revision'] ?? 0));
            abort_if(PayLinkCharge::where('blocking_client_id', $c->id)->exists(), 409, 'A payment is still being checked. Please wait or contact support.');
            app(WriterGuard::class)->assertNoExternalFinancialWork($c);
            $state = app(EligibilityService::class)->snapshot($c);
            $offers = app(EligibilityService::class)->offers($p, $s->offers_json ?? [], $state);
            $flexibleQuote=null;
            $surface=app(\App\Services\FlexibleAmounts\FlexibleLinkOfferService::class)->resolve($link,$input['offer_context']??null)['surface'];
            if (($input['pricing_mode']??'')==='flexible') {
                $flexibleQuote=app(\App\Services\FlexibleAmounts\FlexibleQuoteService::class)->quote($p,$c,$input,$surface,$state);
                app(\App\Services\FlexibleAmounts\FlexibleQuoteService::class)->confirm($input,$flexibleQuote);
                $offer=['product_price_id'=>$flexibleQuote['base_product_price_id'],'product_id'=>$flexibleQuote['product_id'],'tier'=>$flexibleQuote['tier'],'amount'=>$flexibleQuote['charge'],'currency'=>$flexibleQuote['currency'],'days'=>$flexibleQuote['days'],'locked'=>false];
            } else {
                $offer = collect($offers)->firstWhere('product_price_id', (int) ($input['product_price_id'] ?? 0));
                abort_unless($offer && !$offer['locked'], 422, 'Choose an available plan for this profile.');
            }
            abort_unless(($input['provider_key'] ?? 'pawapay') === 'pawapay', 422, 'This payment method is unavailable.');
            $phone = trim((string) ($input['phone'] ?? ''));
            abort_unless($phone !== '' || ($input['confirm_saved_phone'] ?? false) === true, 422, 'Confirm the saved number or enter another one.');
            $phone = PhoneNormalizer::normalize($phone !== '' ? $phone : $c->phone_normalized, $p->phone_prefix);
            abort_unless($phone && (strtoupper($p->currency_code)==='KES'?preg_match('/^254[17][0-9]{8}$/D',$phone):preg_match('/^'.preg_quote((string)$p->phone_prefix,'/').'[0-9]{8,10}$/D',$phone)),422,'Check your mobile-money number.');
            $this->budget('market-start', (string) $p->id, 100);
            $this->budget('link-start', (string) $link->id, 5);
            $this->budget('link-day', (string) $link->id, 15, 86400);
            $this->budget('phone-start', $p->id.':'.$phone, 3);
            $this->budget('ip-start', $p->id.':'.($input['visitor_ip'] ?? ''), 10);
            $env = $s->rollout_mode === 'test' ? 'sandbox' : 'production';
            $binding = app(SettingsService::class)->binding($p, $env);
            $context = app(BillingModeService::class)->profileBackedProviderContext($p, $binding->providerProfile, $binding->id, false, $env, BillingSurface::PayLink);
            app(PawaPayDirectDepositAdapter::class)->configuration($context,$offer['amount']);
            $prediction = app(PawaPayDirectDepositAdapter::class)->predict($context, $phone);
            app(PawaPayDirectDepositAdapter::class)->configuration($context,$offer['amount'],$prediction['provider']);
            return DB::transaction(function () use ($p, $input, $link, $c, $s, $device, $hash, $fp, $offer, $phone, $env, $binding, $context, $prediction, $flexibleQuote, $surface) {
                Client::whereKey($c->id)->lockForUpdate()->firstOrFail();
                if ($old = PayLinkCharge::where('platform_id', $p->id)->where('idempotency_hash', $hash)->first()) return $old;
                abort_unless(ClientPayLink::whereKey($link->id)->where('status', 'active')->exists(), 409, 'This pay link changed.');
                $current = PayLinkSetting::whereKey($s->id)->lockForUpdate()->firstOrFail();
                $this->unchanged($current->config_revision === $s->config_revision && $current->rollout_mode === $s->rollout_mode);
                abort_if(PayLinkCharge::where('blocking_client_id', $c->id)->exists(), 409, 'A payment is still being checked.');
                app(WriterGuard::class)->assertNoExternalFinancialWork($c);
                \App\Models\Product::whereKey($offer['product_id'])->lockForUpdate()->firstOrFail();
                \App\Models\ProductPrice::whereKey($offer['product_price_id'])->lockForUpdate()->firstOrFail();
                $price = app(EligibilityService::class)->price($p, $offer['product_price_id']);
                if ($flexibleQuote) {
                    $fresh=app(\App\Services\FlexibleAmounts\FlexibleQuoteService::class)->quote($p,$c,$input,$surface,null,true);
                    app(\App\Services\FlexibleAmounts\FlexibleQuoteService::class)->confirm($input,$fresh);
                    $flexibleQuote=$fresh;
                    $flexibleQuote['rescue_payment_id']=$input['rescue_payment_id']??null;
                    $flexibleQuote['nudge_used']=$input['nudge_used']??null;
                    if (!empty($input['rescue_payment_id'])) {
                        $failed=Payment::where('client_id',$c->id)->where('platform_id',$p->id)->findOrFail($input['rescue_payment_id']);
                        $failedCharge=PayLinkCharge::where('payment_id',$failed->id)->where('pay_link_id',$link->id)->firstOrFail();
                        abort_unless(hash_equals($failedCharge->session_proof_hash,$device),403);
                        $options=app(\App\Services\FlexibleAmounts\FlexibleRescueService::class)->forPayment($failed,$surface,['codes'=>[data_get($failedCharge->provider_evidence,'failure_code')],'messages'=>[data_get($failedCharge->provider_evidence,'failure_message')]]);
                        abort_unless($failed->product_id===$fresh['product_id']&&collect($options)->contains(fn($q)=>$q['days']===$fresh['days']&&$q['charge']===$fresh['charge']),422,'Refresh smaller payment options.');
                    }
                } else {
                    $this->unchanged((string) $price->price === $offer['amount'] && $price->duration_days === $offer['days'] && strtoupper($price->currency) === $offer['currency']
                        && $price->product_id === $offer['product_id'] && app(DealPaymentService::class)->derivePlanTypeFromProduct($price->product) === $offer['tier']);
                }
                $deal = app(DealPaymentService::class)->createPendingDealFromCatalog($c, $price->product_id, $price->id, null, null, null);
                if ($flexibleQuote) $deal->update(['amount'=>$offer['amount'],'duration_days'=>$offer['days'],'duration'=>'manual']);
                $ref = (string) Str::uuid(); $deposit = (string) Str::uuid(); $token = bin2hex(random_bytes(32));
                $payment = Payment::create(['platform_id' => $p->id, 'client_id' => $c->id, 'deal_id' => $deal->id, 'product_id' => $price->product_id,
                    'user_id' => $c->wp_user_id, 'escort_post_id' => $c->wp_post_id, 'phone' => $phone, 'amount' => $offer['amount'], 'currency' => $offer['currency'],
                    'status' => 'pending', 'purpose' => Payment::PURPOSE_SUBSCRIPTION, 'source' => 'self_service_pay_link', 'provider_key' => 'pawapay',
                    'provider_environment' => $env, 'transaction_uuid' => $deposit, 'transaction_reference' => $deposit, 'reference_number' => 'PL-'.$ref,
                    'payment_data' => array_merge(['billing_surface' => 'pay_link', 'pay_link_ref' => $ref, 'test_mode' => $env === 'sandbox', 'pawapay' => ['deposit_id' => $deposit]],$flexibleQuote?[
                        'pricing_mode'=>'flexible','flexible_quote'=>$flexibleQuote,'base_product_price_id'=>$price->id,'product_price_id'=>$price->id,'duration_days'=>$offer['days'],
                        'initiator'=>$surface==='crm_link'?'staff_link':'companion']:[]),
                    'raw_payload' => ['source' => 'self_service_pay_link', 'method' => 'provider']]);
                BillingRoutingDecision::create(['payment_id' => $payment->id, 'market_id' => $p->id, 'billing_surface' => 'pay_link', 'chosen_binding_id' => $binding->id,
                    'provider_profile_id' => $binding->provider_profile_id, 'provider_type_key' => 'pawapay', 'execution_mode' => 'direct', 'environment' => $env,
                    'immutable_until_terminal_state' => true, 'decision_version' => 1, 'created_at' => now(),
                    'snapshot_json' => ['billing_surface' => 'pay_link', 'pricing' => ['amount' => $offer['amount'], 'currency' => $offer['currency']], 'execution_family' => 'direct_push'],
                    'decision_json' => ['source' => 'self_service_pay_link']]);
                app(BillingProviderTransactionRecorder::class)->recordInitiation($payment, $context, ['provider_reference' => $deposit], ['reason_code' => 'pay_link_reserved']);
                return PayLinkCharge::create(['public_ref' => $ref, 'platform_id' => $p->id, 'client_id' => $c->id, 'pay_link_id' => $link->id,
                    'deal_id' => $deal->id, 'payment_id' => $payment->id, 'blocking_client_id' => $c->id, 'idempotency_hash' => $hash, 'request_fingerprint' => $fp,
                    'provider_profile_id' => $binding->provider_profile_id, 'binding_id' => $binding->id, 'environment' => $env, 'deposit_id' => $deposit,
                    'amount' => $offer['amount'], 'currency' => $offer['currency'], 'duration_days' => $offer['days'], 'tier' => $offer['tier'], 'product_price_id' => $price->id,
                    'phone' => $phone, 'session_proof_hash' => $device, 'recovery_token_hash' => hash('sha256', $token), 'recovery_token_encrypted' => $token,
                    'request_json' => ['depositId' => $deposit, 'payer' => ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => $phone, 'provider' => $prediction['provider']]],
                        'amount' => rtrim(rtrim($offer['amount'],'0'),'.'), 'currency' => $offer['currency']==='CFA'?'XOF':$offer['currency'], 'clientReferenceId' => $ref, 'customerMessage' => 'Profile renewal'],
                    'next_reconcile_at' => now()]);
            });
        }); } catch (\App\Exceptions\ClientLifecycleMutationException $e) {
            if ($old = PayLinkCharge::where('platform_id', $p->id)->where('idempotency_hash', $hash)->first()) return $this->replay($old, $fp, $device);
            throw $e;
        }
        app(ReconciliationService::class)->reconcile($result->id);
        return array_replace($this->replay($result->fresh(), $fp, $device), ['resumed' => false]);
    }

    public function status(Platform $p, string $ref, array $input): array
    {
        $this->budget('status', $p->id.':'.$this->device($input), 180, 60);
        $charge = PayLinkCharge::where('platform_id', $p->id)->where('public_ref', $ref)->first();
        $link = app(LinkService::class)->find($p->id, (string) ($input['code'] ?? ''), false);
        abort_unless($charge && $link && $charge->pay_link_id === $link->id
            && hash_equals($charge->session_proof_hash, $this->device($input))
            && hash_equals($charge->recovery_token_hash, hash('sha256', (string) ($input['recovery_token'] ?? ''))), 404);
        return $this->view($charge);
    }

    public function view(PayLinkCharge $c): array
    {
        $applied = $c->activation_state === 'applied';
        return ['charge_ref' => $c->public_ref, 'payment_state' => $c->payment_state, 'activation_state' => $c->activation_state,
            'market_timezone' => $c->platform->timezone ?: 'Africa/Nairobi',
            'amount' => (string) $c->amount, 'currency' => $c->currency, 'phone_masked' => self::mask($c->phone), 'test_mode' => $c->environment === 'sandbox',
            'live_until' => $applied ? data_get($c->activation_result, 'live_until') : null,
            'profile_url' => $applied ? data_get($c->activation_result, 'profile_url') : null,
            'receipt' => $applied ? ['reference' => data_get($c->provider_evidence, 'receipt_reference'), 'paid_at' => data_get($c->provider_evidence, 'paid_at')] : null,
            'failure_category'=>$c->payment_state==='failed'?app(\App\Services\FlexibleAmounts\FlexibleRescueService::class)->category($c->payment,['codes'=>[data_get($c->provider_evidence,'failure_code')],'messages'=>[data_get($c->provider_evidence,'failure_message')]]):null,
            'rescue'=>$c->payment_state==='failed'?app(\App\Services\FlexibleAmounts\FlexibleRescueService::class)->forPayment($c->payment,data_get($c->payment->payment_data,'flexible_quote.entry_mode','pay_link'),['codes'=>[data_get($c->provider_evidence,'failure_code')],'messages'=>[data_get($c->provider_evidence,'failure_message')]]):[]];
    }
}
