<?php

namespace App\Http\Controllers\CRM;

use App\Billing\Support\BillingProxyLifecycleService;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\BillingModeService;
use App\Services\Routing\HostedCheckoutRoutingExecutor;
use App\Services\PaymentLinkService;
use Illuminate\Http\RedirectResponse;

class PaymentLinkProxyController extends Controller
{
    public function __construct(
        private readonly HostedCheckoutRoutingExecutor $hostedCheckoutExecutor,
        private readonly BillingModeService $billingModeService,
        private readonly BillingProxyLifecycleService $billingProxyLifecycleService
    ) {
    }

    public function handle(string $token): RedirectResponse|\Illuminate\Http\Response
    {
        $session = $this->billingProxyLifecycleService->findSessionByToken($token);
        $payment = $session?->payment;

        if (!$payment) {
            $tokenHash = hash('sha256', trim($token));
            $payment = Payment::query()
                ->whereNotNull('payment_data')
                ->orderByDesc('id')
                ->get()
                ->first(function (Payment $candidate) use ($tokenHash) {
                    return data_get($candidate->payment_data, 'link_proxy.token_hash') === $tokenHash;
                });
        }

        if (!$payment) {
            abort(404);
        }

        $payment->loadMissing(['platform', 'client']);
        $linkProxy = $this->billingProxyLifecycleService->currentLinkProxy($payment);
        $platform = $payment->platform;

        if (!$platform || !$linkProxy || (string) ($linkProxy['mode'] ?? '') !== PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT) {
            abort(404);
        }

        $expiresAt = data_get($linkProxy, 'token_expires_at');
        if ($expiresAt && now()->greaterThan(\Illuminate\Support\Carbon::parse((string) $expiresAt))) {
            return response('Payment link has expired.', 410);
        }

        $providerKey = trim((string) ($linkProxy['provider_key'] ?? ''));
        $environment = trim((string) ($linkProxy['environment'] ?? 'sandbox'));
        if ($providerKey === '') {
            abort(404);
        }

        $linkProxy = $this->billingProxyLifecycleService->markOpened($payment, $session, $linkProxy);

        $redirectUrl = trim((string) ($linkProxy['redirect_url'] ?? ''));
        if ($redirectUrl === '') {
            try {
                $context = $this->billingModeService->providerContext(
                    $platform,
                    $providerKey,
                    requireEnabled: false,
                    environmentOverride: $environment
                );
                $context['provider_key'] = $providerKey;
                
                $action = $this->hostedCheckoutExecutor->execute($payment, $context, [
                    'callback_url' => $this->billingModeService->buildAbsoluteUrl(
                        $platform,
                        '/billing/complete',
                        ['payment' => $payment->transaction_uuid],
                        $environment
                    ),
                    'metadata' => [
                        'channel' => 'payment_link',
                        'provider_config_key' => $linkProxy['provider_config_key'] ?? null,
                    ],
                    'description' => $payment->purpose === 'subscription'
                        ? 'Subscription payment'
                        : 'Payment link checkout',
                ]);
            } catch (\Throwable $exception) {
                return response($exception->getMessage(), 502);
            }

            if (!is_array($action) || trim((string) ($action['url'] ?? '')) === '') {
                return response('Hosted checkout could not be initialized.', 502);
            }

            $redirectUrl = trim((string) $action['url']);
            $providerReference = trim((string) ($action['provider_reference'] ?? ''));
            $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
            $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];

            $linkProxy = $this->billingProxyLifecycleService->markInitialized(
                $payment,
                $session,
                $linkProxy,
                $redirectUrl,
                $providerReference !== '' ? $providerReference : null
            );

            $payment->forceFill([
                'status' => 'pending',
                'provider_key' => $providerKey,
                'provider_environment' => $environment,
                'transaction_reference' => $providerReference !== '' ? $providerReference : $payment->transaction_reference,
                'raw_payload' => array_merge($rawPayload, [
                    $providerKey => $action['provider_payload'] ?? null,
                ]),
            ])->save();
        }

        return redirect()->away($redirectUrl);
    }
}
