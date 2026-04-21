<?php

namespace App\Services;

use App\Billing\Repositories\BillingConfigurationRepository;
use App\Models\BillingManualPaymentMethod;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentManualSubmission;
use App\Models\Platform;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ManualPaymentSubmissionService
{
    public function __construct(
        private readonly BillingConfigurationRepository $billingConfigurationRepository,
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService,
        private readonly SubscriptionLifecycleService $subscriptionLifecycleService
    ) {
    }

    /**
     * @return Collection<int, BillingManualPaymentMethod>
     */
    public function methodsForMarket(int $marketId, bool $enabledOnly = false): Collection
    {
        return $this->billingConfigurationRepository->manualPaymentMethodsForMarket($marketId, $enabledOnly);
    }

    public function methodForMarket(int $marketId, string $methodKey, bool $enabledOnly = false): ?BillingManualPaymentMethod
    {
        return $this->billingConfigurationRepository->manualPaymentMethodForMarket($marketId, $methodKey, $enabledOnly);
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    public function submit(
        Platform $platform,
        Product $product,
        Client $client,
        array $pricing,
        string $manualMethodKey,
        UploadedFile $proofImage,
        array $customer
    ): array {
        $method = $this->methodForMarket((int) $platform->id, $manualMethodKey, true);
        if (!$method) {
            throw new InvalidArgumentException('Manual payment is not configured for this market.');
        }

        $normalizedMethodKey = strtolower(trim((string) $manualMethodKey));
        $normalizedSenderName = trim((string) ($customer['sender_name'] ?? ''));
        $normalizedReference = trim((string) ($customer['transaction_reference'] ?? ''));

        if ($normalizedSenderName === '') {
            throw new InvalidArgumentException('Sender name is required.');
        }

        if ($normalizedReference === '') {
            throw new InvalidArgumentException('Transaction ID is required.');
        }

        $proofMeta = $this->validateProofImage($proofImage);
        $storedProof = null;

        try {
            $result = DB::transaction(function () use (
                $platform,
                $product,
                $client,
                $pricing,
                $customer,
                $method,
                $normalizedMethodKey,
                $normalizedSenderName,
                $normalizedReference,
                $proofImage,
                $proofMeta,
                &$storedProof
            ) {
                $duplicate = $this->findOpenDuplicateSubmission(
                    (int) $client->id,
                    (int) $platform->id,
                    (int) $product->id,
                    (string) ($pricing['duration_key'] ?? 'monthly')
                );

                if ($duplicate) {
                    return [
                        'duplicate' => true,
                        'payment' => $duplicate->payment,
                        'submission' => $duplicate,
                        'deal' => $duplicate->payment?->deal,
                    ];
                }

                $storedProof = $this->storeProofImage($proofImage, (int) $platform->id, $proofMeta['extension']);
                $transactionUuid = (string) Str::uuid();
                $internalReference = $this->subscriptionReference(
                    (int) $platform->id,
                    (int) ($client->wp_user_id ?? 0),
                    (int) $product->id,
                    (string) ($pricing['duration_key'] ?? 'monthly'),
                    $transactionUuid
                );
                $activatedOnSubmit = (bool) $method->auto_activate_on_submission;
                $lifecycle = $this->subscriptionLifecycleService->resolveForClient(
                    $client,
                    (int) $platform->id
                );

                $payment = Payment::query()->create([
                    'user_id' => (int) ($client->wp_user_id ?? 0),
                    'escort_post_id' => $client->wp_post_id,
                    'platform_id' => (int) $platform->id,
                    'product_id' => (int) $product->id,
                    'client_id' => (int) $client->id,
                    'phone' => (string) (($customer['phone'] ?? '') !== '' ? $customer['phone'] : $client->phone_normalized),
                    'amount' => (float) ($pricing['amount'] ?? 0),
                    'currency' => (string) ($pricing['currency'] ?? $platform->currency_code ?? 'KES'),
                    'transaction_uuid' => $transactionUuid,
                    'transaction_reference' => $normalizedReference,
                    'reference_number' => $internalReference,
                    'status' => 'pending',
                    'purpose' => 'subscription',
                    'subscription_lifecycle' => $lifecycle['subscription_lifecycle'],
                    'subscription_lifecycle_source' => $lifecycle['subscription_lifecycle_source'],
                    'subscription_lifecycle_reason' => $lifecycle['subscription_lifecycle_reason'],
                    'source' => 'manual_confirmation',
                    'provider_key' => 'manual_confirmation',
                    'match_confidence' => 'manual',
                    'reconciliation_confidence' => 'high',
                    'reconciliation_state' => 'manual_review',
                    'duration' => (string) ($pricing['legacy_duration'] ?? 'monthly'),
                    'raw_payload' => [
                        'source' => 'manual_payment_submission',
                        'method' => 'manual_confirmation',
                        'manual_method_key' => $normalizedMethodKey,
                        'billing_surface' => 'manual_confirmation',
                    ],
                    'payment_data' => [
                        'duration_key' => (string) ($pricing['duration_key'] ?? 'monthly'),
                        'duration_days' => (int) ($pricing['duration_days'] ?? 30),
                        'duration_label' => (string) ($pricing['duration_label'] ?? ''),
                        'customer' => [
                            'first_name' => trim((string) ($customer['first_name'] ?? '')),
                            'last_name' => trim((string) ($customer['last_name'] ?? '')),
                            'email' => trim((string) ($customer['email'] ?? '')),
                            'phone' => trim((string) ($customer['phone'] ?? '')),
                        ],
                        'manual_submission' => [
                            'manual_method_key' => $normalizedMethodKey,
                            'sender_name' => $normalizedSenderName,
                            'transaction_reference' => $normalizedReference,
                            'activated_on_submit' => $activatedOnSubmit,
                        ],
                    ],
                ]);

                $submission = PaymentManualSubmission::query()->create([
                    'payment_id' => (int) $payment->id,
                    'client_id' => (int) $client->id,
                    'platform_id' => (int) $platform->id,
                    'product_id' => (int) $product->id,
                    'duration_key' => (string) ($pricing['duration_key'] ?? 'monthly'),
                    'manual_method_key' => $normalizedMethodKey,
                    'activated_on_submit' => $activatedOnSubmit,
                    'destination_snapshot_json' => $this->destinationSnapshot($method),
                    'instruction_snapshot_json' => $this->instructionSnapshot($method),
                    'sender_name' => $normalizedSenderName,
                    'transaction_reference' => $normalizedReference,
                    'customer_note' => trim((string) ($customer['customer_note'] ?? '')) ?: null,
                    'proof_disk' => $storedProof['disk'],
                    'proof_path' => $storedProof['path'],
                    'proof_mime' => $proofMeta['mime'],
                    'proof_size_bytes' => (int) $proofMeta['size'],
                ]);

                $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
                data_set($paymentData, 'manual_submission.submission_id', (int) $submission->id);
                $payment->forceFill([
                    'payment_data' => $paymentData,
                ])->save();

                $deal = null;
                if ($activatedOnSubmit) {
                    $deal = $this->createOrActivateDeal($payment, $submission, null);
                }

                $payment->load(['platform', 'product', 'client', 'deal', 'manualSubmission']);
                $submission->load(['payment', 'client', 'platform']);

                return [
                    'duplicate' => false,
                    'payment' => $payment,
                    'submission' => $submission,
                    'deal' => $deal,
                ];
            });
        } catch (\Throwable $exception) {
            if (is_array($storedProof) && !empty($storedProof['path'])) {
                try {
                    Storage::disk($storedProof['disk'])->delete($storedProof['path']);
                } catch (\Throwable $cleanupException) {
                    Log::warning('Manual payment proof cleanup failed after submission error.', [
                        'path' => $storedProof['path'],
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }

            throw $exception;
        }

        if (!$result['duplicate']) {
            $this->synchronizeWpState($result['payment']);
        }

        return array_merge($result, [
            'customer_state' => $this->resolveCustomerState($result['payment']),
        ]);
    }

    public function createOrActivateDeal(Payment $payment, PaymentManualSubmission $submission, ?int $actorId = null): Deal
    {
        $payment->loadMissing(['client.platform', 'platform', 'product', 'deal']);

        if (!$payment->client) {
            throw new InvalidArgumentException('Payment must be matched to a client first.');
        }

        $deal = $payment->deal;
        if (!$deal) {
            $deal = $this->createPendingDeal($payment);
        }

        return $this->subscriptionProvisioningService->activateDeal($deal, [
            'payment' => $payment,
            'payment_method' => 'manual',
            'payment_reference' => $submission->transaction_reference,
            'duration_days' => (int) data_get($payment->payment_data, 'duration_days', 30),
            'actor_id' => $actorId,
            'emit_payment_received_timeline' => false,
            'emit_profile_activated_timeline' => true,
            'emit_deal_activated_timeline' => true,
        ]);
    }

    public function markSubmissionReview(
        PaymentManualSubmission $submission,
        string $decision,
        int $reviewerId,
        ?string $reason = null
    ): PaymentManualSubmission {
        $normalizedDecision = strtolower(trim($decision));
        if (!in_array($normalizedDecision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Unsupported manual review decision.');
        }

        $submission->forceFill([
            'review_decision' => $normalizedDecision,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'rejection_reason' => $normalizedDecision === 'rejected'
                ? trim((string) $reason) ?: 'Payment could not be verified.'
                : null,
        ])->save();

        return $submission->fresh(['payment', 'client', 'platform', 'product', 'reviewer']);
    }

    /**
     * @return array{state:?string,message:?string,reason:?string}
     */
    public function resolveCustomerState(Payment $payment): array
    {
        $payment->loadMissing(['manualSubmission', 'deal', 'client', 'platform']);
        $submission = $payment->manualSubmission;

        if (!$submission) {
            return [
                'state' => null,
                'message' => null,
                'reason' => null,
            ];
        }

        if ((string) $submission->review_decision === 'rejected') {
            return [
                'state' => 'rejected',
                'message' => 'We could not verify this payment. Please submit a new proof after making payment again.',
                'reason' => trim((string) ($submission->rejection_reason ?? '')) ?: null,
            ];
        }

        if ((string) $payment->status === 'completed' && (string) $payment->reconciliation_state === 'resolved') {
            return [
                'state' => null,
                'message' => null,
                'reason' => null,
            ];
        }

        $deal = $payment->deal;
        $hasActiveDeal = $deal && (string) $deal->status === 'active';

        if ((string) $payment->reconciliation_state === 'manual_review' && $hasActiveDeal) {
            return [
                'state' => 'verification_pending',
                'message' => 'Your account is active while our team verifies your payment proof.',
                'reason' => null,
            ];
        }

        if ((string) $payment->reconciliation_state === 'manual_review') {
            return [
                'state' => 'awaiting_review',
                'message' => 'We have received your payment proof and will activate your subscription after review.',
                'reason' => null,
            ];
        }

        return [
            'state' => null,
            'message' => null,
            'reason' => null,
        ];
    }

    public function synchronizeWpState(Payment $payment): void
    {
        $payment->loadMissing(['client.platform', 'platform', 'manualSubmission', 'deal']);

        $client = $payment->client;
        $platformId = (int) ($payment->platform_id ?: $client?->platform_id ?: 0);
        $wpPostId = (int) ($client?->wp_post_id ?? 0);

        if (!$client || $platformId <= 0 || $wpPostId <= 0) {
            return;
        }

        $state = $this->resolveCustomerState($payment);

        try {
            $wpSync = WpSyncService::forPlatform($platformId);
            $wpSync->updateClientProfile($wpPostId, [
                'billing_manual_payment_state' => $state['state'] ?? '',
                'billing_manual_payment_message' => $state['message'] ?? '',
                'billing_manual_payment_reason' => $state['reason'] ?? '',
                'billing_manual_payment_updated_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Manual payment state sync to WordPress failed.', [
                'payment_id' => $payment->id,
                'platform_id' => $platformId,
                'wp_post_id' => $wpPostId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function createPendingDeal(Payment $payment): Deal
    {
        $payment->loadMissing(['client.platform', 'product', 'platform']);

        $client = $payment->client;
        if (!$client) {
            throw new InvalidArgumentException('Payment must be matched to a client first.');
        }

        $product = $payment->product;
        if (!$product) {
            throw new InvalidArgumentException('Payment product is missing.');
        }

        $planType = $this->derivePlanTypeFromProduct($product);
        $deal = Deal::query()->create([
            'platform_id' => (int) $payment->platform_id,
            'client_id' => (int) $client->id,
            'payment_id' => (int) $payment->id,
            'product_id' => (int) $product->id,
            'plan_type' => $planType,
            'amount' => (float) $payment->amount,
            'currency' => $product->currency ?: ($payment->currency ?: ($payment->platform?->currency_code ?: 'KES')),
            'duration' => (string) ($payment->duration ?: 'monthly'),
            'status' => 'pending',
            'assigned_to' => $client->assigned_to,
            'origin' => 'manual_submission',
            'payment_reference' => $payment->transaction_reference,
            'subscription_lifecycle' => $payment->subscription_lifecycle,
            'subscription_lifecycle_source' => $payment->subscription_lifecycle_source,
            'subscription_lifecycle_reason' => $payment->subscription_lifecycle_reason,
        ]);

        $payment->forceFill([
            'deal_id' => (int) $deal->id,
        ])->save();

        return $deal;
    }

    private function derivePlanTypeFromProduct(Product $product): string
    {
        $tier = strtolower(trim((string) ($product->tier ?? '')));
        if (in_array($tier, ['basic', 'premium', 'vip', 'vvip'], true)) {
            return $tier;
        }

        $name = strtolower((string) $product->name);
        if (str_contains($name, 'vvip')) {
            return 'vvip';
        }
        if (str_contains($name, 'vip')) {
            return 'vip';
        }
        if (str_contains($name, 'premium')) {
            return 'premium';
        }

        return 'basic';
    }

    private function findOpenDuplicateSubmission(
        int $clientId,
        int $platformId,
        int $productId,
        string $durationKey
    ): ?PaymentManualSubmission {
        return PaymentManualSubmission::query()
            ->where('client_id', $clientId)
            ->where('platform_id', $platformId)
            ->where('product_id', $productId)
            ->where('duration_key', $durationKey)
            ->lockForUpdate()
            ->with(['payment.deal', 'client', 'platform'])
            ->get()
            ->first(function (PaymentManualSubmission $submission) {
                return (string) ($submission->payment?->reconciliation_state) === 'manual_review';
            });
    }

    /**
     * @return array{mime:string,size:int,extension:string}
     */
    private function validateProofImage(UploadedFile $proofImage): array
    {
        $mime = strtolower(trim((string) ($proofImage->getMimeType() ?: '')));
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!array_key_exists($mime, $allowedMimes)) {
            throw new InvalidArgumentException('Proof image must be a JPG, PNG, or WEBP file.');
        }

        $size = (int) $proofImage->getSize();
        if ($size <= 0 || $size > (8 * 1024 * 1024)) {
            throw new InvalidArgumentException('Proof image must be 8 MB or smaller.');
        }

        $contents = $proofImage->get();
        if ($contents === false || @getimagesizefromstring($contents) === false) {
            throw new InvalidArgumentException('Proof image could not be processed. Please upload a valid image file.');
        }

        return [
            'mime' => $mime,
            'size' => $size,
            'extension' => $allowedMimes[$mime],
        ];
    }

    /**
     * @return array{disk:string,path:string}
     */
    private function storeProofImage(UploadedFile $proofImage, int $platformId, string $extension): array
    {
        $directory = sprintf(
            'manual-payment-proofs/%d/%s/%s',
            $platformId,
            now()->format('Y'),
            now()->format('m')
        );
        $filename = Str::uuid()->toString() . '.' . $extension;
        $path = Storage::disk('local')->putFileAs($directory, $proofImage, $filename);

        if (!$path) {
            throw new InvalidArgumentException('Proof image could not be stored. Please try again.');
        }

        return [
            'disk' => 'local',
            'path' => $path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function destinationSnapshot(BillingManualPaymentMethod $method): array
    {
        return [
            'method_key' => $method->method_key,
            'display_name' => $method->display_name,
            'details' => is_array($method->details_json) ? $method->details_json : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function instructionSnapshot(BillingManualPaymentMethod $method): array
    {
        return [
            'instruction_intro' => $method->instruction_intro,
            'instruction_footer' => $method->instruction_footer,
            'proof_required' => (bool) $method->proof_required,
            'sender_name_required' => (bool) $method->sender_name_required,
            'transaction_id_required' => (bool) $method->transaction_id_required,
            'auto_activate_on_submission' => (bool) $method->auto_activate_on_submission,
        ];
    }

    private function subscriptionReference(
        int $platformId,
        int $userId,
        int $productId,
        string $durationKey,
        string $transactionUuid
    ): string {
        $hash = strtoupper(substr(hash('sha256', implode('|', [
            $platformId,
            $userId,
            $productId,
            $durationKey,
            $transactionUuid,
        ])), 0, 18));

        return 'SUB-' . $hash;
    }
}
