<?php

namespace App\Services;

use App\Billing\Support\BillingSurface;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\VisitorContactUnlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContactUnlockCheckoutService
{
    public function __construct(
        private readonly ContactUnlockPricingService $pricingService,
        private readonly BillingGatewayService $billingGatewayService
    ) {}

    public function createIntent(
        Platform $platform,
        int $wpPostId,
        int $pricingRuleId,
        string $providerKey,
        string $visitorPhone,
        ?string $visitorEmail,
        string $sessionProof,
        string $idempotencyKey,
        array $visitorContext = [],
        ?Request $request = null
    ): array {
        if (! $this->pricingService->enabledForPlatform($platform)) {
            throw ValidationException::withMessages(['platform' => 'Contact unlock is disabled for this market.']);
        }

        $client = Client::query()
            ->where('platform_id', (int) $platform->id)
            ->where('wp_post_id', $wpPostId)
            ->firstOrFail();

        if (! $client->isPubliclyRestricted()) {
            throw ValidationException::withMessages(['wp_post_id' => 'This profile is not currently inactive.']);
        }

        $rule = $this->pricingService->findActiveRule($platform, $pricingRuleId);
        $providerKey = strtolower(trim($providerKey));
        $providerKeys = collect($this->pricingService->providerOptions($platform))->pluck('key')->all();
        if (! in_array($providerKey, $providerKeys, true)) {
            throw ValidationException::withMessages(['provider_key' => 'This payment provider is not available for this market.']);
        }

        $normalizedPhone = $this->normalizePhone($visitorPhone, (string) ($platform->phone_prefix ?? ''));
        if ($normalizedPhone === '') {
            throw ValidationException::withMessages(['visitor_phone' => 'A valid mobile money phone number is required.']);
        }

        $idempotencyHash = $this->hashToken($idempotencyKey);
        $sessionHash = $this->hashToken($sessionProof);
        $email = trim((string) $visitorEmail);
        $checkoutEnvironment = $this->pricingService->checkoutEnvironment();

        $created = false;
        $intent = DB::transaction(function () use (
            $platform,
            $client,
            $rule,
            $providerKey,
            $normalizedPhone,
            $email,
            $idempotencyKey,
            $idempotencyHash,
            $sessionHash,
            $checkoutEnvironment,
            $visitorContext,
            $request,
            &$created
        ): VisitorContactUnlock {
            $existing = VisitorContactUnlock::query()
                ->where('idempotency_key_hash', $idempotencyHash)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh(['payment', 'client', 'pricingRule']);
            }

            $publicToken = $this->derivePublicToken($idempotencyKey, $sessionHash);
            $scope = (string) $rule->scope;
            $singleProfile = $scope === VisitorContactUnlock::SCOPE_SINGLE_PROFILE;
            $reference = 'CU-'.now()->format('ymd').'-'.strtoupper(Str::random(8));

            $payment = Payment::query()->create([
                'user_id' => (int) $client->wp_user_id,
                'platform_id' => (int) $platform->id,
                'escort_post_id' => $singleProfile ? (int) $client->wp_post_id : null,
                'client_id' => $singleProfile ? (int) $client->id : null,
                'phone' => $normalizedPhone,
                'amount' => number_format((float) $rule->amount, 2, '.', ''),
                'currency' => strtoupper((string) $rule->currency),
                'transaction_uuid' => (string) Str::uuid(),
                'transaction_reference' => $reference,
                'reference_number' => $reference,
                'status' => 'initiated',
                'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
                'source' => 'website_unlock',
                'provider_key' => $providerKey,
                'provider_environment' => $checkoutEnvironment,
                'raw_payload' => [
                    'method' => $providerKey,
                    'billing_surface' => BillingSurface::ContactUnlock->value,
                    'pii' => 'visitor phone retained only for provider compatibility',
                ],
                'payment_data' => [
                    'billing_surface' => BillingSurface::ContactUnlock->value,
                    'unlock_scope' => $scope,
                    'wp_post_id' => (int) $client->wp_post_id,
                    'client_id' => (int) $client->id,
                    'idempotency_key_hash' => $idempotencyHash,
                    'pricing_rule_id' => (int) $rule->id,
                ],
            ]);

            $unlock = VisitorContactUnlock::query()->create([
                'platform_id' => (int) $platform->id,
                'client_id' => $singleProfile ? (int) $client->id : null,
                'wp_post_id' => $singleProfile ? (int) $client->wp_post_id : null,
                'payment_id' => (int) $payment->id,
                'pricing_rule_id' => (int) $rule->id,
                'scope' => $scope,
                'status' => VisitorContactUnlock::STATUS_INITIATED,
                'visitor_phone_hash' => $this->hashToken($normalizedPhone),
                'visitor_phone_masked' => $this->maskPhone($normalizedPhone),
                'visitor_email_hash' => $email !== '' ? $this->hashToken(strtolower($email)) : null,
                'visitor_email_masked' => $email !== '' ? $this->maskEmail($email) : null,
                'idempotency_key_hash' => $idempotencyHash,
                'session_token_hash' => $sessionHash,
                'public_token_hash' => $this->hashToken($publicToken),
                'metadata_json' => [
                    'origin_wp_post_id' => (int) $client->wp_post_id,
                    'idempotency_key_hash' => $idempotencyHash,
                    'visitor_context' => $this->visitorContext($visitorContext, $request),
                ],
            ]);

            $payment->forceFill([
                'payment_data' => array_merge($payment->payment_data ?? [], [
                    'unlock_id' => (int) $unlock->id,
                ]),
            ])->save();

            $created = true;

            return $unlock->fresh(['payment', 'client', 'pricingRule']);
        });

        $payment = $intent->payment;
        $metadata = is_array($intent->metadata_json) ? $intent->metadata_json : [];
        $publicToken = $this->derivePublicToken($idempotencyKey, $sessionHash);

        if ($created && $payment) {
            try {
                $action = $this->billingGatewayService->initiateContactUnlock($payment, $providerKey, [
                    'phone' => $normalizedPhone,
                    'idempotency_key' => $idempotencyKey,
                    'description' => 'Contact unlock',
                    'environment' => $checkoutEnvironment,
                ], $request);

                $intent->forceFill([
                    'status' => VisitorContactUnlock::STATUS_PENDING_PAYMENT,
                    'metadata_json' => array_merge($metadata, [
                        'provider_action' => $this->publicAction($action),
                    ]),
                ])->save();
            } catch (Throwable $exception) {
                $reference = 'cu_'.strtolower(Str::random(10));
                $message = $this->publicCheckoutFailureMessage($exception, $providerKey, $checkoutEnvironment, $reference);
                $errorPayload = [
                    'reference' => $reference,
                    'provider_key' => $providerKey,
                    'provider_environment' => $checkoutEnvironment ?: 'billing_default',
                    'message' => $message,
                    'exception' => class_basename($exception),
                ];

                Log::warning('contact_unlock.checkout_failed', [
                    'reference' => $reference,
                    'platform_id' => (int) $platform->id,
                    'unlock_id' => (int) $intent->id,
                    'payment_id' => (int) $payment->id,
                    'provider_key' => $providerKey,
                    'provider_environment' => $checkoutEnvironment ?: 'billing_default',
                    'exception' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                ]);

                $intent->forceFill([
                    'status' => VisitorContactUnlock::STATUS_FAILED,
                    'metadata_json' => array_merge($metadata, [
                        'checkout_error' => $errorPayload,
                    ]),
                ])->save();

                $payment->forceFill([
                    'status' => 'failed',
                    'failure_reason' => $message,
                    'payment_data' => array_merge($payment->payment_data ?? [], [
                        'checkout_error' => $errorPayload,
                    ]),
                ])->save();

                throw ValidationException::withMessages([
                    'provider_key' => $message,
                ]);
            }
        }

        $fresh = $intent->fresh(['payment', 'client', 'pricingRule']);
        $freshMetadata = is_array($fresh->metadata_json) ? $fresh->metadata_json : [];

        return [
            'unlock_reference' => (int) $fresh->id,
            'public_token' => $publicToken,
            'status' => (string) $fresh->status,
            'replayed' => ! $created,
            'payment' => [
                'id' => (int) ($fresh->payment?->id ?? 0),
                'reference' => (string) ($fresh->payment?->reference_number ?? ''),
                'status' => (string) ($fresh->payment?->status ?? ''),
                'amount' => (float) ($fresh->payment?->amount ?? 0),
                'currency' => (string) ($fresh->payment?->currency ?? ''),
            ],
            'action' => $freshMetadata['provider_action'] ?? data_get($fresh->payment?->payment_data, 'resume'),
            'expires_at' => $fresh->expires_at?->toIso8601String(),
        ];
    }

    private function publicAction(array $action): array
    {
        unset($action['provider_payload']);

        return $action;
    }

    private function publicCheckoutFailureMessage(Throwable $exception, string $providerKey, ?string $environment, string $reference): string
    {
        $rawMessage = strtolower($exception->getMessage());
        $environmentLabel = $environment === 'sandbox' ? 'sandbox' : 'production/default';
        $providerLabel = strtoupper(str_replace('_', ' ', $providerKey));

        if (
            str_contains($rawMessage, 'disabled')
            || str_contains($rawMessage, 'credential')
            || str_contains($rawMessage, 'provider')
            || str_contains($rawMessage, 'routing')
        ) {
            return sprintf(
                'Contact unlock checkout is not configured for %s in %s mode. Check the market provider profile and routing in CRM. Reference %s.',
                $providerLabel,
                $environmentLabel,
                $reference
            );
        }

        return sprintf('Contact unlock checkout could not be started. Reference %s.', $reference);
    }

    private function normalizePhone(string $phone, string $prefix): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $prefix = preg_replace('/\D+/', '', $prefix);

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0') && $prefix !== '') {
            $digits = $prefix.substr($digits, 1);
        }

        return strlen($digits) >= 8 ? $digits : '';
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) <= 4 ? '****' : substr($phone, 0, 3).str_repeat('*', max(2, strlen($phone) - 6)).substr($phone, -3);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($local, 0, 1).'***@'.$domain;
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function visitorContext(array $context, ?Request $request): array
    {
        $browser = is_array($context['browser'] ?? null) ? $context['browser'] : $context;
        $requestContext = is_array($context['request'] ?? null) ? $context['request'] : [];
        $ipAddress = trim((string) ($requestContext['ip_address'] ?? $request?->ip() ?? ''));

        return [
            'browser' => [
                'locale' => $this->stringValue($browser['locale'] ?? ''),
                'languages' => $this->stringList($browser['languages'] ?? []),
                'timezone' => $this->stringValue($browser['timezone'] ?? ''),
                'timezone_offset_minutes' => $this->integerValue($browser['timezone_offset_minutes'] ?? null),
                'platform' => $this->stringValue($browser['platform'] ?? ''),
                'user_agent_platform' => $this->stringValue($browser['user_agent_platform'] ?? ''),
                'mobile_hint' => is_bool($browser['mobile_hint'] ?? null) ? (bool) $browser['mobile_hint'] : null,
                'brands' => $this->browserBrands($browser['brands'] ?? []),
                'viewport' => [
                    'width' => $this->integerValue(data_get($browser, 'viewport.width')),
                    'height' => $this->integerValue(data_get($browser, 'viewport.height')),
                    'pixel_ratio' => $this->numericValue(data_get($browser, 'viewport.pixel_ratio')),
                ],
                'screen' => [
                    'width' => $this->integerValue(data_get($browser, 'screen.width')),
                    'height' => $this->integerValue(data_get($browser, 'screen.height')),
                    'color_depth' => $this->integerValue(data_get($browser, 'screen.color_depth')),
                ],
                'device' => [
                    'max_touch_points' => $this->integerValue(data_get($browser, 'device.max_touch_points')),
                    'hardware_concurrency' => $this->integerValue(data_get($browser, 'device.hardware_concurrency')),
                    'device_memory_gb' => $this->numericValue(data_get($browser, 'device.device_memory_gb')),
                ],
                'current_path' => $this->stringValue($browser['current_path'] ?? '', 180),
                'referrer_host' => $this->stringValue($browser['referrer_host'] ?? '', 120),
                'referrer_path' => $this->stringValue($browser['referrer_path'] ?? '', 180),
            ],
            'request' => [
                'ip_hash' => $ipAddress !== '' ? $this->hashToken($ipAddress) : null,
                'ip_masked' => $ipAddress !== '' ? $this->maskIp($ipAddress) : '',
                'user_agent' => $this->stringValue($requestContext['user_agent'] ?? $request?->userAgent() ?? '', 320),
                'accept_language' => $this->stringValue($requestContext['accept_language'] ?? $request?->header('Accept-Language', '') ?? '', 180),
            ],
        ];
    }

    private function stringValue(mixed $value, int $maxLength = 120): string
    {
        return Str::limit(trim((string) $value), $maxLength, '');
    }

    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => $this->stringValue($item, 40))
            ->filter()
            ->take(6)
            ->values()
            ->all();
    }

    private function browserBrands(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_array($item))
            ->map(fn ($item) => [
                'brand' => $this->stringValue($item['brand'] ?? '', 60),
                'version' => $this->stringValue($item['version'] ?? '', 20),
            ])
            ->filter(fn ($item) => $item['brand'] !== '')
            ->take(4)
            ->values()
            ->all();
    }

    private function integerValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function numericValue(mixed $value): int|float|null
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function maskIp(string $ipAddress): string
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);

            return sprintf('%s.%s.%s.*', $parts[0] ?? '*', $parts[1] ?? '*', $parts[2] ?? '*');
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ipAddress);

            return implode(':', array_slice($parts, 0, 3)).':*';
        }

        return '';
    }

    private function derivePublicToken(string $idempotencyKey, string $sessionHash): string
    {
        $raw = hash_hmac('sha256', $idempotencyKey.'|'.$sessionHash, (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
