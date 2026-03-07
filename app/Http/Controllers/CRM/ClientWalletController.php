<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\WalletService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class ClientWalletController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly WalletService $walletService
    ) {
    }

    public function show(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client);

        return response()->json([
            'client_id' => (int) $client->id,
            'platform_id' => (int) $client->platform_id,
            'wallet' => $this->walletService->summary(
                $client,
                (int) data_get($client->platform?->wallet_settings, 'recent_transactions_limit', 10)
            ),
        ]);
    }

    public function transactions(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client);
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $limit = (int) ($validated['limit'] ?? data_get($client->platform?->wallet_settings, 'recent_transactions_limit', 10));
        $transactions = $this->walletService->recentTransactions($client, $limit);

        return response()->json([
            'client_id' => (int) $client->id,
            'data' => $transactions->map(fn ($transaction) => $this->walletService->serializeTransaction($transaction))->values()->all(),
            'meta' => [
                'limit' => $limit,
            ],
        ]);
    }

    public function topup(Request $request, Client $client)
    {
        $this->authorizeManager($request, $client);
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        $before = $this->walletService->summary($client, 5);
        $result = $this->walletService->credit($client, (float) $validated['amount'], [
            'reference_type' => 'admin_topup',
            'reference_id' => (int) $client->id,
            'performed_by' => (int) $request->user()->id,
            'description' => 'CRM admin wallet top-up',
            'metadata' => [
                'reason' => $validated['reason'],
            ],
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_WALLET_TOPUP,
            'client',
            (int) $client->id,
            ['wallet' => $before],
            ['wallet' => $this->walletService->summary($result['client'], 5)],
            $validated['reason']
        );

        return response()->json([
            'message' => 'Wallet top-up recorded.',
            'wallet' => $this->walletService->summary($result['client'], 10),
            'transaction' => $this->walletService->serializeTransaction($result['transaction']),
        ], 201);
    }

    public function adjustment(Request $request, Client $client)
    {
        $this->authorizeManager($request, $client);
        $validated = $request->validate([
            'type' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        $before = $this->walletService->summary($client, 5);

        try {
            $result = $validated['type'] === 'debit'
                ? $this->walletService->debit($client, (float) $validated['amount'], [
                    'reference_type' => 'admin_adjustment',
                    'reference_id' => (int) $client->id,
                    'performed_by' => (int) $request->user()->id,
                    'description' => 'CRM admin wallet debit adjustment',
                    'metadata' => ['reason' => $validated['reason']],
                ])
                : $this->walletService->credit($client, (float) $validated['amount'], [
                    'reference_type' => 'admin_adjustment',
                    'reference_id' => (int) $client->id,
                    'performed_by' => (int) $request->user()->id,
                    'description' => 'CRM admin wallet credit adjustment',
                    'metadata' => ['reason' => $validated['reason']],
                ]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => $validated['type'] === 'debit' ? 'wallet_adjustment_failed' : 'wallet_adjustment_invalid',
            ], 422);
        }

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_WALLET_ADJUSTMENT,
            'client',
            (int) $client->id,
            ['wallet' => $before],
            ['wallet' => $this->walletService->summary($result['client'], 5)],
            $validated['reason']
        );

        return response()->json([
            'message' => 'Wallet adjustment recorded.',
            'wallet' => $this->walletService->summary($result['client'], 10),
            'transaction' => $this->walletService->serializeTransaction($result['transaction']),
        ]);
    }

    private function authorizeClient(Request $request, Client $client): void
    {
        $client->loadMissing('platform');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $client->platform_id,
            'You do not have access to this client wallet.'
        );
    }

    private function authorizeManager(Request $request, Client $client): void
    {
        $this->marketAuthorizationService->ensureManager(
            $request->user(),
            'Only admin or sub-admin users can update client wallets.'
        );
        $this->authorizeClient($request, $client);
    }
}
