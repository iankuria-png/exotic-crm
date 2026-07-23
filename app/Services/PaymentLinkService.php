<?php

namespace App\Services;

use App\Billing\Support\BillingProxyLifecycleService;
use App\Billing\Support\BillingRoutingDecisionRecorder;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\Messaging\DispatchResult;
use App\Services\Messaging\MessageRecipient;
use App\Services\Messaging\MessagingDispatcher;
use App\Support\CrmAuditAction;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;

class PaymentLinkService
{
    public const MODE_STATIC_URL = 'static_url';
    public const MODE_PROXY_HOSTED_CHECKOUT = 'proxy_hosted_checkout';

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly AuditService $auditService,
        private readonly BillingModeService $billingModeService,
        private readonly WalletSettingsService $walletSettingsService,
        private readonly BillingRoutingDecisionRecorder $billingRoutingDecisionRecorder,
        private readonly BillingProxyLifecycleService $billingProxyLifecycleService,
        private readonly MessagingDispatcher $messagingDispatcher
    ) {
    }

    public function resolveUrl(?Platform $platform, ?string $requestedProvider = null): ?string
    {
        if (!$platform) {
            return null;
        }

        $resolvedProvider = $this->resolveProviderConfig($platform, $requestedProvider);
        if (is_array($resolvedProvider)) {
            $mode = (string) ($resolvedProvider['config']['mode'] ?? self::MODE_STATIC_URL);
            if ($mode === self::MODE_PROXY_HOSTED_CHECKOUT) {
                return null;
            }

            return $this->buildStaticUrl($platform, $resolvedProvider['config']);
        }

        return $this->buildStaticUrl($platform);
    }

    public function resolveProviderConfig(?Platform $platform, ?string $requestedProvider = null): ?array
    {
        $paymentLinkProviders = $platform ? $this->walletSettingsService->currentPaymentLinkProviders($platform) : null;

        if (!$platform || !is_array($paymentLinkProviders)) {
            return null;
        }

        $configuredProvider = trim((string) ($paymentLinkProviders['active_provider'] ?? ''));
        $activeProvider = trim((string) ($requestedProvider ?: $configuredProvider));
        $providers = $paymentLinkProviders['providers'] ?? [];

        if ($activeProvider === '' || !is_array($providers) || !isset($providers[$activeProvider]) || !is_array($providers[$activeProvider])) {
            return null;
        }

        $provider = $providers[$activeProvider];
        $enabled = array_key_exists('enabled', $provider) ? (bool) $provider['enabled'] : true;
        if (!$enabled) {
            return null;
        }

        return [
            'key' => $activeProvider,
            'config' => array_merge($provider, [
                'mode' => trim((string) ($provider['mode'] ?? self::MODE_STATIC_URL)) ?: self::MODE_STATIC_URL,
                'enabled' => $enabled,
            ]),
        ];
    }

    public function generateProxyToken(Payment $payment, array $providerConfig): string
    {
        return $this->storeProxyToken($payment, $providerConfig);
    }

    public function rotateProxyToken(Payment $payment, array $providerConfig): string
    {
        return $this->storeProxyToken($payment, $providerConfig);
    }

    /**
     * Resolve a TOKENIZED hosted-checkout URL for a payment without dispatching
     * any message. Used by the lifecycle SMS engine, which embeds the link in
     * its own template copy. Static pay pages are deliberately NOT supported
     * here — a market without a tokenized provider skips the link-bearing send.
     */
    public function prepareTokenizedUrl(Payment $payment, array $options = []): array
    {
        $payment->loadMissing(['platform']);
        $platform = $payment->platform;

        if (!$platform) {
            return ['success' => false, 'skip_reason' => 'missing_platform'];
        }

        $requestedProvider = isset($options['provider']) ? trim((string) $options['provider']) : null;
        $resolvedProvider = $this->resolveProviderConfig($platform, $requestedProvider);

        if (!is_array($resolvedProvider)) {
            return ['success' => false, 'skip_reason' => 'market_no_psp'];
        }

        $mode = (string) ($resolvedProvider['config']['mode'] ?? self::MODE_STATIC_URL);
        if ($mode !== self::MODE_PROXY_HOSTED_CHECKOUT) {
            return ['success' => false, 'skip_reason' => 'market_no_psp'];
        }

        $token = $this->rotateProxyToken($payment, array_merge($resolvedProvider['config'], [
            'key' => $resolvedProvider['key'],
        ]));
        $environment = (string) ($resolvedProvider['config']['environment'] ?? 'sandbox');
        $paymentUrl = $this->billingModeService->buildAbsoluteUrl(
            $platform,
            '/api/payments/link/' . $token,
            [],
            $environment
        );

        if (!$paymentUrl) {
            return ['success' => false, 'skip_reason' => 'link_url_unresolved'];
        }

        $this->billingRoutingDecisionRecorder->recordPaymentLink($payment, $resolvedProvider, $paymentUrl, [
            'requested_provider' => $requestedProvider,
            'notification_purpose' => $options['notification_purpose'] ?? 'lifecycle_payment_link',
        ]);

        return [
            'success' => true,
            'payment_url' => $paymentUrl,
            'provider' => $resolvedProvider['key'],
            'mode' => $mode,
        ];
    }

    /**
     * Whether the market has an enabled TOKENIZED (hosted-checkout) payment-link
     * provider — the lifecycle "payment provider ready" capability gate.
     */
    public function hasTokenizedProvider(?Platform $platform): bool
    {
        $resolved = $this->resolveProviderConfig($platform);

        return is_array($resolved)
            && (string) ($resolved['config']['mode'] ?? self::MODE_STATIC_URL) === self::MODE_PROXY_HOSTED_CHECKOUT;
    }

    public function sendLink(Payment $payment, array $options = []): array
    {
        $payment->loadMissing(['platform', 'product', 'client']);
        $platform = $payment->platform;

        if (!$platform) {
            return [
                'success' => false,
                'http_status' => 422,
                'message' => 'Payment has no platform.',
            ];
        }

        $channel = strtolower(trim((string) ($options['channel'] ?? 'sms')));
        if ($channel === '') {
            $channel = 'sms';
        }

        $requestedProvider = isset($options['provider']) ? trim((string) $options['provider']) : null;
        $phone = PhoneNormalizer::normalize(
            $options['phone'] ?? $payment->phone,
            (string) ($platform->phone_prefix ?: '254')
        );

        if (!$phone) {
            return [
                'success' => false,
                'http_status' => 422,
                'message' => 'No valid phone number to send the link to.',
            ];
        }

        $resolvedProvider = $this->resolveProviderConfig($platform, $requestedProvider);
        $providerMode = (string) ($resolvedProvider['config']['mode'] ?? self::MODE_STATIC_URL);
        $providerKey = $resolvedProvider['key'] ?? $requestedProvider;

        if ($providerMode === self::MODE_PROXY_HOSTED_CHECKOUT && is_array($resolvedProvider)) {
            $token = $this->rotateProxyToken($payment, array_merge($resolvedProvider['config'], [
                'key' => $resolvedProvider['key'],
            ]));
            $environment = (string) ($resolvedProvider['config']['environment'] ?? 'sandbox');
            $paymentUrl = $this->billingModeService->buildAbsoluteUrl(
                $platform,
                '/api/payments/link/' . $token,
                [],
                $environment
            );
        } else {
            $paymentUrl = $this->resolveUrl($platform, $requestedProvider);
        }

        if (!$paymentUrl) {
            return [
                'success' => false,
                'http_status' => 422,
                'message' => 'Payment page URL could not be determined for this market.',
            ];
        }

        if (is_array($resolvedProvider)) {
            $this->billingRoutingDecisionRecorder->recordPaymentLink($payment, $resolvedProvider, $paymentUrl, [
                'requested_provider' => $requestedProvider,
                'notification_purpose' => $options['notification_purpose'] ?? 'payment_link',
            ]);
        }

        $currency = $payment->currency ?: ($platform->currency_code ?: 'KES');
        $message = sprintf(
            'Complete your payment of %s %s here: %s',
            $currency,
            number_format((float) $payment->amount),
            $paymentUrl
        );

        $request = $options['request'] ?? null;
        $requestMetaExtra = array_filter([
            'channel' => $channel,
            'requested_provider' => $providerKey,
            'phone' => $phone,
        ], static fn($value) => $value !== null && $value !== '');
        $requestMeta = $request instanceof Request
            ? $this->paymentAttemptService->requestMetaFromRequest($request, $requestMetaExtra)
            : $requestMetaExtra;

        $attemptStartedAt = microtime(true);
        $notificationContext = array_merge([
            'purpose' => (string) ($options['notification_purpose'] ?? 'payment_link'),
            'payment_id' => $payment->id,
            'platform_id' => $payment->platform_id,
            'phone_prefix' => $platform->phone_prefix ?: '254',
        ], is_array($options['notification_context'] ?? null) ? $options['notification_context'] : []);
        $result = $this->dispatchPaymentLinkMessage($payment, $phone, $message, $channel, $notificationContext, $options);
        $latencyMs = (int) round((microtime(true) - $attemptStartedAt) * 1000);

        $attemptStatus = ($result['success'] ?? false) === true
            ? (($result['status'] ?? '') === 'disabled' ? 'disabled' : 'success')
            : 'failed';
        $providerForAttempt = $result['provider'] ?? ($providerKey ?: 'payment_link');
        $failedErrorCode = $channel === 'whatsapp'
            ? (string) ($result['error_code'] ?? 'whatsapp_send_failed')
            : 'sms_send_failed';

        $this->paymentAttemptService->record(
            $payment,
            (string) ($options['attempt_type'] ?? 'send_payment_link'),
            $attemptStatus,
            [
                'provider' => $providerForAttempt,
                'error_code' => ($result['success'] ?? false) === true ? null : $failedErrorCode,
                'error_message' => ($result['success'] ?? false) === true ? null : ($result['provider_response'] ?? 'Message could not be sent.'),
                'latency_ms' => $latencyMs,
                'request_meta' => $requestMeta,
                'response_meta' => [
                    'message_status' => $result['status'] ?? null,
                    'channel' => $channel,
                    'delivered_channel' => $result['channel'] ?? $channel,
                    'whatsapp_message_id' => $result['whatsapp_message_id'] ?? null,
                    'fallback_attempted' => $result['fallback_attempted'] ?? false,
                    'sms_status' => $channel === 'sms' || ($result['channel'] ?? null) === 'sms' ? ($result['status'] ?? null) : null,
                    'provider_response' => $result['provider_response'] ?? null,
                    'payment_url' => $paymentUrl,
                ],
                'created_by' => $request instanceof Request ? optional($request->user())->id : ($options['actor_id'] ?? null),
            ]
        );

        $beforeState = [
            'channel' => $channel,
            'phone' => $phone,
            'provider' => $providerKey,
            'requested_provider' => $options['requested_provider'] ?? null,
        ];
        $afterState = [
            'message_success' => $result['success'] ?? false,
            'message_status' => $result['status'] ?? null,
            'channel' => $channel,
            'delivered_channel' => $result['channel'] ?? $channel,
            'whatsapp_message_id' => $result['whatsapp_message_id'] ?? null,
            'fallback_attempted' => $result['fallback_attempted'] ?? false,
            'provider' => $providerKey,
            'mode' => $providerMode,
            'provider_override_requested' => (bool) ($options['provider_override_requested'] ?? false),
            'provider_override_applied' => (bool) ($options['provider_override_applied'] ?? false),
            'provider_override_denied' => (bool) ($options['provider_override_denied'] ?? false),
            'provider_override_actor_role' => $options['provider_override_actor_role'] ?? null,
        ];
        $reason = (string) ($options['reason'] ?? 'Send payment link from CRM');

        if ($request instanceof Request) {
            $this->auditService->fromRequest(
                $request,
                (int) $payment->platform_id,
                CrmAuditAction::PAYMENT_SEND_LINK,
                'payment',
                (int) $payment->id,
                $beforeState,
                $afterState,
                $reason
            );
        } else {
            $this->auditService->record([
                'platform_id' => (int) $payment->platform_id,
                'actor_id' => $options['actor_id'] ?? null,
                'action' => CrmAuditAction::PAYMENT_SEND_LINK,
                'entity_type' => 'payment',
                'entity_id' => (int) $payment->id,
                'before_state' => $beforeState,
                'after_state' => $afterState,
                'reason' => $reason,
                'ip_address' => $options['ip_address'] ?? null,
            ]);
        }

        if (($result['success'] ?? false) !== true && ($result['status'] ?? '') !== 'disabled') {
            return [
                'success' => false,
                'http_status' => 502,
                'message' => $result['provider_response'] ?? ((string) ($options['failure_message'] ?? 'Message could not be sent.')),
                'payment_url' => $paymentUrl,
                'phone' => $phone,
                'notification_result' => $result,
                'request_meta' => $requestMeta,
                'latency_ms' => $latencyMs,
            ];
        }

        return [
            'success' => true,
            'http_status' => 200,
            'message' => ($result['status'] ?? '') === 'disabled'
                ? (string) ($options['disabled_message'] ?? 'Payment link prepared (SMS disabled).')
                : (string) ($options['success_message'] ?? ($channel === 'whatsapp' ? 'Payment link sent by WhatsApp.' : 'Payment link sent by SMS.')),
            'payment_url' => $paymentUrl,
            'phone' => $phone,
            'notification_result' => $result,
            'request_meta' => $requestMeta,
            'latency_ms' => $latencyMs,
        ];
    }

    private function dispatchPaymentLinkMessage(Payment $payment, string $phone, string $message, string $channel, array $notificationContext, array $options): array
    {
        if ($channel !== 'whatsapp') {
            return $this->notificationService->sendSms($phone, $message, $notificationContext);
        }

        $recipient = $payment->client
            ? MessageRecipient::fromClient($payment->client)->withPaymentId((int) $payment->id)
            : MessageRecipient::fromPhone($phone, (int) $payment->platform_id, (string) ($payment->platform?->phone_prefix ?: '254'))->withPaymentId((int) $payment->id);
        $request = $options['request'] ?? null;
        $actorId = $request instanceof Request ? optional($request->user())->id : ($options['actor_id'] ?? null);

        $dispatch = $this->messagingDispatcher->dispatch($recipient, $message, 'whatsapp_with_sms_fallback', array_merge($notificationContext, [
            'message_type' => 'payment_link',
            'actor_id' => $actorId,
            'idempotency_key' => 'payment-link-' . $payment->id . '-' . sha1($phone . '|' . $message . '|' . microtime(true)),
        ]));

        return $this->serializeDispatchResult($dispatch);
    }

    private function serializeDispatchResult(DispatchResult $dispatch): array
    {
        return [
            'success' => $dispatch->success,
            'status' => $dispatch->status,
            'provider' => $dispatch->channel,
            'channel' => $dispatch->channel,
            'provider_response' => $dispatch->smsResult['provider_response']
                ?? $dispatch->errorMessage
                ?? ($dispatch->success ? 'Message accepted by provider.' : 'Message could not be sent.'),
            'whatsapp_message_id' => $dispatch->whatsAppMessage?->id,
            'fallback_attempted' => $dispatch->fallbackAttempted,
            'error_code' => $dispatch->errorCode,
        ];
    }

    private function buildStaticUrl(Platform $platform, ?array $provider = null): ?string
    {
        if (is_array($provider)) {
            $directUrl = rtrim(trim((string) ($provider['url'] ?? '')), '/');
            if ($directUrl !== '') {
                return $directUrl;
            }

            $baseUrl = rtrim(trim((string) ($provider['base_url'] ?? '')), '/');
            if ($baseUrl !== '') {
                $path = trim((string) ($provider['path'] ?? config('services.payment_link.path', '/pay')));
                if ($path === '') {
                    $path = '/pay';
                }
                if (!str_starts_with($path, '/')) {
                    $path = '/' . $path;
                }

                return $baseUrl . $path;
            }
        }

        $baseUrl = null;

        if (!empty($platform->wp_api_url)) {
            $baseUrl = preg_replace('#/wp-json/.*$#', '', (string) $platform->wp_api_url);
            $baseUrl = rtrim((string) $baseUrl, '/');
        }

        if (!$baseUrl && !empty($platform->domain)) {
            $domain = trim((string) $platform->domain);
            $baseUrl = str_starts_with($domain, 'http') ? $domain : 'https://' . $domain;
            $baseUrl = rtrim($baseUrl, '/');
        }

        if ($baseUrl === '' || $baseUrl === null) {
            return null;
        }

        $path = config('services.payment_link.path', '/pay');

        return $baseUrl . $path;
    }

    private function storeProxyToken(Payment $payment, array $providerConfig): string
    {
        return $this->billingProxyLifecycleService->issueToken($payment, $providerConfig);
    }
}
