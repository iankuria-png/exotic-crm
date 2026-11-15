<?php

namespace App\Services\PayLink;

use App\Models\{Deal, PayLinkCharge, PayLinkReceipt, Payment};
use App\Services\{ClientLifecycleMutationLock, CommissionService, PaymentAttemptService, WpSyncFactory};
use App\Billing\Providers\PawaPay\PawaPayDirectDepositAdapter;
use App\Billing\Support\{BillingProviderTransactionRecorder, CanonicalPaymentStateReducer};
use App\Support\WpSubscriptionExpiry;
use Illuminate\Support\Facades\{Cache, DB};

class ReconciliationService
{
    public function reconcile(int $id): void
    {
        // Production uses the same shared atomic cache store as lifecycle mutations.
        $store = trim((string) config('client_lifecycle.lock_store', ''));
        if (app()->environment('production') && ($store === '' || in_array(config("cache.stores.$store.driver"), ['array', 'file', null], true))) throw new \RuntimeException('Shared pay-link lock store unavailable.');
        $lock = ($store ? Cache::store($store) : Cache::store())->lock('pay-link-charge:'.$id, 180);
        if (!$lock->get()) return;
        try {
            $c = PayLinkCharge::with(['payment', 'platform', 'client'])->findOrFail($id);
            if ($c->final_at) return;
            if ($c->activation_state === 'needs_review') return;
            if ($c->payment_state !== 'completed') {
                if (!$c->dispatch_started_at) {
                    // This commit precedes any deposit request; a lost response can only recover this identity.
                    $c->update(['dispatch_started_at' => now(), 'payment_state' => 'submitting', 'next_reconcile_at' => now()->addMinute()]);
                    try { $action = app(PawaPayDirectDepositAdapter::class)->initiate($c); }
                    catch (\Throwable) { $action = ['state' => 'uncertain']; }
                    $c->update(['payment_state' => $action['state'], 'provider_evidence' => array_intersect_key($action, array_flip(['provider_status', 'failure_code', 'failure_message']))]);
                    app(PaymentAttemptService::class)->record($c->payment, 'pay_link_deposit', $action['state'], ['provider' => 'pawapay']);
                    if ($action['state'] === 'failed') { $this->failed($c, 'provider_rejected'); return; }
                    $this->schedule($c);
                    return;
                }
                try { $v = app(PawaPayDirectDepositAdapter::class)->verify($c); }
                catch (\Throwable) { $v = ['status' => 'pending', 'uncertain' => true]; }
                if (!empty($v['review'])) { $this->review($c, $v['review']); return; }
                if ($v['status'] === 'failed') {
                    $c->update(['provider_evidence'=>array_merge($c->provider_evidence??[],['failure_code'=>substr((string)data_get($v,'data.failureReason.failureCode'),0,100),'failure_message'=>substr((string)data_get($v,'data.failureReason.failureMessage'),0,300)])]);
                    $this->failed($c, 'provider_failed'); return;
                }
                if ($v['status'] !== 'completed') {
                    $c->update(['payment_state' => !empty($v['uncertain']) ? 'uncertain' : 'pending']);
                    if ($c->dispatch_started_at->lt(now()->subDay())) $this->review($c, 'provider_unresolved_24h'); else $this->schedule($c);
                    return;
                }
                $this->money($c, $v['data']);
                $c->refresh();
            }
            if ($c->environment === 'sandbox') return;
            app(WriterGuard::class)->ownedCharge($c, fn () => $this->activate($c));
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $row = PayLinkCharge::find($id);
            if ($row && $row->payment_state === 'completed' && in_array($e->response->status(), [400, 401, 403, 404, 409, 422], true)) {
                $this->review($row, 'wordpress_activation_requires_review');
            } else {
                PayLinkCharge::whereKey($id)->whereNull('final_at')->update(['next_reconcile_at' => now()->addMinutes(5)]);
            }
        } catch (\Throwable) {
            // Do not log transport exceptions: provider URLs/payloads can contain private identifiers.
            PayLinkCharge::whereKey($id)->whereNull('final_at')->update(['next_reconcile_at' => now()->addMinutes(5)]);
        } finally { $lock->release(); }
    }

    private function schedule(PayLinkCharge $c): void
    {
        $n = $c->attempt_count + 1;
        $minutes = [1, 2, 5, 15][$n - 1] ?? 60;
        $c->update(['attempt_count' => $n, 'next_reconcile_at' => now()->addMinutes($minutes)]);
    }

    private function failed(PayLinkCharge $c, string $reason): void
    {
        DB::transaction(function () use ($c, $reason) {
            $row = PayLinkCharge::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($row->payment_state === 'completed') return;
            $p = Payment::whereKey($row->payment_id)->lockForUpdate()->firstOrFail();
            $p->forceFill(app(CanonicalPaymentStateReducer::class)->fail($p, $reason))->save();
            Deal::whereKey($row->deal_id)->where('status', 'pending')->update(['status' => 'cancelled']);
            $row->update(['payment_state' => 'failed', 'blocking_client_id' => null, 'final_at' => now(), 'next_reconcile_at' => null]);
        });
    }

    private function money(PayLinkCharge $c, array $data): void
    {
        DB::transaction(function () use ($c, $data) {
            $row = PayLinkCharge::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($row->payment_state === 'completed') return;
            $p = Payment::whereKey($row->payment_id)->lockForUpdate()->firstOrFail();
            $sandbox = $row->environment === 'sandbox';
            $assessment = ['settlement_status' => 'matched', 'completion_policy' => 'complete', 'requested_amount' => $row->amount, 'requested_currency' => $row->currency,
                'settled_amount' => $row->amount, 'settled_currency' => $row->currency];
            app(BillingProviderTransactionRecorder::class)->recordSettlement($p, $assessment, $data);
            $p->forceFill(app(CanonicalPaymentStateReducer::class)->complete($p, ['provisioning_status' => $sandbox ? 'suppressed_sandbox' : 'pending',
                'sandbox_suppressed' => $sandbox, 'transition' => 'pay_link_money_confirmed']))->save();
            $row->update(['payment_state' => 'completed', 'activation_state' => $sandbox ? 'suppressed_sandbox' : 'pending',
                'blocking_client_id' => $sandbox ? null : $row->client_id, 'final_at' => $sandbox ? now() : null, 'next_reconcile_at' => $sandbox ? null : now(),
                'provider_evidence' => ['status' => 'COMPLETED', 'receipt_reference' => substr((string) ($data['providerTransactionId'] ?? $row->deposit_id), 0, 120),
                    'paid_at' => now()->toIso8601String(), 'payload_sha256' => hash('sha256', json_encode($data))]]);
        });
    }

    private function review(PayLinkCharge $c, string $reason): void
    {
        $c->update(['activation_state' => 'needs_review', 'review_reason' => $reason, 'next_reconcile_at' => null]);
    }

    private function activate(PayLinkCharge $c): void
    {
        app(ClientLifecycleMutationLock::class)->run($c->client_id, function () use ($c) {
            $sync = app(WpSyncFactory::class)->forPlatform($c->platform_id);
            // Recover a committed WP result BEFORE checking a later hold or changed tier.
            $saved = $sync->payLinkOperation($c->public_ref);
            $result = $saved['operation'] ?? null;
            $recovered = (bool) $result;
            if (!$result) {
                try { app(EligibilityService::class)->snapshot($c->client->fresh('platform')); }
                catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->review($c, 'profile_restricted_after_payment'); return; }
                $result = $sync->activatePayLink($c->client->wp_post_id, ['operation_key' => $c->public_ref,
                    'crm_payment_id' => $c->payment_id, 'crm_deal_id' => $c->deal_id, 'wp_user_id' => $c->client->wp_user_id,
                    'product_type' => $c->tier, 'duration_days' => $c->duration_days]);
            }
            if (($result['state'] ?? '') !== 'applied' || ($result['operation_key'] ?? '') !== $c->public_ref
                || (int) ($result['post_id'] ?? 0) !== (int) $c->client->wp_post_id || (int) ($result['crm_payment_id'] ?? 0) !== (int) $c->payment_id
                || !WpSubscriptionExpiry::reportedBy($result) || (int) ($result['escort_expire'] ?? 0) <= now()->timestamp) {
                $this->review($c, 'wordpress_result_mismatch'); return;
            }
            $expiry = WpSubscriptionExpiry::resolve($result, now(), ['payment_id' => $c->payment_id]);
            $url = (string) ($result['profile_url'] ?? '');
            $localHttp=app()->environment(['local','testing']) && parse_url($url,PHP_URL_SCHEME)==='http'
                && (in_array(parse_url($url,PHP_URL_HOST),['127.0.0.1','localhost'],true)||str_ends_with((string)parse_url($url,PHP_URL_HOST),'.local'));
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== strtolower((string) parse_url('https://'.preg_replace('#^https?://#', '', $c->platform->domain), PHP_URL_HOST))
                || (parse_url($url, PHP_URL_SCHEME) !== 'https' && !$localHttp)) { $this->review($c, 'wordpress_profile_url_mismatch'); return; }
            DB::transaction(function () use ($c, $result, $expiry, $url, $recovered) {
                $row = PayLinkCharge::whereKey($c->id)->lockForUpdate()->firstOrFail();
                if ($row->activation_state === 'applied') return;
                $p = Payment::whereKey($row->payment_id)->lockForUpdate()->firstOrFail();
                $deal = Deal::findOrFail($row->deal_id);
                $deal->update(['status' => 'active', 'activated_at' => now(), 'expires_at' => $expiry, 'payment_id' => $p->id, 'payment_reference' => $row->deposit_id]);
                $p->forceFill(app(CanonicalPaymentStateReducer::class)->complete($p, ['provisioning_status' => 'completed', 'transition' => 'pay_link_activation_applied']))->save();
                if (!$recovered) $c->client->update(['escort_expire' => $expiry->timestamp, 'profile_status' => 'publish', 'needs_payment' => false, 'notactive' => false,
                    'lifecycle_state' => 'active', 'premium' => in_array($c->tier, ['premium', 'vip', 'vvip'], true), 'premium_expire' => in_array($c->tier, ['premium', 'vip', 'vvip'], true) ? $expiry->timestamp : 0,
                    'featured' => in_array($c->tier, ['vip', 'vvip'], true), 'featured_expire' => in_array($c->tier, ['vip', 'vvip'], true) ? $expiry->timestamp : 0, 'last_synced_at' => now()]);
                app(CommissionService::class)->recordActivationCommission($deal);
                app(CommissionService::class)->recordRenewalCommission($deal);
                if (data_get($p->payment_data,'pricing_mode')==='flexible') {
                    foreach(['payment_received','profile_activated','deal_activated'] as $event) \App\Models\TimelineEvent::create([
                        'platform_id'=>$c->platform_id,'entity_type'=>'client','entity_id'=>$c->client_id,'event_type'=>$event,'actor_id'=>null,
                        'content'=>['payment_id'=>$p->id,'deal_id'=>$deal->id,'source'=>'self_service_pay_link','pricing_mode'=>'flexible','plan'=>$c->tier,'duration_days'=>$c->duration_days,'amount'=>$c->amount,'currency'=>$c->currency,'expires_at'=>$expiry->toIso8601String()],'created_at'=>now()]);
                    app(\App\Services\Rebates\RebateGrantService::class)->afterSettlement($p);
                }
                $result['live_until'] = $expiry->toIso8601String(); $result['profile_url'] = $url;
                $row->update(['activation_state' => 'applied', 'activation_result' => $result, 'blocking_client_id' => null, 'final_at' => now(), 'next_reconcile_at' => null]);
                $contact = \App\Support\PhoneNormalizer::normalize($c->client->phone_normalized, $c->platform->phone_prefix);
                PayLinkReceipt::firstOrCreate(['payment_id' => $p->id, 'kind' => 'pay_link_receipt'], ['charge_id' => $row->id, 'phone' => $contact,
                    'status' => $contact ? 'pending' : 'needs_review', 'outcome' => $contact ? null : 'advertiser_contact_missing',
                    'body' => app(\App\Services\TemplateService::class)->renderString(data_get($p->payment_data,'pricing_mode')==='flexible'?app(\App\Services\FlexibleAmounts\FlexibleReceiptService::class)->template($c->platform,data_get($p->payment_data,'flexible_quote.locale')):config('sms_templates.pay_link_receipt'), ['market_name' => $c->platform->name,
                        'currency' => $row->currency, 'amount' => (string) $row->amount, 'plan' => $row->tier, 'days'=>$row->duration_days,
                        'live_until' => $expiry->copy()->timezone($c->platform->timezone ?: 'Africa/Nairobi')->format('j M Y'), 'profile_url' => $url])['content'],
                    'next_attempt_at' => now()]);
            });
        });
    }
}
