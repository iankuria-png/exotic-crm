<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\WalletSettingsService;
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
        private readonly WalletSettingsService $walletSettingsService,
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
                $this->walletSettingsService->runtimeRecentTransactionsLimit($client->platform, 10)
            ),
        ]);
    }

    public function transactions(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client);
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $limit = (int) ($validated['limit'] ?? $this->walletSettingsService->runtimeRecentTransactionsLimit($client->platform, 10));
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
        $this->authorizeWalletOperator($request, $client);
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
        ]);
        $pinError = $this->validateOperatorPin((string) $validated['pin']);
        if ($pinError) {
            return $pinError;
        }

        $before = $this->walletService->summary($client, 5);
        $result = $this->walletService->credit($client, (float) $validated['amount'], [
            'reference_type' => 'admin_topup',
            'reference_id' => (int) $client->id,
            'performed_by' => (int) $request->user()->id,
            'description' => 'CRM wallet top-up',
            'metadata' => [
                'reason' => $validated['reason'],
                'performed_by_role' => (string) $request->user()->role,
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
        $this->authorizeWalletOperator($request, $client);
        $validated = $request->validate([
            'type' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
        ]);
        $pinError = $this->validateOperatorPin((string) $validated['pin']);
        if ($pinError) {
            return $pinError;
        }

        $before = $this->walletService->summary($client, 5);

        try {
            $result = $validated['type'] === 'debit'
                ? $this->walletService->debit($client, (float) $validated['amount'], [
                    'reference_type' => 'admin_adjustment',
                    'reference_id' => (int) $client->id,
                    'performed_by' => (int) $request->user()->id,
                    'description' => 'CRM wallet debit adjustment',
                    'metadata' => [
                        'reason' => $validated['reason'],
                        'performed_by_role' => (string) $request->user()->role,
                    ],
                ])
                : $this->walletService->credit($client, (float) $validated['amount'], [
                    'reference_type' => 'admin_adjustment',
                    'reference_id' => (int) $client->id,
                    'performed_by' => (int) $request->user()->id,
                    'description' => 'CRM wallet credit adjustment',
                    'metadata' => [
                        'reason' => $validated['reason'],
                        'performed_by_role' => (string) $request->user()->role,
                    ],
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

    private function authorizeWalletOperator(Request $request, Client $client): void
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            ['admin', 'sub_admin', 'sales'],
            'Only admin, sub-admin, or sales users can update client wallets.'
        );
        $this->authorizeClient($request, $client);
    }

    private function validateOperatorPin(string $pin)
    {
        if (!$this->walletSettingsService->operatorPinIsConfigured()) {
            return response()->json([
                'message' => 'Wallet PIN is not configured. Ask an admin to set it in Settings.',
                'error_code' => 'wallet_pin_not_configured',
            ], 422);
        }

        if (!$this->walletSettingsService->verifyOperatorPin($pin)) {
            return response()->json([
                'message' => 'Wallet PIN is invalid.',
                'error_code' => 'wallet_pin_invalid',
            ], 403);
        }

        return null;
    }
}
