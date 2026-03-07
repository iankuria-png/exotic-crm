<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Product;
use App\Models\Platform;
use App\Services\WalletCheckoutService;
use App\Services\WalletPayloadService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletCheckoutService $walletCheckoutService,
        private readonly WalletPayloadService $walletPayloadService
    ) {
    }

    public function balance(Request $request)
    {
        [$client, $platform, $context] = $this->resolveWalletClient($request);
        $summary = $this->walletService->summary($client, (int) data_get($context, 'wallet.recent_transactions_limit', 10));

        return response()->json([
            'client' => [
                'id' => (int) $client->id,
                'wp_user_id' => (int) ($client->wp_user_id ?? 0),
                'wp_post_id' => (int) ($client->wp_post_id ?? 0),
                'profile_url' => $client->wp_profile_url,
            ],
            'balance' => $summary['balance'],
            'currency' => $summary['currency'],
            'mode' => $context['mode'],
            'refreshed_at' => $summary['refreshed_at'],
            'wallet_last_synced_at' => $summary['wallet_last_synced_at'],
            'last_topup' => $summary['last_topup'],
            'transactions' => $summary['transactions'],
            'config' => $this->walletPayloadService->config($platform, $context),
        ]);
    }

    public function transactions(Request $request)
    {
        [$client] = $this->resolveWalletClient($request);
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $transactions = $this->walletService->recentTransactions($client, $limit);

        return response()->json([
            'data' => $transactions->map(fn ($transaction) => $this->walletService->serializeTransaction($transaction))->values()->all(),
            'meta' => [
                'limit' => $limit,
            ],
        ]);
    }

    public function subscribe(Request $request)
    {
        [$client, $platform, $context] = $this->resolveWalletClient($request);
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'duration' => 'required|string|max:30',
        ]);

        $product = Product::query()
            ->where('platform_id', (int) $platform->id)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->findOrFail((int) $validated['product_id']);

        try {
            $checkout = $this->walletCheckoutService->payForSubscriptionFromWallet(
                $client,
                $product,
                $validated['duration'],
                (string) $request->attributes->get('wallet_idempotency_key'),
                [
                    'environment' => $request->attributes->get('wallet_environment'),
                    'origin' => 'wp_wallet_subscribe',
                ]
            );
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => str_contains(strtolower($exception->getMessage()), 'insufficient')
                    ? 'insufficient_balance'
                    : 'wallet_subscribe_failed',
            ], 422);
        }

        $summary = $this->walletService->summary($checkout['client'], (int) data_get($context, 'wallet.recent_transactions_limit', 10));

        return response()->json([
            'message' => $checkout['replayed']
                ? 'Wallet subscription request already processed.'
                : 'Subscription paid from wallet.',
            'replayed' => (bool) $checkout['replayed'],
            'payment' => [
                'id' => (int) $checkout['payment']->id,
                'reference_number' => $checkout['payment']->reference_number,
                'status' => $checkout['payment']->status,
                'amount' => number_format((float) $checkout['payment']->amount, 2, '.', ''),
                'currency' => $checkout['payment']->currency,
            ],
            'deal' => $checkout['deal'] ? [
                'id' => (int) $checkout['deal']->id,
                'status' => $checkout['deal']->status,
                'plan_type' => $checkout['deal']->plan_type,
                'expires_at' => optional($checkout['deal']->expires_at)->toIso8601String(),
            ] : null,
            'wallet' => [
                'balance' => $summary['balance'],
                'currency' => $summary['currency'],
                'transaction' => $this->walletService->serializeTransaction($checkout['transaction']),
            ],
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
}
