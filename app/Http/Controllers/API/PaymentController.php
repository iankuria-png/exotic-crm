<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Deal;
use App\Models\Activation;
use App\Models\SmsLog;
use App\Models\WordpressPost;
use App\Services\DynamicDatabaseService;
use App\Services\LegacyStkService;
use App\Services\PaymentCompletionService;
use App\Services\PaymentAttemptService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentCompletionService $paymentCompletionService,
        private readonly LegacyStkService $legacyStkService
    ) {
    }

    public function initiate(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required',
                'platform_id' => 'required',
                'user_id' => 'required',
                'phone' => 'required',
                'duration' => 'required'
            ]);
    
            // 2. Lookup product/platform + calculate amount using switch
            $product = Product::findOrFail($request->product_id);
            $platform = Platform::findOrFail($request->platform_id);
            
            $price = 0;
            switch ($request->duration) {
                case 'weekly':
                    $price = $product->weekly_price;
                    break;
                case 'biweekly':
                    $price = $product->biweekly_price;
                    break;
                case 'monthly':
                    $price = $product->monthly_price;
                    break;
                default:
                    throw new \Exception("Invalid duration type: " . $request->duration);
            }
    
            // 3. Save Payment record in DB (initiated)
            $payment = Payment::create([
                'user_id' => $request->user_id,
                'platform_id' => $platform->id,
                'product_id' => $product->id,
                'phone' => $request->phone,
                'amount' => $price,
                'duration' => $request->duration,
                'status' => 'initiated'
            ]);
    
            $result = $this->legacyStkService->initiate($payment, [
                'phone' => $request->phone,
                'duration' => $request->duration,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
            ]);

            if ($result['success']) {
                $updates = [
                    'status' => 'pending',
                    'failure_reason' => null,
                    'provider_key' => 'mpesa_stk',
                    'provider_environment' => $result['provider_environment'] ?? null,
                    'raw_payload' => [
                        'source' => 'legacy_payment_initiate',
                        'transport' => $result['transport'] ?? null,
                        'upstream_url' => $result['upstream_url'] ?? null,
                        'provider_response' => $result['provider_response'] ?? null,
                    ],
                ];
                if (!empty($result['provider_reference'])) {
                    $updates['transaction_reference'] = $result['provider_reference'];
                }
                $payment->forceFill($updates)->save();

                return response()->json([
                    "status"     => true,
                    "message"    => "Payment initiated successfully",
                    "payment_id" => $payment->id
                ]);
            }

            \Log::warning("STK push failed response", [
                "payment_id" => $payment->id,
                "provider" => $result['provider'] ?? null,
                "transport" => $result['transport'] ?? null,
                "upstream_url" => $result['upstream_url'] ?? null,
                "http_status" => $result['http_status'] ?? null,
                "redirect_location" => $result['redirect_location'] ?? null,
                "response_body" => $result['response_body'] ?? null,
                "provider_response" => $result['provider_response'] ?? null,
            ]);
            $payment->forceFill([
                'status' => 'failed',
                'failure_reason' => mb_substr((string) ($result['message'] ?? 'STK push could not be initiated.'), 0, 190),
                'provider_key' => 'mpesa_stk',
                'provider_environment' => $result['provider_environment'] ?? null,
                'raw_payload' => [
                    'source' => 'legacy_payment_initiate',
                    'transport' => $result['transport'] ?? null,
                    'upstream_url' => $result['upstream_url'] ?? null,
                    'http_status' => $result['http_status'] ?? null,
                    'redirect_location' => $result['redirect_location'] ?? null,
                    'response_body' => $result['response_body'] ?? null,
                    'provider_response' => $result['provider_response'] ?? null,
                ],
            ])->save();

            return response()->json([
                "status" => false,
                "error"  => $result['message'] ?? 'STK push could not be initiated.'
            ], 400);
    
        } catch (\Exception $e) {
            \Log::error("STK push error", ["exception" => $e->getMessage()]);
            return response()->json([
                "status" => false,
                "error"  => "Server error: " . $e->getMessage()
            ], 500);
        }
    }

    public function initiatePayment(Request $request)
    {
        if (!$request->filled('platform_id')) {
            $platformId = $this->inferPlatformIdForLegacyPaymentRequest($request->input('product_id'));
            if ($platformId) {
                $request->merge(['platform_id' => $platformId]);
            }
        }

        $phone = preg_replace('/\D/', '', (string) $request->input('phone'));
        if (strlen($phone) === 9 && str_starts_with($phone, '7')) {
            $request->merge(['phone' => '254' . $phone]);
        }

        return $this->manualStkPush($request);
    }

    public function callback(Request $request)
    {
        return $this->updatePaymentStatus($request);
    }


    
   #callback
    public function updatePaymentStatus(Request $request)
    {
        try {
            \Log::info("Payment update received from Django", $request->all());
    
            $request->validate([
                'payment_id'            => 'required|integer',
                'status'                => 'required|string',
                'transaction_reference' => 'nullable|string'
            ]);
    
            $payment = Payment::find($request->payment_id);
    
            if (!$payment) {
                \Log::warning("Payment not found", ["payment_id" => $request->payment_id]);
                return response()->json(["error" => "Payment not found"], 404);
            }
    
            $resource = $request->input('resource', []);
            $metadata = $request->input('metadata', []);
            $rawData  = $request->input('rawData', []);
            $incomingStatus = $this->normalizePaymentStatus($request->input('status'));
    
            if (($resource['status'] ?? null) === "Success" || $incomingStatus === "completed") {
                $payment->status = 'completed';
                $payment->transaction_reference = $request->transaction_reference ?? ($resource['reference'] ?? null);
                $payment->save();
    
                // Pass the payment object directly instead of trying to extract ID
                $this->handleSuccessfulPayment($payment, $resource, $metadata, $rawData);
                $this->recordCallbackAttempt($request, $payment, 'success', $resource, $rawData);
    
            } elseif (($resource['status'] ?? null) === "Reversed" || $incomingStatus === "reversed") {
                $payment->status = 'reversed';
                $payment->transaction_reference = $request->transaction_reference ?? ($resource['reference'] ?? null);
                $payment->save();
    
                $this->handleReversedPayment($resource, $metadata, $rawData);
                $this->recordCallbackAttempt(
                    $request,
                    $payment,
                    'reversed',
                    $resource,
                    $rawData,
                    'Payment reversed by provider',
                    'reversed'
                );
    
            } else {
                $payment->status = 'failed';
                $payment->transaction_reference = $request->transaction_reference ?? ($resource['reference'] ?? null);
                $payment->save();
    
                $this->handleFailedPayment($resource, $metadata, $rawData);
                $this->recordCallbackAttempt(
                    $request,
                    $payment,
                    'failed',
                    $resource,
                    $rawData,
                    (string) ($resource['status'] ?? $incomingStatus ?? 'Payment failed'),
                    'callback_failed'
                );
            }
    
            return response()->json([
                "success" => true,
                "message" => "Payment status updated successfully"
            ]);
    
        } catch (\Exception $e) {
            \Log::error("Error updating payment status", ["exception" => $e->getMessage()]);
            return response()->json([
                "success" => false,
                "error" => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Handle STK Push errors
     */
    private function handleStkError($stkResponse, $payment)
    {
        $errorBody = $stkResponse->json();
        $statusCode = $stkResponse->status();
        
        // Map status codes to user-friendly messages
        $errorMap = [
            400 => 'Invalid payment request. Please check your details.',
            401 => 'Payment service authentication failed.',
            403 => 'Payment service unavailable.',
            422 => 'Invalid payment details provided.',
            429 => 'Please complete the pending payment request on your phone first.',
            500 => 'Payment service temporarily unavailable.',
            503 => 'Payment service temporarily unavailable.'
        ];
        
        $errorMessage = $errorMap[$statusCode] ?? 
                      ($errorBody['errorMessage'] ?? 'Payment request failed');

        $payment->update([
            'status' => 'failed',
            'failure_reason' => $errorMessage,
            'raw_payload' => array_merge($payment->raw_payload, [
                'error_response' => $errorBody,
                'status_code' => $statusCode
            ])
        ]);
        
        $responseData = [
            'status' => false,
            'error' => $errorMessage,
            'error_details' => [
                'code' => $statusCode,
                'reference' => $errorBody['reference'] ?? null,
                'timestamp' => now()->toDateTimeString()
            ]
        ];

        // Add additional guidance for specific errors
        if ($statusCode === 429) {
            $responseData['error_details']['retry_after'] = 300; // 5 minutes
            $responseData['error_details']['guidance'] = 'Complete or cancel the pending request on your phone';
        }

        return response()->json($responseData, 
            in_array($statusCode, [400, 401, 422, 429]) ? $statusCode : 502);
    }

    /**
     * Handle exceptions
     */
    private function handleException(\Exception $e, $payment = null)
    {
        if ($payment) {
            $payment->update([
                'status' => 'failed',
                'failure_reason' => 'System error: ' . substr($e->getMessage(), 0, 100),
                'raw_payload' => array_merge($payment->raw_payload, [
                    'exception' => [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]
                ])
            ]);
        }
        
        Log::error('Payment initiation exception', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        return response()->json([
            'status' => false,
            'error' => 'System error processing your payment.',
            'error_details' => config('app.debug') ? [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'timestamp' => now()->toDateTimeString()
            ] : null
        ], 500);
    }

    private function recordCallbackAttempt(
        Request $request,
        Payment $payment,
        string $status,
        array $resource = [],
        array $rawData = [],
        ?string $errorMessage = null,
        ?string $errorCode = null
    ): void {
        try {
            $eventResource = data_get($rawData, 'event.resource', []);
            $origination = $resource['origination_time']
                ?? (is_array($eventResource) ? ($eventResource['origination_time'] ?? null) : null);

            $latencyMs = null;
            if (!empty($origination)) {
                $origin = Carbon::parse((string) $origination);
                $diff = $origin->diffInMilliseconds(now(), false);
                if ($diff >= 0) {
                    $latencyMs = (int) $diff;
                }
            }

            app(PaymentAttemptService::class)->record($payment, 'callback_update', $status, [
                'provider' => 'kopokopo_webhook',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'latency_ms' => $latencyMs,
                'request_meta' => app(PaymentAttemptService::class)->requestMetaFromRequest($request, [
                    'event_status' => $resource['status'] ?? $request->input('status'),
                    'transaction_reference' => $resource['reference'] ?? $request->input('transaction_reference'),
                ]),
                'response_meta' => [
                    'resource_status' => $resource['status'] ?? null,
                    'resource_reference' => $resource['reference'] ?? null,
                    'metadata_payment_id' => $rawData['metadata']['payment_id'] ?? null,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to record callback payment attempt', [
                'payment_id' => $payment->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

  



        
    
    private function processWebhookEvent($eventType, $resource)
    {
        // Legacy helper kept for backward compatibility.
        // Current callback flow uses updatePaymentStatus() directly.
        Log::warning('Deprecated processWebhookEvent() call ignored', [
            'event_type' => $eventType,
        ]);

        return true;
    } 

    /**
     * Handle successful payment webhook
     */
    private function handleSuccessfulPayment(Payment $payment, array $resource, ?array $metadata, array $rawData)
    {
        try {
            DB::transaction(function () use ($payment, $resource, $metadata, $rawData) {
                \Log::info('Starting successful payment handling', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'amount' => $payment->amount,
                    'product_id' => $payment->product_id,
                    'platform_id' => $payment->platform_id
                ]);
    
                $completion = $payment->purpose === 'wallet_topup'
                    ? $this->paymentCompletionService->completeTopupPayment($payment, $resource, [
                        'transaction_reference' => $resource['reference'] ?? $payment->transaction_reference,
                        'raw_payload' => [
                            'webhook_data' => $rawData,
                            'processed_at' => now()->toDateTimeString(),
                        ],
                    ])
                    : $this->paymentCompletionService->completeSubscriptionPayment($payment, $resource, [
                        'transaction_reference' => $resource['reference'] ?? $payment->transaction_reference,
                        'raw_payload' => [
                            'webhook_data' => $rawData,
                            'processed_at' => now()->toDateTimeString(),
                        ],
                        'metadata' => $metadata,
                        'raw_context' => $rawData,
                        'confirmed_at' => $payment->confirmed_at ?? now(),
                        'match_confidence' => $payment->match_confidence ?: 'auto_high',
                        'reconciliation_confidence' => 'high',
                        'reconciliation_state' => 'resolved',
                        'payment_method' => $this->paymentCompletionService->resolvePaymentMethod($payment, $metadata, $rawData),
                        'emit_payment_received_timeline' => true,
                        'emit_profile_activated_timeline' => false,
                        'emit_deal_activated_timeline' => true,
                    ]);

                $payment = $completion['payment'];
                $deal = $completion['deal'] ?? null;

                \Log::info('Services activation result', [
                    'payment_id' => $payment->id,
                    'deal_id' => $deal?->id,
                    'success' => $deal !== null
                ]);

                if ((string) $payment->purpose !== 'wallet_topup') {
                    $message = $this->generateSuccessMessage($payment, $payment->transaction_reference);
                    $this->saveSMSLog($payment, $message, 'sent');
                }

                Log::info('Payment completed and activated successfully', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'deal_id' => $deal?->id
                ]);
            });
        } catch (\Exception $e) {
            \Log::error('Error in handleSuccessfulPayment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Re-throw to ensure the outer transaction knows it failed
            throw $e;
        }
    }

    private function handleReversedPayment(array $resource, ?array $metadata, array $rawData)
    {
        $paymentId = $metadata['payment_id'] ?? $resource['payment_id'] ?? null;
    
        if (!$paymentId) {
            Log::error('Payment ID missing in reversal webhook', ['metadata' => $metadata]);
            return;
        }
    
        $payment = Payment::find($paymentId);
    
        if (!$payment) {
            Log::error('Payment not found for reversal webhook', ['payment_id' => $paymentId]);
            return;
        }
    
        $payment->update([
            'status' => 'reversed',
            'failure_reason' => 'Payment reversed',
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'reversal_data' => $rawData,
                'processed_at' => now()->toDateTimeString()
            ])
        ]);
    
        $message = $this->generateFailureMessage($payment, 'REVERSED');
        $this->saveSMSLog($payment, $message, 'sent');
    
        Log::info('Payment marked as reversed', ['payment_id' => $paymentId]);
    }
    
    private function handleFailedPayment(array $resource, ?array $metadata, array $rawData)
    {
        $paymentId = $metadata['payment_id'] ?? $resource['payment_id'] ?? null;
    
        if (!$paymentId) {
            Log::error('Payment ID missing in failed webhook', ['metadata' => $metadata]);
            return;
        }
    
        $payment = Payment::find($paymentId);
    
        if (!$payment) {
            Log::error('Payment not found for failed webhook', ['payment_id' => $paymentId]);
            return;
        }
    
        $payment->update([
            'status' => 'failed',
            'failure_reason' => 'Payment failed',
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'failure_data' => $rawData,
                'processed_at' => now()->toDateTimeString()
            ])
        ]);
    
        $message = $this->generateFailureMessage($payment, 'FAILED');
        $this->saveSMSLog($payment, $message, 'sent');
    
        Log::info('Payment marked as failed', ['payment_id' => $paymentId]);
    }
    
    /**
     * Test webhook endpoint - for debugging
     */
    public function testWebhook(Request $request)
    {
        Log::info('Test webhook called', [
            'method' => $request->method(),
            'headers' => $request->headers->all(),
            'content' => $request->getContent(),
            'timestamp' => now()->toDateTimeString()
        ]);
    
        return response()->json([
            'status' => 'success',
            'message' => 'Test webhook received',
            'timestamp' => now()->toDateTimeString(),
            'server_info' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                'https' => $request->isSecure(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip()
            ],
            'received_data' => [
                'method' => $request->method(),
                'headers' => $request->headers->all(),
                'content' => $request->getContent(),
                'content_length' => strlen($request->getContent())
            ]
        ]);
    }
    
    /**
     * Webhook registration helper - subscribe to webhooks
     */
    public function subscribeToWebhooks()
    {
        if (!config('app.debug')) {
            return response()->json(['error' => 'Debug mode required'], 403);
        }
    
        $baseUrl = config('kopokopo.base_url');
        $clientId = config('kopokopo.client_id');
        $clientSecret = config('kopokopo.client_secret');
        
        // Get access token
        $accessToken = $this->getAccessTokenWithDebug($baseUrl, $clientId, $clientSecret);
        
        if (!$accessToken) {
            return response()->json([
                'status' => false,
                'error' => 'Could not get access token'
            ]);
        }
    
        $webhookUrl = url('/api/payment-callback');
        $subscriptions = [];
        
        // Subscribe to relevant events
        $events = [
            'buygoods_transaction_received',
            'buygoods_transaction_reversed',
            'customer_created'
        ];
    
        foreach ($events as $eventType) {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->post($baseUrl . '/api/v1/webhook_subscriptions', [
                    'event_type' => $eventType,
                    'url' => $webhookUrl,
                    'scope' => 'till',
                    'scope_reference' => config('kopokopo.till_number')
                ]);
    
                $subscriptions[$eventType] = [
                    'status_code' => $response->status(),
                    'success' => $response->successful(),
                    'response' => $response->json(),
                    'location' => $response->header('Location')
                ];
    
            } catch (\Exception $e) {
                $subscriptions[$eventType] = [
                    'error' => $e->getMessage()
                ];
            }
        }
    
        return response()->json([
            'status' => true,
            'webhook_url' => $webhookUrl,
            'subscriptions' => $subscriptions,
            'note' => 'Check the location headers for subscription IDs'
        ]);
    }

    /**
     * Format phone number to Kenyan format
     */
    private function formatPhoneNumber($phone)
    {
        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);
        
        // Handle various formats:
        // 712345678 → 254712345678
        // 0712345678 → 254712345678
        // 254712345678 → 254712345678
        // +254712345678 → 254712345678
        
        if (strlen($phone) === 9 && strpos($phone, '7') === 0) {
            return '254' . $phone;
        }
        
        if (strlen($phone) === 10 && strpos($phone, '0') === 0) {
            return '254' . substr($phone, 1);
        }
        
        if (strlen($phone) === 12 && strpos($phone, '254') === 0) {
            return $phone;
        }
        
        return false;
    }

    /**
     * Check payment status
     */
    public function checkStatus(Request $request)
    {
        $validated = $request->validate([
            'payment_id' => 'nullable|integer',
            'transaction_uuid' => 'nullable|string',
            'reference_number' => 'nullable|string',
        ]);

        $payment = $this->resolvePaymentForStatusLookup(
            $validated['payment_id'] ?? null,
            $validated['transaction_uuid'] ?? null,
            $validated['reference_number'] ?? null
        );

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' => 'Payment not found',
            ], 404);
        }

        return response()->json($this->statusResponseForPayment($payment));
    }

    public function checkPaymentStatus(string $transactionUuid)
    {
        $payment = $this->resolvePaymentForStatusLookup(
            ctype_digit($transactionUuid) ? (int) $transactionUuid : null,
            $transactionUuid,
            null
        );

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' => 'Payment not found',
            ], 404);
        }

        return response()->json($this->statusResponseForPayment($payment));
    }

    public function debugKopokopo()
    {
        return response()->json([
            'status' => true,
            'config' => [
                'base_url' => config('kopokopo.base_url'),
                'till_number' => config('kopokopo.till_number'),
                'callback_url' => url('/api/payment-callback'),
                'webhook_test_url' => url('/api/test-webhook'),
                'is_production' => config('kopokopo.base_url') === 'https://api.kopokopo.com',
            ],
        ]);
    }

    public function clearPendingPayments(Request $request)
    {
        if (!config('app.debug')) {
            return response()->json([
                'status' => false,
                'message' => 'Debug mode required',
            ], 403);
        }

        $minutes = max(1, min(1440, (int) $request->input('minutes', 10)));
        $expiredBefore = now()->subMinutes($minutes);
        $payments = Payment::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $expiredBefore)
            ->get();

        foreach ($payments as $payment) {
            $payment->update([
                'status' => 'failed',
                'failure_reason' => 'Pending payment timed out',
            ]);
        }

        return response()->json([
            'status' => true,
            'cleared_count' => $payments->count(),
            'timeout_minutes' => $minutes,
        ]);
    }

    private function statusResponseForPayment(Payment $payment): array
    {
        $payment->loadMissing(['platform:id,name', 'product:id,name']);
        $status = $this->normalizePaymentStatus($payment->status) ?? $payment->status;

        return [
            'status' => true,
            'payment_status' => $status,
            'payment_id' => $payment->id,
            'data' => [
                'id' => $payment->id,
                'status' => $status,
                'transaction_uuid' => $payment->transaction_uuid,
                'transaction_reference' => $payment->transaction_reference,
                'reference_number' => $payment->reference_number,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'duration' => $payment->duration,
                'platform_name' => $payment->platform->name ?? null,
                'product_name' => $payment->product->name ?? null,
                'completed_at' => optional($payment->completed_at)->toDateTimeString(),
                'updated_at' => optional($payment->updated_at)->toDateTimeString(),
            ],
        ];
    }

    private function resolvePaymentForStatusLookup(?int $paymentId, ?string $transactionUuid, ?string $referenceNumber): ?Payment
    {
        $query = Payment::query()->with(['platform:id,name', 'product:id,name']);

        if ($paymentId) {
            return $query->find($paymentId);
        }

        if (!empty($transactionUuid)) {
            $payment = $query->where('transaction_uuid', $transactionUuid)->first();
            if ($payment) {
                return $payment;
            }
        }

        if (!empty($referenceNumber)) {
            return $query->where('reference_number', $referenceNumber)->first();
        }

        return null;
    }

    private function normalizePaymentStatus(?string $status): ?string
    {
        $normalized = strtolower(trim((string) $status));

        return match ($normalized) {
            '', null => null,
            'success' => 'completed',
            'cancelled' => 'canceled',
            default => $normalized,
        };
    }

    private function inferPlatformIdForLegacyPaymentRequest($productId): ?int
    {
        if (!$productId) {
            return null;
        }

        return Product::query()
            ->whereKey($productId)
            ->value('platform_id');
    }

    private function generateSuccessMessage($payment, $transactionId)
    {
        $platform = Platform::find($payment->platform_id);
        $endDate = $this->calculateSubscriptionEndDate($payment->duration, now());
        $expirationDate = $endDate->format('jS F Y');
        
        // Get user's specific post URL
        $userPostUrl = $this->getUserPostUrl($payment->user_id, $payment->platform_id);
        
        return "Payment confirmed! KSH {$payment->amount} " .
               "Subscription active until {$expirationDate}. " .
               "Your profile: {$userPostUrl} " .
               "Transaction ID: {$transactionId}";
    }
    
    private function getUserPostUrl($userId, $platformId)
    {
        try {
            $platform = Platform::find($platformId);
            if (!$platform) {
                return 'Profile link not available';
            }

            $connectionName = 'platform_' . $platform->id;
            DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());

            // Get the escort post first
            $userPost = WordpressPost::on($connectionName)
                ->where('post_author', $userId)
                ->where('post_type', 'escort')
                ->where('post_status', 'publish')
                ->first(['ID', 'guid']);

            if ($userPost) {
                // Try to get live_url from uapy1_escort_live_urls table
                try {
                    $pdo = DB::connection($connectionName)->getPdo();
                    $stmt = $pdo->prepare("SELECT live_url FROM uapy1_escort_live_urls WHERE post_id = ? LIMIT 1");
                    $stmt->execute([$userPost->ID]);
                    $liveUrl = $stmt->fetchColumn();
                    
                    if ($liveUrl) {
                        return $liveUrl; // Return live_url if available
                    }
                } catch (\Exception $e) {
                    \Log::warning('Could not fetch from uapy1_escort_live_urls', [
                        'post_id' => $userPost->ID,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Fallback to GUID if live_url not found
                if (!empty($userPost->guid)) {
                    return $userPost->guid;
                }
            }

            return $platform->domain ?? 'Profile link';

        } catch (\Exception $e) {
            \Log::error('Error getting user URL', [
                'user_id' => $userId,
                'platform_id' => $platformId,
                'error' => $e->getMessage()
            ]);
            return 'Profile link';
        }
    }

    private function generateFailureMessage($payment, $errorCode)
    {
        $platform = Platform::find($payment->platform_id);
        return "Payment failed for {$platform->name} subscription. Error: {$errorCode}. " .
               "Please try again or contact support.";
    }

    private function calculateSubscriptionEndDate($duration, $startDate)
    {
        $startDate = Carbon::parse($startDate);
        
        switch (strtolower($duration)) {
            case 'weekly':
                return $startDate->copy()->addDays(7);
            case 'biweekly':
                return $startDate->copy()->addDays(14);
            case 'monthly':
                return $startDate->copy()->addMonth();
            default:
                return $startDate->copy()->addMonth();
        }
    }

    private function saveSMSLog($payment, $message, $resultCode)
    {
        try {
            \Log::info("Saving SMS log", [
                'payment_id' => $payment->id,
                'phone' => $payment->phone,
                'result_code' => $resultCode
            ]);
    
            $smsLogId = \DB::table('sms_logs')->insertGetId([
                'payment_id' => $payment->id,
                'phone' => $payment->phone,
                'message' => $message,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    
            \Log::info("SMS log saved successfully", ['sms_log_id' => $smsLogId]);
    
           
            try {
                $smsResponse = $this->sendSMS($payment->phone, $message, $payment);
                
                \DB::table('sms_logs')
                    ->where('id', $smsLogId)
                    ->update([
                        'status' => $smsResponse['success'] ? 'sent' : 'failed',
                        'sent_at' => $smsResponse['success'] ? now() : null,
                        'response' => $smsResponse['message'] ?? null,
                        'updated_at' => now(),
                    ]);
    
                \Log::info("SMS send attempted", [
                    'sms_log_id' => $smsLogId,
                    'success' => $smsResponse['success']
                ]);
    
            } catch (\Exception $smsException) {
                \Log::error('SMS sending failed but log was saved', [
                    'sms_log_id' => $smsLogId,
                    'error' => $smsException->getMessage()
                ]);
    
                \DB::table('sms_logs')
                    ->where('id', $smsLogId)
                    ->update([
                        'status' => 'failed',
                        'response' => 'SMS error: ' . $smsException->getMessage(),
                        'updated_at' => now(),
                    ]);
            }
    
            return $smsLogId;
    
        } catch (\Exception $e) {
            \Log::error('CRITICAL: Failed to save SMS log entirely', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

   private function activateUserServices($userId, $payment)
{
    try {
        \Log::info('Starting service activation', [
            'user_id' => $userId,
            'payment_id' => $payment->id
        ]);

        // Get the product from the payment
        $product = Product::find($payment->product_id);
        
        if (!$product) {
            \Log::error('Product not found for payment', ['payment_id' => $payment->id]);
            throw new \Exception('Product not found for payment');
        }

        // Get the platform from the payment
        $platform = Platform::find($payment->platform_id);
        if (!$platform) {
            \Log::error('Platform not found for payment', ['payment_id' => $payment->id]);
            throw new \Exception('Platform not found for payment');
        }

        \Log::info('Platform and product loaded', [
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'product_name' => $product->name
        ]);

        // Switch to platform's database connection
        $connectionName = 'platform_' . $platform->id;
        DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());

        // Calculate subscription period - MATCHES WordPress "escort_expire" logic
        $startDate = now();
        $endDate = $this->calculateSubscriptionEndDate($payment->duration, $startDate);

        \Log::info('Subscription dates calculated', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'duration' => $payment->duration
        ]);

        // Activate all escort posts for this user - INCLUDING PRIVATE ONES
        $posts = WordpressPost::on($connectionName)
            ->where('post_author', $userId)
            ->where('post_type', 'escort')
            ->get();

        \Log::info('Found posts to activate', [
            'post_count' => $posts->count(),
            'user_id' => $userId
        ]);

        $activatedCount = 0;

        foreach ($posts as $post) {
            \Log::info('Activating post', [
                'post_id' => $post->ID,
                'current_status' => $post->post_status
            ]);

            // === WORDPRESS ACTIVATION LOGIC ===
            
            // 1. UPDATE POST STATUS TO PUBLISH (Like WordPress wp_update_post)
            $post->post_status = 'publish';
            $post->save();
            
            // 2. DELETE "notactive" FLAG (Like delete_post_meta for 'notactive')
            DB::connection($connectionName)->table('postmeta')
                ->where('post_id', $post->ID)
                ->where('meta_key', 'notactive')
                ->delete(); // DELETE not just update to '0'

            // 3. DELETE "needs_payment" FLAG (Like delete_post_meta for 'needs_payment')
            DB::connection($connectionName)->table('postmeta')
                ->where('post_id', $post->ID)
                ->where('meta_key', 'needs_payment')
                ->delete();

            // 4. SET "escort_expire" METADATA (Like update_post_meta for 'escort_expire')
            // Convert to UNIX timestamp like WordPress does
            $escortExpireTimestamp = $endDate->timestamp;
            
            DB::connection($connectionName)->table('postmeta')
                ->updateOrInsert(
                    ['post_id' => $post->ID, 'meta_key' => 'escort_expire'],
                    ['meta_value' => $escortExpireTimestamp]
                );

            // 5. SET "premium_since" FOR PREMIUM PROFILES (Like update_post_meta for 'premium_since')
            $productName = strtolower($product->name);
            if (str_contains($productName, 'premium') || str_contains($productName, 'vip')) {
                $premiumSinceTimestamp = $startDate->timestamp;
                
                DB::connection($connectionName)->table('postmeta')
                    ->updateOrInsert(
                        ['post_id' => $post->ID, 'meta_key' => 'premium_since'],
                        ['meta_value' => $premiumSinceTimestamp]
                    );
            }

            // 6. Determine meta values based on product name - SAME AS BEFORE
            $metaUpdates = [];
            if (str_contains($productName, 'vip')) {
                $metaUpdates = [
                    'premium' => '1',
                    'featured' => '1',
                    'verified' => '1' // VIP might include verification
                ];
            } elseif (str_contains($productName, 'premium')) {
                $metaUpdates = [
                    'premium' => '1',
                    'featured' => '0',
                    'verified' => '0'
                ];
            } else {
                $metaUpdates = [
                    'premium' => '0',
                    'featured' => '0',
                    'verified' => '0'
                ];
            }

            // 7. Update or create the meta values
            foreach ($metaUpdates as $metaKey => $metaValue) {
                DB::connection($connectionName)->table('postmeta')
                    ->updateOrInsert(
                        ['post_id' => $post->ID, 'meta_key' => $metaKey],
                        ['meta_value' => $metaValue]
                    );
            }

            // 8. Store subscription dates (your custom fields)
            DB::connection($connectionName)->table('postmeta')->updateOrInsert(
                ['post_id' => $post->ID, 'meta_key' => 'subscription_start'],
                ['meta_value' => $startDate->format('Y-m-d H:i:s')]
            );

            DB::connection($connectionName)->table('postmeta')->updateOrInsert(
                ['post_id' => $post->ID, 'meta_key' => 'subscription_end'],
                ['meta_value' => $endDate->format('Y-m-d H:i:s')]
            );

            // 9. UPDATE POST MODIFIED DATE (Like WordPress does)
            $postModified = now()->format('Y-m-d H:i:s');
            $postModifiedGmt = now()->setTimezone('UTC')->format('Y-m-d H:i:s');
            
            $post->post_modified = $postModified;
            $post->post_modified_gmt = $postModifiedGmt;
            $post->save();

            $activatedCount++;
            \Log::info('Post activated successfully with WordPress logic', [
                'post_id' => $post->ID,
                'new_status' => 'publish',
                'escort_expire' => $escortExpireTimestamp,
                'meta_updates' => $metaUpdates
            ]);
        }

        // Update payment with subscription dates
        $payment->update([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'platform_id' => $platform->id
        ]);

        // === SIMULATE WORDPRESS EMAIL NOTIFICATION ===
        // If you want to send notification like WordPress does
        if (config('app.send_activation_emails', false)) {
            $this->sendActivationEmail($userId, $platform, $product, $activatedCount);
        }

        \Log::info("Successfully activated {$activatedCount} services for user {$userId} on platform {$platform->name}", [
            'platform_id' => $platform->id,
            'product_name' => $product->name,
            'wordpress_actions' => [
                'post_status_updated' => true,
                'notactive_deleted' => true,
                'needs_payment_deleted' => true,
                'escort_expire_set' => true,
                'premium_since_set' => str_contains(strtolower($product->name), 'premium') || str_contains(strtolower($product->name), 'vip')
            ],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'duration' => $payment->duration,
            'activated_count' => $activatedCount
        ]);
        
        return $activatedCount;
        
    } catch (\Exception $e) {
        \Log::error('Service activation failed: ' . $e->getMessage(), [
            'user_id' => $userId,
            'payment_id' => $payment->id ?? null,
            'platform_id' => $payment->platform_id ?? null,
            'trace' => $e->getTraceAsString()
        ]);
        throw $e;
    }
}

    /**
     * Helper method to send activation email (optional)
     */
    private function sendActivationEmail($userId, $platform, $product, $activatedCount)
    {
        try {
            // Get user email from WordPress users table
            $user = DB::connection('platform_' . $platform->id)
                ->table('users')
                ->where('ID', $userId)
                ->first(['user_email', 'display_name']);
            
            if ($user && $user->user_email) {
                // Send email similar to WordPress dolce_email function
                $subject = "Profile Activated on " . config('app.name');
                $message = "Hello,<br><br>";
                $message .= "Your profile has been activated with {$product->name} package.<br>";
                $message .= "{$activatedCount} profile(s) are now live on the site.<br><br>";
                $message .= "Thank you!";
                
                // Use your email service here
                // Mail::to($user->user_email)->send(new ActivationEmail($subject, $message));
                
                \Log::info('Activation email queued', [
                    'user_email' => $user->user_email,
                    'user_id' => $userId
                ]);
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to send activation email: ' . $e->getMessage());
            // Don't throw, just log
        }
    }


    // Optional: Get payment history for a user
    public function getPayments(Request $request, $userId)
    {
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|in:initiated,pending,completed,success,failed,cancelled,canceled,reversed,activated,deactivated,under_review,error'
        ]);

        $query = Payment::where('user_id', $userId)
            ->with(['platform:id,name', 'product:id,name'])
            ->orderBy('created_at', 'desc');

        if ($request->status) {
            $status = $this->normalizePaymentStatus($request->status);
            if ($status === 'completed') {
                $query->whereIn('status', ['completed', 'success']);
            } else {
                $query->where('status', $status);
            }
        }

        $limit = $request->limit ?? 10;
        $payments = $query->limit($limit)->get();

        return response()->json([
            'status' => true,
            'data' => $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'duration' => $payment->duration,
                    'status' => $this->normalizePaymentStatus($payment->status) ?? $payment->status,
                    'transaction_reference' => $payment->transaction_reference,
                    'platform_name' => $payment->platform->name ?? 'N/A',
                    'product_name' => $payment->product->name ?? 'N/A',
                    'start_date' => $payment->start_date,
                    'end_date' => $payment->end_date,
                    'created_at' => $payment->created_at,
                    'phone' => $payment->phone
                ];
            })
        ]);
    }

    
    
    public function handlePendingTimeouts()
    {
        $timeoutPayments = Payment::where('status', 'pending')
                                 ->where('created_at', '<', now()->subMinutes(5))
                                 ->get();
    
        foreach ($timeoutPayments as $payment) {
            $payment->update(['status' => 'failed']);
            
            // Generate timeout message
            $message = "Payment timeout. Your transaction of KSH {$payment->amount} was not completed. Please try again.";
            
            // Log the timeout
            $this->saveSMSLog($payment, $message, 'timeout');
            
            \Log::info('Payment marked as failed due to timeout', [
                'payment_id' => $payment->id,
                'created_at' => $payment->created_at,
                'timeout_at' => now()
            ]);
        }
    
        return "Processed {$timeoutPayments->count()} timeout payments";
    }
    private function normalizePhone($phone)
    {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            // Convert to 254 format if it starts with 0
            if (str_starts_with($phone, '0')) {
                $phone = '254' . substr($phone, 1);
            }
            // Ensure it's in 254 format
            elseif (!str_starts_with($phone, '254')) {
                $phone = '254' . ltrim($phone, '254');
            }
            
            return $phone;
    }
    
    
    private function sendSMS($phone, $message, $payment = null)
    {
        try {
            $phoneNumberToUse = $phone;
            $connectionName = 'mysql';
            
            if ($payment) {
                
                if ($payment->platform_id) {
                    $platform = Platform::find($payment->platform_id);
                    if ($platform) {
                    
                        $connectionName = 'platform_' . $platform->id;
                        DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());
                        $logContext = ['connection' => $connectionName];
                    }
                }
    
                // Get escort post using the appropriate connection
                $escortPost = WordpressPost::on($connectionName)
                    ->where('post_author', $payment->user_id)
                    ->where('post_type', 'escort')
                    ->first();
    
                if ($escortPost) {
                    // Get and validate phone number using the same connection
                    $phoneMeta = DB::connection($connectionName)
                        ->table('postmeta')
                        ->where('post_id', $escortPost->ID)
                        ->where('meta_key', 'phone')
                        ->first();
    
                    if ($phoneMeta) {
                        $escortPhone = $this->normalizePhone($phoneMeta->meta_value);
                        
                        if (preg_match('/^254[0-9]{9}$/', $escortPhone)) {
                            $phoneNumberToUse = $escortPhone;
                            \Log::info('Using escort phone number for SMS', [
                                'platform_id' => $payment->platform_id ?? null,
                                'original_phone' => $phone,
                                'escort_phone' => $escortPhone,
                                'post_id' => $escortPost->ID
                            ]);
                        } else {
                            \Log::error('Invalid escort phone format', [
                                'platform_id' => $payment->platform_id ?? null,
                                'phone' => $escortPhone,
                                'post_id' => $escortPost->ID
                            ]);
                        }
                    } else {
                        \Log::error('Phone meta missing', [
                            'platform_id' => $payment->platform_id ?? null,
                            'post_id' => $escortPost->ID,
                            'user_id' => $payment->user_id
                        ]);
                    }
                } else {
                    \Log::error('No escort post found', [
                        'platform_id' => $payment->platform_id ?? null,
                        'user_id' => $payment->user_id
                    ]);
                }
            }
    
            // Send SMS using the specified gateway
            $smsResponse = Http::timeout(15)
                ->retry(2, 500)
                ->post('http://138.201.58.10:8093/SendMessageFON', [
                    'Phonenumber' => $phoneNumberToUse,
                    'OrgCode' => '76',
                    'Message' => $message
                ]);
    
            \Log::info('SMS Gateway Response', [
                'platform_id' => $payment->platform_id ?? null,
                'status' => $smsResponse->status(),
                'body' => $smsResponse->body(),
                'phone_used' => $phoneNumberToUse
            ]);
    
            // Check if the response was successful
            if ($smsResponse->successful()) {
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'response' => $smsResponse->body()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'SMS gateway returned error: ' . $smsResponse->status(),
                    'response' => $smsResponse->body()
                ];
            }
            
        } catch (\Exception $e) {
            \Log::error('SMS sending failed', [
                'platform_id' => $payment->platform_id ?? null,
                'error' => $e->getMessage(),
                'phone' => $phoneNumberToUse ?? $phone,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    // 5. Optional: Timeout handler for pending payments
    public function handleTimeouts()
    {
        $timeoutPayments = Payment::where('status', 'pending')
                                 ->where('created_at', '<', now()->subMinutes(10))
                                 ->get();
    
        foreach ($timeoutPayments as $payment) {
            try {
                $platform = $payment->platform_id ? Platform::find($payment->platform_id) : null;
                
                if ($platform) {
                    $connectionName = 'platform_' . $platform->id;
                    DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());
                }
    
                $payment->update(['status' => 'failed']);
                
                \Log::info('Payment marked as failed due to timeout', [
                    'payment_id' => $payment->id,
                    'platform_id' => $payment->platform_id,
                    'connection' => $connectionName ?? 'default'
                ]);
            } catch (\Exception $e) {
                \Log::error('Timeout handling failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                    'platform_id' => $payment->platform_id
                ]);
            }
        }
    
        return "Processed {$timeoutPayments->count()} timeout payments";
    }
        
    public function list(Request $request)
    {
        $query = Payment::with(['product', 'platform']);

        if ($request->has('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        $payments = $query
            ->latest()
            ->get()
            ->map(function ($payment) {
                $startDate = $payment->start_date 
                    ? (is_string($payment->start_date) 
                        ? Carbon::parse($payment->start_date)->toDateTimeString() 
                        : $payment->start_date->toDateTimeString()) 
                    : null;

                $endDate = $payment->end_date 
                    ? (is_string($payment->end_date) 
                        ? Carbon::parse($payment->end_date)->toDateTimeString() 
                        : $payment->end_date->toDateTimeString()) 
                    : null;

                // 🔹 Get the escort name dynamically from WordPress DB
                $escortName = 'N/A';
                // 🔹 Get the profile URL from uapy1_escort_live_urls table
                $profileUrl = null;
                
                if ($payment->platform_id && $payment->user_id) {
                    $platform = Platform::find($payment->platform_id);

                    if ($platform) {
                        $connectionName = 'platform_' . $platform->id;
                        DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());

                        // Get the escort post first to get the post ID and title
                        $escortPost = WordpressPost::on($connectionName)
                            ->where('post_author', $payment->user_id)
                            ->where('post_type', 'escort')
                            ->first();

                        if ($escortPost) {
                            $escortName = $escortPost->post_title;
                            
                            // 🔹 Get live_url from uapy1_escort_live_urls table using post_id
                            // METHOD 1: Use query with table name without prefix (by getting raw PDO connection)
                            $pdo = DB::connection($connectionName)->getPdo();
                            $stmt = $pdo->prepare("SELECT live_url FROM uapy1_escort_live_urls WHERE post_id = ? LIMIT 1");
                            $stmt->execute([$escortPost->ID]);
                            $liveUrl = $stmt->fetchColumn();
                            
                            // Use live_url if available, otherwise fallback to WordPress GUID
                            $profileUrl = $liveUrl ?: $escortPost->guid;
                        }
                    }
                }

                return [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'escort_name' => $escortName,
                    'profile_url' => $profileUrl, // Now comes from uapy1_escort_live_urls if available
                    'product_id' => $payment->product_id,
                    'platform_id' => $payment->platform_id,
                    'platform_name' => optional($payment->platform)->name ?? 'N/A',
                    'product' => $payment->product->name ?? 'N/A',
                    'phone' => $payment->phone,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency ?? 'KES',
                    'transaction_reference' => $payment->transaction_reference,
                    'transaction_uuid' => $payment->transaction_uuid,
                    'status' => $payment->status,
                    'duration' => $payment->duration,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'created_at' => $payment->created_at->toDateTimeString(),
                    'updated_at' => $payment->updated_at->toDateTimeString(),
                    'is_active' => $endDate ? now()->lt(Carbon::parse($endDate)) : false,
                    'days_remaining' => $endDate ? now()->diffInDays(Carbon::parse($endDate)) : null
                ];
            });

        if ($payments->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'total' => 0,
                'reason' => $request->has('platform_id') 
                    ? 'No payments found for the provided Platform.'
                    : 'No payments found.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'total' => $payments->count(),
            'payments' => $payments
        ]);
    }


    public function manualActivate(Request $request)
    {
            $request->validate([
                'post_id' => 'required|numeric',
                'days' => 'required|numeric|min:1',
                'is_free_trial' => 'sometimes|boolean',
                'product_id' => 'required|exists:products,id',
                'platform_id' => 'required|exists:platforms,id'
            ]);
        
            $postId = $request->post_id;
            $days = $request->days;
            $isFreeTrial = $request->boolean('is_free_trial', false);
            $productId = $request->product_id;
            $platformId = $request->platform_id;
        
            try {
                // Get platform and switch connection
                $platform = Platform::findOrFail($platformId);
                $connectionName = 'platform_' . $platform->id;
                DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());
        
                // Get the product
                $product = Product::findOrFail($productId);
                
                // 1. Check if post exists in this platform
                $post = WordpressPost::on($connectionName)
                    ->where('ID', $postId)
                    ->where('post_type', 'escort')
                    ->first();
        
                if (!$post) {
                    return response()->json([
                        'error' => 'Activation failed',
                        'message' => "User with post ID {$postId} does not exist on platform '{$platform->name}'. Please check the post ID and platform selection."
                    ], 404);
                }
        
                $post->update(['post_status' => 'publish']);
        
                // 2. Update postmeta "notactive" to 0
                DB::connection($connectionName)->table('postmeta')
                    ->updateOrInsert(
                        ['post_id' => $postId, 'meta_key' => 'notactive'],
                        ['meta_value' => '0']
                    );
        
                // 3. Calculate subscription period
                $startDate = now();
                $endDate = now()->addDays($days);
                
                // 4. Set product-specific meta values
                $productName = strtolower($product->name);
                $metaUpdates = [];
                
                if (str_contains($productName, 'vip')) {
                    $metaUpdates = [
                        'premium' => '1',
                        'featured' => '1'
                    ];
                } elseif (str_contains($productName, 'premium')) {
                    $metaUpdates = [
                        'premium' => '1',
                        'featured' => '0'
                    ];
                } else {
                    $metaUpdates = [
                        'premium' => '0',
                        'featured' => '0'
                    ];
                }
                
                // Apply meta updates
                foreach ($metaUpdates as $metaKey => $metaValue) {
                    DB::connection($connectionName)->table('postmeta')
                        ->updateOrInsert(
                            ['post_id' => $postId, 'meta_key' => $metaKey],
                            ['meta_value' => $metaValue]
                        );
                }
                
                // Store subscription dates
                DB::connection($connectionName)->table('postmeta')->updateOrInsert(
                    ['post_id' => $postId, 'meta_key' => 'subscription_start'],
                    ['meta_value' => $startDate->format('Y-m-d H:i:s')]
                );
                
                DB::connection($connectionName)->table('postmeta')->updateOrInsert(
                    ['post_id' => $postId, 'meta_key' => 'subscription_end'],
                    ['meta_value' => $endDate->format('Y-m-d H:i:s')]
                );
        
                // 5. Record activation
                $activation = Activation::updateOrCreate(
                    ['post_id' => $postId],
                    [
                        'activated_at' => $startDate,
                        'expires_at' => $endDate,
                        'is_free_trial' => $isFreeTrial,
                        'product_id' => $productId,
                        'platform_id' => $platformId
                    ]
                );
        
                // 6. Get user phone number from postmeta
                $userPhone = DB::connection($connectionName)->table('postmeta')
                    ->where('post_id', $postId)
                    ->where('meta_key', 'phone')
                    ->value('meta_value');
        
                $normalizedPhone = $this->normalizePhone($userPhone);
        
                // 7. Create a real payment record for proper logging
                $payment = Payment::create([
                    'user_id' => $post->post_author,
                    'product_id' => $productId,
                    'platform_id' => $platformId,
                    'phone' => $normalizedPhone,
                    'amount' => 0,
                    'duration' => 'manual',
                    'status' => 'activated',
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'transaction_reference' => 'MANUAL-ACTIVATE-'.now()->format('YmdHis'),
                    'raw_payload' => [
                        'manual_activation' => true,
                        'post_id' => $postId,
                        'is_free_trial' => $isFreeTrial
                    ]
                ]);
        
                
                $platformUrl = $platform->domain ?? 'your platform';
                $userPostUrl = $this->getUserPostUrl($post->post_author, $platformId);

                $message = $isFreeTrial 
                    ? "Your account is activated for a free trial for {$product->name} Valid until ".$endDate->format('jS F Y').". Enjoy! Your profile: {$userPostUrl}"
                    : "You have been awarded a free trial for {$product->name} subscription, your account is now active until ".$endDate->format('jS F Y').". Thank you! Your profile: {$userPostUrl}";
        
                // 9. Save SMS log with retry mechanism
                $smsLog = SmsLog::create([
                    'payment_id' => $payment->id,
                    'phone' => $normalizedPhone,
                    'message' => $message,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
        
                // Attempt to send SMS with retry
                $maxAttempts = 3;
                $attempt = 1;
                $smsSent = false;
                $lastError = null;
        
                while ($attempt <= $maxAttempts && !$smsSent) {
                    try {
                        $smsResponse = $this->sendSMS($normalizedPhone, $message, $payment);
                        $smsSent = $smsResponse['success'];
                        $lastError = $smsResponse['message'] ?? null;
        
                        $smsLog->update([
                            'status' => $smsSent ? 'sent' : 'failed',
                            'sent_at' => $smsSent ? now() : null,
                            'response' => $lastError,
                            'updated_at' => now()
                        ]);
                    } catch (\Exception $e) {
                        $lastError = $e->getMessage();
                        $smsLog->update([
                            'status' => 'failed',
                            'response' => $lastError,
                            'updated_at' => now()
                        ]);
                    }
                    
                    if (!$smsSent && $attempt < $maxAttempts) {
                        sleep(2);
                    }
                    $attempt++;
                }
                
                // 10. Return response
                return response()->json([
                    'message' => 'Profile activated successfully with product benefits.',
                    'post_id' => $postId,
                    'product' => $product->name,
                    'platform' => $platform->name,
                    'platform_url' => $platformUrl,
                    'expires_at' => $endDate->toDateTimeString(),
                    'meta_updates' => $metaUpdates,
                    'sms_sent' => $smsSent,
                    'sms_log_id' => $smsLog->id,
                    'sms_status' => $smsSent ? 'sent' : 'failed',
                    'phone' => $normalizedPhone,
                    'sms_error' => $smsSent ? null : $lastError
                ]);
        
            } catch (\Exception $e) {
                \Log::error('Manual activation failed: ' . $e->getMessage(), [
                    'post_id' => $postId,
                    'platform_id' => $platformId,
                    'product_id' => $productId ?? null,
                    'exception' => $e->getTraceAsString()
                ]);
                return response()->json([
                    'error' => 'Failed to activate profile with product',
                    'details' => 'System error. Please try again or contact support.'
                ], 500);
            }
    }
        
    public function listActivatedProfiles(Request $request)
    {
        try {
            $query = Activation::query();
            
            if ($request->has('platform_id')) {
                $query->where('platform_id', $request->platform_id);
            }
    
            $activations = $query->get();
    
            $platformIds = $activations->pluck('platform_id')->filter()->unique();
            $platforms = Platform::whereIn('id', $platformIds)->get()->keyBy('id');
    
            $groupedActivations = $activations->groupBy('platform_id');
    
            foreach ($groupedActivations as $platformId => $platformActivations) {
                if (!$platformId || !isset($platforms[$platformId])) {
                    continue;
                }
    
                $platform = $platforms[$platformId];
                $connectionName = 'platform_' . $platform->id;
    
                try {
                    DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());
    
                    $postIds = $platformActivations->pluck('post_id')->toArray();
    
                    $posts = WordpressPost::on($connectionName)
                        ->whereIn('ID', $postIds)
                        ->where('post_type', 'escort')
                        ->get()
                        ->keyBy('ID');
    
                    foreach ($platformActivations as $activation) {
                        $activation->escortPost = $posts[$activation->post_id] ?? null;
                    }
                } catch (\Exception $e) {
                    \Log::error("Failed to fetch posts for platform {$platformId}", [
                        'error' => $e->getMessage(),
                        'platform' => $platformId,
                        'connection' => $connectionName
                    ]);
                    continue;
                }
            }
    
            return response()->json([
                'data' => $activations->map(function ($activation) {
                    return [
                        'post_id' => $activation->post_id,
                        'post_title' => optional($activation->escortPost)->post_title ?? 'Not Found',
                        'post_status' => optional($activation->escortPost)->post_status ?? 'unknown',
                        'platform_id' => $activation->platform_id,
                        'activated_at' => $activation->activated_at,
                        'expires_at' => $activation->expires_at,
                        'is_active' => $activation->expires_at ? now()->lt($activation->expires_at) : false,
                        'days_remaining' => $activation->expires_at ? now()->diffInDays($activation->expires_at) : null
                    ];
                }),
                'meta' => [
                    'total' => $activations->count(),
                    'platform_filter' => $request->has('platform_id') ? $request->platform_id : 'all'
                ]
            ]);
    
        } catch (\Exception $e) {
            \Log::error('Failed to fetch activated profiles: ' . $e->getMessage(), [
                'platform_id' => $request->platform_id ?? null,
                'error' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to retrieve activated profiles',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    public function manualDeactivate(Request $request)
    {
        $request->validate([
            'post_id' => 'required|numeric',
            'product_id' => 'required|exists:products,id',
            'platform_id' => 'required|exists:platforms,id'
        ]);
        
        $postId = $request->post_id;
        $productId = $request->product_id;
        $platformId = $request->platform_id;
    
        try {
            $platform = Platform::findOrFail($platformId);
            $connectionName = 'platform_' . $platform->id;
            DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());
    
            // Get the product
            $product = Product::findOrFail($productId);
            
            // 1. Check if post exists in this platform
            $post = WordpressPost::on($connectionName)
                ->where('ID', $postId)
                ->where('post_type', 'escort')
                ->first();
    
            if (!$post) {
                return response()->json([
                    'error' => 'Deactivation failed',
                    'message' => "User with post ID {$postId} does not exist on platform '{$platform->name}'. Please check the post ID and platform selection."
                ], 404);
            }
    
            $post->update(['post_status' => 'private']);
    
            // 2. Update postmeta
            DB::connection($connectionName)->table('postmeta')
                ->updateOrInsert(
                    ['post_id' => $postId, 'meta_key' => 'notactive'],
                    ['meta_value' => '1']
                );
    
            // 3. Reset premium/featured flags
            $metaUpdates = ['premium' => '0', 'featured' => '0'];
            foreach ($metaUpdates as $metaKey => $metaValue) {
                DB::connection($connectionName)->table('postmeta')
                    ->updateOrInsert(
                        ['post_id' => $postId, 'meta_key' => $metaKey],
                        ['meta_value' => $metaValue]
                    );
            }
    
            // 4. Update activation record
            Activation::where('post_id', $postId)->update([
                'expires_at' => now(),
                'deactivated_at' => now(),
                'platform_id' => $platformId
            ]);
    
            // 5. Get user phone number
            $userPhone = DB::connection($connectionName)->table('postmeta')
                ->where('post_id', $postId)
                ->where('meta_key', 'phone')
                ->value('meta_value');
            $normalizedPhone = $this->normalizePhone($userPhone);
    
            // 6. Create payment record
            $payment = Payment::create([
                'user_id' => $post->post_author,
                'product_id' => $productId,
                'platform_id' => $platformId,
                'phone' => $normalizedPhone,
                'amount' => 0,
                'duration' => 'manual',
                'status' => 'deactivated',
                'transaction_reference' => 'MANUAL-DEACT-'.now()->format('YmdHis'),
                'raw_payload' => [
                    'manual_deactivation' => true,
                    'post_id' => $postId
                ]
            ]);
    
            // 7. Prepare and send SMS with retry - USING domain FIELD
            $platformUrl = $platform->domain ?? 'your platform';
            $userPostUrl = $this->getUserPostUrl($post->post_author, $platformId);
            $message = "Your {$product->name} subscription has been deactivated. Thank you. Your profile: {$userPostUrl}";
    
            // First save the SMS log
            $smsLog = SmsLog::create([
                'payment_id' => $payment->id,
                'phone' => $normalizedPhone,
                'message' => $message,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now()
            ]);
    
            // Then attempt to send with retry
            $maxAttempts = 3;
            $attempt = 1;
            $smsSent = false;
            $lastError = null;
    
            while ($attempt <= $maxAttempts && !$smsSent) {
                try {
                    $smsResponse = $this->sendSMS($normalizedPhone, $message, $payment);
                    $smsSent = $smsResponse['success'];
                    $lastError = $smsResponse['message'] ?? null;
    
                    if ($smsSent) {
                        $smsLog->update([
                            'status' => 'sent',
                            'sent_at' => now(),
                            'response' => $lastError,
                            'updated_at' => now()
                        ]);
                    } else {
                        $smsLog->update([
                            'status' => 'failed',
                            'response' => $lastError,
                            'updated_at' => now()
                        ]);
                    }
                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                    $smsLog->update([
                        'status' => 'failed',
                        'response' => $lastError,
                        'updated_at' => now()
                    ]);
                }
                
                if (!$smsSent && $attempt < $maxAttempts) {
                    sleep(2); 
                }
                $attempt++;
            }
    
            return response()->json([
                'message' => 'Profile deactivated successfully',
                'post_id' => $postId,
                'product' => $product->name,
                'platform' => $platform->name,
                'platform_url' => $platformUrl,
                'deactivated_at' => now()->toDateTimeString(),
                'sms_sent' => $smsSent,
                'sms_log_id' => $smsLog->id,
                'sms_status' => $smsSent ? 'sent' : 'failed',
                'sms_error' => $smsSent ? null : $lastError
            ]);
    
        } catch (\Exception $e) {
            \Log::error('Manual deactivation failed: ' . $e->getMessage(), [
                'post_id' => $postId,
                'platform_id' => $platformId,
                'exception' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to deactivate profile',
                'details' => 'System error. Please try again or contact support.'
            ], 500);
        }
    }
    
    public function listDeactivatedProfiles(Request $request)
    {
        try {
            // Start with base query for deactivated profiles
            $query = Activation::whereNotNull('deactivated_at');
            
            // Apply platform filter if requested
            if ($request->has('platform_id')) {
                $query->where('platform_id', $request->platform_id);
            }
    
            // Get all deactivated profiles
            $deactivations = $query->get();
    
            // Get all unique platform IDs from deactivations
            $platformIds = $deactivations->pluck('platform_id')->filter()->unique();
            $platforms = Platform::whereIn('id', $platformIds)->get()->keyBy('id');
    
            // Group deactivations by platform
            $groupedDeactivations = $deactivations->groupBy('platform_id');
    
            foreach ($groupedDeactivations as $platformId => $platformDeactivations) {
                // Skip if no platform ID or platform not found
                if (!$platformId || !isset($platforms[$platformId])) {
                    continue;
                }
    
                $platform = $platforms[$platformId];
                $connectionName = 'platform_' . $platform->id;
    
                try {
                    // Switch to platform connection
                    DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());
    
                    // Get all post IDs for this platform
                    $postIds = $platformDeactivations->pluck('post_id')->toArray();
    
                    // Fetch posts for this platform
                    $posts = WordpressPost::on($connectionName)
                        ->whereIn('ID', $postIds)
                        ->where('post_type', 'escort')
                        ->get()
                        ->keyBy('ID');
    
                    // Attach posts to their deactivations
                    foreach ($platformDeactivations as $deactivation) {
                        $deactivation->escortPost = $posts[$deactivation->post_id] ?? null;
                    }
                } catch (\Exception $e) {
                    \Log::error("Failed to fetch posts for platform {$platformId}", [
                        'error' => $e->getMessage(),
                        'platform' => $platformId,
                        'connection' => $connectionName
                    ]);
                    continue;
                }
            }
    
            return response()->json([
                'data' => $deactivations->map(function ($deactivation) {
                    return [
                        'post_id' => $deactivation->post_id,
                        'post_title' => optional($deactivation->escortPost)->post_title ?? 'Not Found',
                        'post_status' => optional($deactivation->escortPost)->post_status ?? 'unknown',
                        'platform_id' => $deactivation->platform_id,
                        'product_id' => $deactivation->product_id,
                        'is_free_trial' => $deactivation->is_free_trial,
                        'activated_at' => $deactivation->activated_at,
                        'expires_at' => $deactivation->expires_at,
                        'deactivated_at' => $deactivation->deactivated_at,
                        'days_inactive' => $deactivation->deactivated_at ? now()->diffInDays($deactivation->deactivated_at) : null
                    ];
                }),
                'meta' => [
                    'total' => $deactivations->count(),
                    'platform_filter' => $request->has('platform_id') ? $request->platform_id : 'all'
                ]
            ]);
    
        } catch (\Exception $e) {
            \Log::error('Failed to fetch deactivated profiles: ' . $e->getMessage(), [
                'platform_id' => $request->platform_id ?? null,
                'error' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to retrieve deactivated profiles',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    
    
    public function manualStkPush(Request $request)
    {
        try {
            // 1. Validate request - update duration validation to include weekly
            $validated = $request->validate([
                'product_id'  => 'required|exists:products,id',
                'platform_id' => 'required|exists:platforms,id',
                'user_id'     => 'required|numeric',
                'phone'       => 'required|digits_between:10,13',
                'duration'    => 'required|in:monthly,biweekly,weekly',
                'first_name'  => 'nullable|string|max:255',
                'last_name'   => 'nullable|string|max:255',
                'email'       => 'nullable|email|max:255',
            ]);
    
            // 2. Format phone number
            $phone = $this->formatPhoneNumber($validated['phone']);
    
            // 3. Lookup product/platform + calculate amount using switch
            $product = Product::findOrFail($validated['product_id']);
            $platform = Platform::findOrFail($validated['platform_id']);
    
            $price = 0;
            switch ($validated['duration']) {
                case 'weekly':
                    $price = $product->weekly_price;
                    break;
                case 'biweekly':
                    $price = $product->biweekly_price;
                    break;
                case 'monthly':
                    $price = $product->monthly_price;
                    break;
                default:
                    return response()->json([
                        'status' => false,
                        'error' => 'Invalid duration type: ' . $validated['duration']
                    ], 400);
            }
    
            if ($price <= 0) {
                return response()->json([
                    'status' => false,
                    'error' => 'Invalid product price configuration'
                ], 400);
            }
    
            // 4. Save Payment record in DB (initiated)
            $payment = Payment::create([
                'user_id'     => $validated['user_id'],
                'platform_id' => $platform->id,
                'product_id'  => $product->id,
                'phone'       => $phone, // Use formatted phone
                'amount'      => $price,
                'duration'    => $validated['duration'],
                'status'      => 'initiated'
            ]);
    
            $result = $this->legacyStkService->initiate($payment, [
                'phone' => $phone,
                'duration' => $validated['duration'],
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'email' => $validated['email'] ?? null,
            ]);

            if ($result['success']) {
                $updates = [
                    'status' => 'pending',
                    'failure_reason' => null,
                    'provider_key' => 'mpesa_stk',
                    'provider_environment' => $result['provider_environment'] ?? null,
                    'raw_payload' => [
                        'source' => 'legacy_payment_initiate_v2',
                        'transport' => $result['transport'] ?? null,
                        'upstream_url' => $result['upstream_url'] ?? null,
                        'provider_response' => $result['provider_response'] ?? null,
                    ],
                ];
                if (!empty($result['provider_reference'])) {
                    $updates['transaction_reference'] = $result['provider_reference'];
                }
                $payment->forceFill($updates)->save();

                return response()->json([
                    "status"     => true,
                    "message"    => "STK push initiated successfully",
                    "payment_id" => $payment->id,
                    "amount"     => $price,
                    "duration"   => $validated['duration'],
                    "phone"      => $phone
                ]);
            }

            \Log::warning("STK push failed response", [
                "payment_id" => $payment->id,
                "provider" => $result['provider'] ?? null,
                "transport" => $result['transport'] ?? null,
                "upstream_url" => $result['upstream_url'] ?? null,
                "http_status" => $result['http_status'] ?? null,
                "redirect_location" => $result['redirect_location'] ?? null,
                "response_body" => $result['response_body'] ?? null,
                "provider_response" => $result['provider_response'] ?? null,
            ]);
            $payment->forceFill([
                'status' => 'failed',
                'failure_reason' => mb_substr((string) ($result['message'] ?? 'STK push could not be initiated.'), 0, 190),
                'provider_key' => 'mpesa_stk',
                'provider_environment' => $result['provider_environment'] ?? null,
                'raw_payload' => [
                    'source' => 'legacy_payment_initiate_v2',
                    'transport' => $result['transport'] ?? null,
                    'upstream_url' => $result['upstream_url'] ?? null,
                    'http_status' => $result['http_status'] ?? null,
                    'redirect_location' => $result['redirect_location'] ?? null,
                    'response_body' => $result['response_body'] ?? null,
                    'provider_response' => $result['provider_response'] ?? null,
                ],
            ])->save();

            return response()->json([
                "status" => false,
                "error"  => $result['message'] ?? 'STK push could not be initiated.'
            ], 400);
    
        } catch (\Exception $e) {
            \Log::error("STK push error", ["exception" => $e->getMessage()]);
            return response()->json([
                "status" => false,
                "error"  => "Server error: " . $e->getMessage()
            ], 500);
        }
    }


        
    public function initiateCardPayment(Request $request)
    {
        Log::info('Initiate Payment Request Payload', $request->all());

        $validated = $request->validate([
            'product_id' => 'required|integer',
            'product_name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'user_id' => 'required|integer',
            'platform_id' => 'sometimes|integer',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'duration' => 'required|string|max:50'
        ]);

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 10,
                'verify' => false, // Important for Render.com
            ]);

            // Use the correct Django API URL
            $djangoUrl = 'https://paymentservice-nwg5.onrender.com/api/payments/card/initiate/';
            
            Log::info('Calling Django API', ['url' => $djangoUrl, 'payload' => $validated]);

            $response = $client->post($djangoUrl, [
                'json' => $validated,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]
            ]);

            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody, true);

            Log::info('Django API Response', ['response' => $data]);

            // Check if Django API returned success
            if (!isset($data['status']) || $data['status'] !== true) {
                Log::error('Django API returned error', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $data
                ]);
                
                return response()->json([
                    'status' => false,
                    'message' => 'Payment gateway error',
                    'error' => $data['error'] ?? 'Invalid response from payment gateway'
                ], 500);
            }

            // Store payment record in Laravel
            $payment = Payment::create([
                'user_id' => $validated['user_id'],
                'product_id' => $validated['product_id'],
                'platform_id' => $validated['platform_id'] ?? null,
                'amount' => $validated['price'],
                'currency' => $validated['currency'],
                'duration' => $validated['duration'],
                'status' => 'pending',
                'transaction_uuid' => $data['payment_data']['transaction_uuid'] ?? null,
                'reference_number' => $data['payment_data']['reference_number'] ?? null,
                'payment_data' => $data['payment_data'] ?? null,
            ]);

            Log::info('Payment record created in Laravel', ['payment_id' => $payment->id]);

            return response()->json([
                'status' => true,
                'payment_id' => $payment->id,
                'payment_data' => $data['payment_data'],
                'test_mode' => true // Since you're using test credentials
            ]);

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $response = $e->getResponse();
            $errorBody = $response ? $response->getBody()->getContents() : 'No response body';
            
            Log::error('Django API Connection Failed', [
                'error_message' => $e->getMessage(),
                'status_code' => $response ? $response->getStatusCode() : 'No response',
                'response_body' => $errorBody,
                'url' => $djangoUrl ?? 'Not set'
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Cannot connect to payment gateway',
                'error' => json_decode($errorBody, true)['error'] ?? $e->getMessage()
            ], 503);

        } catch (\Exception $e) {
            Log::error('Payment initiation failed', [
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to initiate payment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }


    private function mapDecisionToStatus(string $decision): string
    {
        switch (strtoupper($decision)) {
            case 'ACCEPT':
                return 'completed';
            case 'DECLINE':
                return 'failed';
            case 'REVIEW':
                return 'under_review';
            case 'ERROR':
                return 'error';
            default:
                return 'unknown';
        }
    }

    public function handleNotification(Request $request)
    {
        try {
            $result = $this->processCybersourceResponse($request);

            return response()->json([
                'status' => $result['ok'],
                'payment_id' => $result['payment']?->id,
                'payment_status' => $result['status'],
                'message' => $result['message'],
            ], $result['ok'] ? 200 : 404);
        } catch (\Throwable $exception) {
            Log::error('CyberSource notification handling failed', [
                'error' => $exception->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to process payment notification',
            ], 500);
        }
    }

    public function paymentResponse(Request $request)
    {
        try {
            $result = $this->processCybersourceResponse($request);

            return $this->renderLegacyPaymentResult(
                $result['status'],
                $result['payment'],
                $result['reason']
            );
        } catch (\Throwable $exception) {
            Log::error('CyberSource browser response handling failed', [
                'error' => $exception->getMessage(),
                'payload' => $request->all(),
            ]);

            return $this->renderLegacyPaymentResult('error', null, 'Payment processing failed. Please contact support.');
        }
    }

    public function paymentCancel(Request $request)
    {
        $payment = $this->resolveLegacyBrowserPayment(
            $request->input('payment_id')
                ?? $request->input('req_reference_number')
                ?? $request->input('reference_number')
                ?? $request->input('transaction_uuid')
        );

        if ($payment && $this->normalizePaymentStatus($payment->status) !== 'completed') {
            $payment->forceFill([
                'status' => 'canceled',
                'failure_reason' => 'Payment canceled by customer',
            ])->save();
        }

        return $this->renderLegacyPaymentResult(
            'canceled',
            $payment,
            'Payment was canceled before completion.'
        );
    }

    public function paymentSuccess(string $id)
    {
        return view('payments.success', [
            'payment' => $this->resolveLegacyBrowserPayment($id)
                ?? $this->placeholderPayment([
                    'status' => 'completed',
                    'reference_number' => $id,
                    'transaction_uuid' => $id,
                ]),
        ]);
    }

    public function paymentFailed(string $id)
    {
        return $this->renderLegacyPaymentResult(
            'failed',
            $this->resolveLegacyBrowserPayment($id),
            'Payment failed. Please try again.'
        );
    }

    public function paymentCanceled(Request $request)
    {
        $identifier = $request->input('payment_id')
            ?? $request->input('req_reference_number')
            ?? $request->input('reference_number')
            ?? $request->input('transaction_uuid');

        return $this->renderLegacyPaymentResult(
            'canceled',
            $this->resolveLegacyBrowserPayment($identifier),
            'Payment was canceled before completion.'
        );
    }

    public function paymentError(Request $request)
    {
        $identifier = $request->input('payment_id')
            ?? $request->input('req_reference_number')
            ?? $request->input('reference_number')
            ?? $request->input('transaction_uuid');

        return $this->renderLegacyPaymentResult(
            'error',
            $this->resolveLegacyBrowserPayment($identifier),
            (string) ($request->input('reason') ?? 'Payment processing failed.')
        );
    }

    private function processCybersourceResponse(Request $request): array
    {
        $decision = strtoupper((string) $request->input('decision', ''));
        $status = $this->mapDecisionToStatus($decision !== '' ? $decision : 'ERROR');
        $payment = $this->resolveLegacyBrowserPayment(
            $request->input('payment_id')
                ?? $request->input('req_reference_number')
                ?? $request->input('reference_number')
                ?? $request->input('transaction_uuid')
        );
        $reason = (string) (
            $request->input('message')
                ?? $request->input('reason')
                ?? $request->input('auth_response')
                ?? ($status === 'completed' ? 'Payment completed successfully.' : 'Payment could not be completed.')
        );

        if (!$payment) {
            Log::warning('CyberSource response could not be matched to a payment', [
                'decision' => $decision,
                'payload' => $request->all(),
            ]);

            return [
                'ok' => false,
                'status' => $status,
                'payment' => null,
                'reason' => $reason,
                'message' => 'Payment not found',
            ];
        }

        $referenceNumber = (string) ($request->input('req_reference_number') ?? $payment->reference_number ?? '');
        $transactionReference = (string) (
            $request->input('transaction_id')
                ?? $request->input('transaction_uuid')
                ?? $request->input('request_id')
                ?? $payment->transaction_reference
                ?? ''
        );

        if ($status === 'completed') {
            if ($this->normalizePaymentStatus($payment->status) !== 'completed') {
                $payment->forceFill([
                    'reference_number' => $referenceNumber !== '' ? $referenceNumber : $payment->reference_number,
                    'transaction_reference' => $transactionReference !== '' ? $transactionReference : $payment->transaction_reference,
                ])->save();

                $this->handleSuccessfulPayment($payment, [
                    'reference' => $transactionReference !== '' ? $transactionReference : $referenceNumber,
                ], null, [
                    'cybersource_response' => $request->all(),
                    'processed_at' => now()->toDateTimeString(),
                ]);
            }
        } else {
            $payment->forceFill([
                'status' => $status === 'unknown' ? 'error' : $status,
                'failure_reason' => $reason,
                'reference_number' => $referenceNumber !== '' ? $referenceNumber : $payment->reference_number,
                'transaction_reference' => $transactionReference !== '' ? $transactionReference : $payment->transaction_reference,
                'raw_payload' => array_merge($payment->raw_payload ?? [], [
                    'cybersource_response' => $request->all(),
                    'processed_at' => now()->toDateTimeString(),
                ]),
            ])->save();
        }

        return [
            'ok' => true,
            'status' => $this->normalizePaymentStatus($payment->fresh()->status) ?? $status,
            'payment' => $payment->fresh(['product']),
            'reason' => $reason,
            'message' => 'Payment notification processed',
        ];
    }

    private function resolveLegacyBrowserPayment($identifier): ?Payment
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $query = Payment::query()->with(['product']);

        if (is_numeric($identifier)) {
            $payment = $query->find((int) $identifier);
            if ($payment) {
                return $payment;
            }
        }

        $identifier = (string) $identifier;

        return $query->where('transaction_uuid', $identifier)
            ->orWhere('reference_number', $identifier)
            ->orWhere('transaction_reference', $identifier)
            ->first();
    }

    private function renderLegacyPaymentResult(string $status, ?Payment $payment, ?string $reason = null)
    {
        $payment = $payment ?? $this->placeholderPayment([
            'status' => $status,
        ]);

        if ($reason !== null && $reason !== '') {
            session()->flash('reason', $reason);
        }

        if ($status === 'completed') {
            return view('payments.success', ['payment' => $payment]);
        }

        $view = $status === 'canceled' ? 'payments.canceled' : 'payments.failed';

        return response()
            ->view($view, ['payment' => $payment])
            ->withCookie(cookie()->forever('legacy_payment_status', $status))
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    private function placeholderPayment(array $attributes = []): Payment
    {
        $payment = new Payment();
        $payment->forceFill(array_merge([
            'amount' => 0,
            'currency' => 'KES',
            'status' => 'failed',
            'reference_number' => 'N/A',
            'transaction_uuid' => null,
            'transaction_reference' => null,
        ], $attributes));
        $payment->created_at = now();
        $payment->updated_at = now();

        return $payment;
    }


    public function paybillCallback(Request $request)
    {
        \Log::info('Incoming Paybill Payload:', $request->all());
    
        try {
            $request->validate([
                'TransID' => 'required|string',
                'TransAmount' => 'required',
                'MSISDN' => 'required|string',
                'BillRefNumber' => 'required|string',
                'TransTime' => 'required|string'
            ]);
    
            $transactionId = $request->TransID;
            $amount = $request->TransAmount;
            $phone = $request->MSISDN;
            $billRef = $request->BillRefNumber;
            $transTime = $request->TransTime;
            $resultCode = '0';
    
            \Log::info('Parsed Paybill Data:', compact(
                'transactionId', 'amount', 'phone', 'billRef', 'transTime'
            ));
    
            // Test-only payment record
            \Log::info('Simulating payment save...');
    
            return response()->json([
                'ResultCode' => $resultCode,
                'ResultDesc' => 'Accepted (test)',
            ]);
        } catch (\Exception $e) {
            \Log::error('Paybill Callback Error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTrace(),
                'payload' => $request->all()
            ]);
    
            return response()->json([
                'ResultCode' => '1',
                'ResultDesc' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
 * Manually update a payment's status (for network failure recovery)
 */
    public function manuallyUpdatePaymentStatus(Request $request)
    {
        try {
            $request->validate([
                'payment_id' => 'required|integer|exists:payments,id',
                'status' => 'required|string|in:completed,reversed,failed',
                'transaction_reference' => 'nullable|string',
            ]);
    
            $payment = Payment::findOrFail($request->payment_id);
    
            if ($payment->status === $request->status) {
                return response()->json([
                    "success" => false,
                    "message" => "Payment is already marked as {$request->status}."
                ], 400);
            }
    
            // Log that this is a manual update
            \Log::info('Manual payment update triggered', [
                'payment_id' => $payment->id,
                'old_status' => $payment->status,
                'new_status' => $request->status,
                'triggered_by' => auth()->user()->id ?? 'system/manual'
            ]);
    
            $payment->transaction_reference = $request->transaction_reference ?? $payment->transaction_reference;
    
            switch ($request->status) {
                case 'completed':
                    $payment->status = 'completed';
                    $payment->save();
    
                    // Trigger the same success flow
                    $this->handleSuccessfulPayment($payment, [
                        'reference' => $payment->transaction_reference
                    ], null, [
                        'manual_update' => true,
                        'processed_at' => now()->toDateTimeString()
                    ]);
    
                    break;
    
                case 'reversed':
                    $payment->status = 'reversed';
                    $payment->save();
    
                    $this->handleReversedPayment([
                        'payment_id' => $payment->id
                    ], null, [
                        'manual_update' => true
                    ]);
    
                    break;
    
                case 'failed':
                    $payment->status = 'failed';
                    $payment->save();
    
                    $this->handleFailedPayment([
                        'payment_id' => $payment->id
                    ], null, [
                        'manual_update' => true
                    ]);
    
                    break;
            }
    
            return response()->json([
                "success" => true,
                "message" => "Payment #{$payment->id} manually updated to {$request->status} successfully.",
                "data" => $payment
            ]);
    
        } catch (\Exception $e) {
            \Log::error('Manual payment update error', ['error' => $e->getMessage()]);
            return response()->json([
                "success" => false,
                "error" => $e->getMessage()
            ], 500);
        }
    }

}
