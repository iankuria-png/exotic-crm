<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Services\PayLink\CheckoutService;
use Illuminate\Http\Request;

class PayLinkController extends Controller
{
    private function input(Request $r): array
    {
        return $r->validate(['code' => 'sometimes|string|max:16', 'session_proof' => 'required|string|min:32|max:256', 'visitor_ip' => 'required|ip',
            'product_price_id' => 'sometimes|integer|min:1', 'config_revision' => 'sometimes|integer|min:1', 'phone' => 'nullable|string|max:30',
            'confirm_saved_phone' => 'sometimes|boolean', 'provider_key' => 'sometimes|in:pawapay', 'recovery_token' => 'sometimes|string|max:128',
            'locale'=>'sometimes|in:en,fr,sw','pricing_mode'=>'sometimes|in:flexible','product_id'=>'sometimes|integer|min:1','amount'=>'sometimes|numeric|min:0|max:999999999',
            'requested_days'=>'sometimes|integer|min:3|max:365','rules_version'=>'sometimes|integer|min:0','catalogue_hash'=>'sometimes|string|size:64','confirmation_hash'=>'sometimes|string|size:64',
            'offer_context'=>'nullable|string|max:3000','rescue_payment_id'=>'nullable|integer','nudge_used'=>'nullable|string|max:30',
            'pairing_token' => 'sometimes|string|max:128']);
    }

    private function response(array $body)
    {
        return response()->json($body)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }

    public function resolve(Request $r) { return $this->response(app(CheckoutService::class)->resolve($r->attributes->get('wallet_platform'), $this->input($r))); }
    public function quote(Request $r) { return $this->response(app(CheckoutService::class)->quote($r->attributes->get('wallet_platform'),$this->input($r))); }
    public function start(Request $r)
    {
        try { return $this->response(app(CheckoutService::class)->start($r->attributes->get('wallet_platform'), $this->input($r), (string) $r->header('X-Idempotency-Key'))); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $unreserved = in_array($e->getStatusCode(), [404,422], true) && !\App\Models\PayLinkCharge::where('platform_id', $r->attributes->get('wallet_platform')->id)
                ->where('idempotency_hash', hash('sha256', (string)$r->header('X-Idempotency-Key')))->exists();
            if (!$unreserved && ($e->getHeaders()['X-Pay-Link-Unreserved'] ?? '') !== '1') throw $e;
            return $this->response(['message' => $e->getMessage(), 'errors' => ['pay_link_refresh' => ['No charge was reserved. Refresh plans.']]])->setStatusCode(409);
        }
    }
    public function status(Request $r, string $ref) { return $this->response(app(CheckoutService::class)->status($r->attributes->get('wallet_platform'), $ref, $this->input($r))); }

    public function pair(Request $r)
    {
        $d = $this->input($r); $p = $r->attributes->get('wallet_platform');
        app(CheckoutService::class)->budget('pair', $p->id.':'.$d['visitor_ip'], 5);
        return $this->response(\Illuminate\Support\Facades\DB::transaction(function () use ($d, $p) {
            $pair = \Illuminate\Support\Facades\DB::table('pay_link_device_pairings')->where('platform_id', $p->id)->where('token_hash', hash('sha256', $d['pairing_token'] ?? ''))->lockForUpdate()->first();
            abort_unless($pair && !$pair->consumed_at && \Carbon\Carbon::parse($pair->expires_at)->isFuture(), 422, 'This pairing code is invalid or expired.');
            $s = \App\Models\PayLinkSetting::where('platform_id', $p->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->rollout_mode === 'test', 409, 'Test device enrollment is off.');
            $hashes = array_values(array_unique(array_merge($s->test_device_hashes ?? [], [hash('sha256', $d['session_proof'])])));
            abort_if(count($hashes) > 100, 422, 'Test device limit reached.');
            $s->update(['test_device_hashes' => $hashes, 'config_revision' => $s->config_revision + 1]);
            \Illuminate\Support\Facades\DB::table('pay_link_device_pairings')->where('id', $pair->id)->update(['consumed_at' => now()]);
            abort_unless(app(\App\Services\AuditService::class)->record(['platform_id' => $p->id, 'entity_type' => 'pay_link_settings', 'entity_id' => $s->id,
                'actor_id' => $pair->created_by_user_id, 'action' => 'device_paired', 'reason' => 'Consumed staff-issued single-use pairing', 'after_state' => ['pairing_id' => $pair->id]]), 503);
            return ['paired' => true];
        }));
    }
}
