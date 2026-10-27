<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PaymentRecoveryCase;
use App\Services\WpSyncService;
use Illuminate\Http\Request;

class PaymentRecoveryStatusController extends Controller
{
    public function status(Request $request)
    {
        $v = $request->validate(['case_reference' => 'nullable|uuid', 'payment_id' => 'nullable|integer|required_without:case_reference', 'wp_user_id' => 'required|integer|min:1', 'wp_post_id' => 'required|integer|min:1']);
        $market = (int) $request->attributes->get('platform_id');
        $case = PaymentRecoveryCase::where('platform_id', $market)->whereHas('payment', function ($q) use ($v) {
            $q->where('user_id', $v['wp_user_id'])->where(function ($q) use ($v) {
                $q->where('escort_post_id', $v['wp_post_id'])->orWhere('payment_data->recovery_purchase->wp_post_id', $v['wp_post_id']);
            });
        })->when(! empty($v['case_reference']), fn ($q) => $q->where('public_reference', $v['case_reference']), fn ($q) => $q->where('payment_id', $v['payment_id']))->first();
        abort_unless($case, 404);
        // The site assertion is checked against authoritative profile ownership; a shared key alone is insufficient.
        $profile = WpSyncService::forPlatform($market)->getClient((int) $v['wp_post_id']);
        abort_unless((int) ($profile['wp_user_id'] ?? 0) === (int) $v['wp_user_id'], 404);

        return response()->json(['case_reference' => $case->public_reference, 'state' => $case->state, 'message' => match ($case->state) {
            'closed' => 'Your payment has been resolved.', 'awaiting_fulfillment','awaiting_identity' => 'Payment received. We are completing your activation. Please do not pay again.',
            'effect_unknown' => 'Payment received. We are checking your activation. Please do not pay again.',
            default => 'Your payment is being reviewed. Support has this case; please do not pay again.',
        }]);
    }

    public function checkout(Request $request)
    {
        $v = $request->validate(['request_key' => 'required|uuid', 'wp_user_id' => 'required|integer|min:1', 'wp_post_id' => 'required|integer|min:1']);
        $platform = (int) $request->attributes->get('platform_id');
        $profile = WpSyncService::forPlatform($platform)->getClient((int) $v['wp_post_id']);
        abort_unless((int) ($profile['wp_user_id'] ?? 0) === (int) $v['wp_user_id'], 403, 'Profile ownership could not be verified.');
        $request->merge(['user_id' => $v['wp_user_id'], 'platform_id' => $platform]);
        $request->attributes->set('recovery_verified_beneficiary', ['platform_id' => $platform, 'wp_user_id' => $v['wp_user_id'], 'wp_post_id' => $v['wp_post_id']]);
        $payload = $request->only(['wp_post_id', 'product_id', 'product_price_id', 'duration', 'currency', 'provider', 'phone', 'first_name', 'last_name', 'email']);
        ksort($payload);
        $hash = hash('sha256', json_encode($payload));
        $key = ['platform_id' => $platform, 'wp_user_id' => $v['wp_user_id'], 'request_key' => $v['request_key']];
        $claim = \Illuminate\Support\Facades\DB::table('payment_owner_checkout_requests')->insertOrIgnore($key + ['request_hash' => $hash, 'state' => 'dispatching', 'created_at' => now(), 'updated_at' => now()]);
        $row = \Illuminate\Support\Facades\DB::table('payment_owner_checkout_requests')->where($key)->first();
        abort_unless($row && hash_equals($row->request_hash, $hash), 409, 'Checkout key belongs to another purchase.');
        if (! $claim) {
            if ($row->state === 'completed') {
                $body = json_decode($row->response_json, true);
                $paymentId = data_get($body, 'payment_id') ?? data_get($body, 'payment.id') ?? data_get($body, 'data.payment_id');
                $payment = $paymentId ? \App\Models\Payment::where('platform_id', $platform)->where('user_id', $v['wp_user_id'])->find($paymentId) : null;
                if ($payment && app(\App\Services\PaymentRecoveryEvidencePolicy::class)->benefit($payment)) {
                    return response()->json(['status' => false, 'code' => 'purchase_fulfilled', 'message' => 'Your previous purchase is complete. Select your package again to start a renewal.']);
                }

                return response()->json($body);
            }

            return response()->json(['status' => false, 'message' => 'This checkout is already being checked. Please keep this reference and do not start another payment.', 'request_key' => $v['request_key']], 202);
        }
        $request->attributes->set('recovery_checkout_request_key', $v['request_key']);
        try {
            $response = app(PaymentController::class)->selfCheckout($request);
            $body = $response->getData(true);
            // Preserve ambiguous failures for support instead of retrying a possibly sent charge.
            if (! empty($body['status'])) {
                \Illuminate\Support\Facades\DB::table('payment_owner_checkout_requests')->where('id', $row->id)->update(['state' => 'completed', 'response_json' => json_encode($body), 'updated_at' => now()]);
            }

            return $response;
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => false, 'message' => 'We are checking this checkout. Please do not start another payment.', 'request_key' => $v['request_key']], 202);
        }

    }
}
