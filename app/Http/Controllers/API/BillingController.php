<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\BillingGatewayService;
use App\Services\BillingModeService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingGatewayService $billingGatewayService,
        private readonly BillingModeService $billingModeService
    ) {
    }

    public function initiate(Request $request)
    {
        [$client, $platform, $context] = $this->resolveWalletClient($request);
        $validated = $request->validate([
            'provider' => 'required|string|max:30',
            'amount' => 'required|numeric|min:0.01',
            'phone' => 'nullable|string|max:30',
            'auto_subscribe' => 'nullable|array',
            'auto_subscribe.enabled' => 'nullable|boolean',
            'auto_subscribe.product_id' => 'nullable|integer',
            'auto_subscribe.duration' => 'nullable|string|max:30',
        ]);

        $provider = strtolower(trim((string) $validated['provider']));
        if ($provider === 'cybersource') {
            return response()->json([
                'message' => 'CyberSource remains a legacy coexistence flow and is not part of the wallet billing API.',
                'error_code' => 'provider_not_supported',
            ], 422);
        }

        try {
            $result = $this->billingGatewayService->initiateTopup($client, $provider, (float) $validated['amount'], [
                'idempotency_key' => (string) $request->attributes->get('wallet_idempotency_key'),
                'phone' => $validated['phone'] ?? null,
                'auto_subscribe' => $validated['auto_subscribe'] ?? null,
            ], $request);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => $provider === 'cybersource'
                    ? 'provider_not_supported'
                    : (str_contains(strtolower($exception->getMessage()), 'disabled') ? 'invalid_mode' : 'billing_initiate_failed'),
            ], 422);
        }

        /** @var Payment $payment */
        $payment = $result['payment'];

        return response()->json([
            'message' => $result['replayed']
                ? 'Billing initiation already exists for this idempotency key.'
                : 'Billing initiation created.',
            'replayed' => (bool) $result['replayed'],
            'mode' => $context['mode'],
            'provider' => $provider,
            'payment' => $this->billingGatewayService->paymentPayload($payment),
            'action' => $result['action'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function retryStk(Request $request)
    {
        [$client] = $this->resolveWalletClient($request);
        $validated = $request->validate([
            'payment_id' => 'required|integer|exists:payments,id',
            'phone' => 'nullable|string|max:30',
        ]);

        $payment = Payment::query()
            ->where('id', (int) $validated['payment_id'])
            ->where('client_id', (int) $client->id)
            ->where('platform_id', (int) $client->platform_id)
            ->firstOrFail();

        try {
            $result = $this->billingGatewayService->retryMpesaTopup($payment, [
                'phone' => $validated['phone'] ?? null,
            ], $request);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => 'stk_retry_failed',
            ], 422);
        }

        return response()->json([
            'message' => 'STK retry dispatched.',
            'payment' => $this->billingGatewayService->paymentPayload($result['payment']),
            'action' => $result['action'],
        ]);
    }

    public function paystackWebhook(Request $request)
    {
        $rawBody = (string) $request->getContent();
        $payload = $request->json()->all();
        $signature = trim((string) $request->header('X-Paystack-Signature', ''));

        try {
            $result = $this->billingGatewayService->handlePaystackWebhook($rawBody, $payload, $signature);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => 'webhook_verification_failed',
            ], 401);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => 'webhook_invalid_payload',
            ], 422);
        }

        return response()->json([
            'message' => 'Paystack webhook processed.',
            'status' => $result['status'],
            'payment' => $this->billingGatewayService->paymentPayload($result['payment']),
        ]);
    }

    public function pesapalIpn(Request $request)
    {
        $payload = array_merge($request->query(), $request->all());

        try {
            $result = $this->billingGatewayService->handlePesapalIpn($payload);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => 'webhook_verification_failed',
            ], 401);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => 'webhook_invalid_payload',
            ], 422);
        }

        return response()->json([
            'message' => 'Pesapal IPN processed.',
            'status' => $result['status'],
            'payment' => $this->billingGatewayService->paymentPayload($result['payment']),
        ]);
    }

    public function mpesaCallback(Request $request)
    {
        $rawBody = (string) $request->getContent();
        $signature = trim((string) $request->header('X-KopoKopo-Signature', ''));

        try {
            $result = $this->billingGatewayService->handleMpesaCallback($rawBody, $signature);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => 'webhook_verification_failed',
            ], 401);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => 'webhook_invalid_payload',
            ], 422);
        }

        return response()->json([
            'message' => 'M-Pesa callback processed.',
            'status' => $result['status'],
            'payment' => $this->billingGatewayService->paymentPayload($result['payment']),
        ]);
    }

    public function complete(Request $request)
    {
        $payment = $this->resolveBrowserPayment(
            $request->query('payment')
                ?? $request->query('reference')
                ?? $request->query('OrderMerchantReference')
                ?? $request->query('OrderTrackingId')
        );

        $redirectUrl = null;
        $autoRedirectUrl = null;
        $manualProfileUrl = null;
        $returnUrlSuppressed = false;
        $redirectDelay = 3;
        $mode = 'sandbox';
        $statusLabel = 'processing';
        $crmPaymentsUrl = rtrim((string) config('app.url', url('/')), '/') . '/payments';
        $providerStatusUrl = $crmPaymentsUrl;
        $testMode = false;

        if ($payment) {
            $payment->loadMissing(['client.platform', 'platform']);
            $context = $this->billingModeService->walletContext($payment->platform);
            $redirectDelay = (int) data_get($context, 'system.redirect_delay_seconds', 3);
            $mode = (string) ($payment->provider_environment ?: ($context['environment'] ?? 'sandbox'));
            $testMode = (bool) data_get($payment->payment_data, 'test_mode', false);
            $statusLabel = (string) ($payment->status ?: 'processing');
            if ($mode === 'sandbox' && $testMode) {
                $statusLabel = 'sandbox_' . ((string) data_get($payment->payment_data, 'test_result', $statusLabel));
            }

            $redirectUrl = $this->paymentReturnUrl($payment);
            $returnUrlIsPublic = $this->isPublicReturnUrl($redirectUrl);
            $manualProfileUrl = $returnUrlIsPublic ? $redirectUrl : null;
            $autoRedirectUrl = $mode !== 'sandbox' && $returnUrlIsPublic ? $redirectUrl : null;
            $returnUrlSuppressed = $redirectUrl !== null && !$returnUrlIsPublic;
            $providerStatusUrl = $crmPaymentsUrl . '?payment=' . (int) $payment->id . '&intent=provider-status';
        }

        return response()->view('payments.complete', [
            'payment' => $payment,
            'redirect_url' => $manualProfileUrl,
            'auto_redirect_url' => $autoRedirectUrl,
            'redirect_delay_seconds' => $redirectDelay,
            'mode' => $mode,
            'status_label' => $statusLabel,
            'crm_payments_url' => $crmPaymentsUrl,
            'provider_status_url' => $providerStatusUrl,
            'return_url_suppressed' => $returnUrlSuppressed,
            'test_mode' => $testMode,
        ])->withHeaders([
            'Cache-Control' => 'no-store',
        ]);
    }

    public function health(Request $request)
    {
        return response()->json([
            'ok' => true,
            'service' => 'billing',
            'app' => config('app.name', 'ExoticCRM'),
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
            'host' => $request->getHost(),
        ]);
    }

    private function resolveWalletClient(Request $request): array
    {
        /** @var Platform $platform */
        $platform = $request->attributes->get('wallet_platform');
        $context = $request->attributes->get('wallet_context', []);

        $validated = $request->validate([
            'wp_user_id' => 'nullable|integer|min:1',
            'wp_post_id' => 'nullable|integer|min:1',
        ]);

        $query = Client::query()
            ->with('platform')
            ->where('platform_id', (int) $platform->id);

        if (!empty($validated['wp_user_id'])) {
            $query->where('wp_user_id', (int) $validated['wp_user_id']);
        } elseif (!empty($validated['wp_post_id'])) {
            $query->where('wp_post_id', (int) $validated['wp_post_id']);
        } else {
            throw ValidationException::withMessages([
                'wp_user_id' => 'Either wp_user_id or wp_post_id is required.',
            ]);
        }

        $client = $query->latest('id')->firstOrFail();

        return [$client, $platform, $context];
    }

    private function resolveBrowserPayment(mixed $identifier): ?Payment
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $query = Payment::query();

        if (is_numeric($identifier)) {
            $payment = (clone $query)->find((int) $identifier);
            if ($payment) {
                return $payment;
            }
        }

        $identifier = (string) $identifier;

        return $query
            ->where(function ($builder) use ($identifier) {
                $builder->where('transaction_uuid', $identifier)
                    ->orWhere('reference_number', $identifier)
                    ->orWhere('transaction_reference', $identifier);
            })
            ->first();
    }

    private function paymentReturnUrl(Payment $payment): ?string
    {
        $profileUrl = $payment->client?->wp_profile_url;
        if (!$profileUrl) {
            return null;
        }

        if ((string) $payment->purpose !== 'wallet_topup') {
            return $profileUrl;
        }

        $separator = str_contains($profileUrl, '?') ? '&' : '?';

        return $profileUrl . $separator . http_build_query([
            'wallet_refresh' => 1,
            'wallet_payment_status' => $payment->status,
            'wallet_payment_id' => $payment->id,
        ]);
    }

    private function isPublicReturnUrl(?string $url): bool
    {
        if (!$url) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        $normalizedHost = trim($host, '[]');
        if (filter_var($normalizedHost, FILTER_VALIDATE_IP)) {
            return (bool) filter_var(
                $normalizedHost,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        return str_contains($host, '.');
    }
}
