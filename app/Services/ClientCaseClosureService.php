<?php

namespace App\Services;

use App\Exceptions\ClientCaseClosureException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Payment;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Support\CrmAuditAction;
use App\Support\CrmClientChurnReason;
use App\Support\CrmClientCloseReason;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientCaseClosureService
{
    public const SOFT_CLOSE_DAYS = 30;

    private const CASCADE_PAYMENT_STATUSES = ['failed', 'initiated', 'pending'];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly ClientChurnStamper $churnStamper,
    ) {
    }

    public function close(
        Client $client,
        string $reasonCode,
        ?string $note,
        User $actor,
        Request $request,
    ): array {
        $this->validateReason($reasonCode, $note);

        $client->refresh();

        if ($client->closed_at !== null) {
            throw ClientCaseClosureException::alreadyClosed($client);
        }

        $activeDeal = $client->deals()->where('status', 'active')->first();
        if ($activeDeal !== null) {
            throw ClientCaseClosureException::activeSubscription($client, $activeDeal);
        }

        $beforeState = [
            'closed_at' => null,
            'first_contact_at' => optional($client->first_contact_at)?->toDateTimeString(),
            'last_contact_at' => optional($client->last_contact_at)?->toDateTimeString(),
            'assigned_to' => $client->assigned_to,
            'deals_count' => $client->deals()->count(),
            'open_payments_count' => $client->payments()
                ->whereIn('status', self::CASCADE_PAYMENT_STATUSES)
                ->where('reconciliation_state', '!=', 'resolved')
                ->count(),
        ];

        $platformId = (int) $client->platform_id;
        $actorId = (int) $actor->id;
        $now = now();
        $purgeAfter = $now->copy()->addDays(self::SOFT_CLOSE_DAYS);
        $reasonLabel = CrmClientCloseReason::label($reasonCode);
        $trimmedNote = $this->trimmedNote($note);

        $result = DB::transaction(function () use (
            $client,
            $reasonCode,
            $reasonLabel,
            $trimmedNote,
            $actorId,
            $platformId,
            $beforeState,
            $now,
            $purgeAfter,
            $request,
        ): array {
            Client::withoutRetentionRefresh(function () use ($client, $reasonCode, $trimmedNote, $actorId, $now, $purgeAfter): void {
                $client->forceFill([
                    'closed_at' => $now,
                    'close_reason_code' => $reasonCode,
                    'close_reason_note' => $trimmedNote,
                    'closed_by' => $actorId,
                    'purge_after' => $purgeAfter,
                ])->save();
            });

            TimelineEvent::create([
                'platform_id' => $platformId,
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'event_type' => 'client_case_closed',
                'actor_id' => $actorId,
                'content' => [
                    'reason_code' => $reasonCode,
                    'reason_label' => $reasonLabel,
                    'note' => $trimmedNote,
                    'purge_after' => $purgeAfter->toDateTimeString(),
                ],
                'created_at' => $now,
            ]);

            $auditReason = $reasonLabel . ($trimmedNote !== null ? ' — ' . $trimmedNote : '');

            $audit = $this->auditService->fromRequest(
                $request,
                $platformId,
                CrmAuditAction::CLIENT_CLOSE_CASE,
                'client',
                (int) $client->id,
                $beforeState,
                [
                    'closed_at' => $now->toDateTimeString(),
                    'close_reason_code' => $reasonCode,
                    'close_reason_note' => $trimmedNote,
                    'purge_after' => $purgeAfter->toDateTimeString(),
                ],
                $auditReason,
            );

            $clientCloseAuditId = $audit?->id;

            $cascadedPaymentIds = $this->cascadeOpenPayments(
                $client,
                $reasonCode,
                $reasonLabel,
                $trimmedNote,
                $actorId,
                $platformId,
                $clientCloseAuditId,
                $now,
                $request,
            );

            return [
                'cascaded_payment_ids' => $cascadedPaymentIds,
                'client_close_audit_id' => $clientCloseAuditId,
            ];
        });

        // Stamp churn for paid clients (those who activated at least one deal).
        // Never-paid case closures belong in Closed Cases, not Churned queue.
        $client->refresh();
        if ($client->first_activated_at !== null) {
            $churnReasonCode = CrmClientChurnReason::fromCloseCase($reasonCode);
            $this->churnStamper->stamp($client, $churnReasonCode, 'case_closed', $purgeAfter->copy()->subDays(ClientCaseClosureService::SOFT_CLOSE_DAYS));
        }

        return [
            'client' => $client->fresh(['platform', 'closedBy']),
            'cascaded_payment_ids' => $result['cascaded_payment_ids'],
            'cascaded_payments_count' => count($result['cascaded_payment_ids']),
            'purge_after' => $purgeAfter->toDateTimeString(),
        ];
    }

    public function reopen(Client $client, ?string $note, User $actor, Request $request): Client
    {
        $client->refresh();

        if ($client->closed_at === null) {
            throw ClientCaseClosureException::notClosed($client);
        }

        if ($client->purge_after !== null && $client->purge_after->isPast()) {
            throw ClientCaseClosureException::pastPurgeWindow($client);
        }

        $beforeState = [
            'closed_at' => optional($client->closed_at)?->toDateTimeString(),
            'close_reason_code' => $client->close_reason_code,
            'close_reason_note' => $client->close_reason_note,
            'closed_by' => $client->closed_by,
            'purge_after' => optional($client->purge_after)?->toDateTimeString(),
        ];

        $platformId = (int) $client->platform_id;
        $actorId = (int) $actor->id;
        $now = now();
        $trimmedNote = $this->trimmedNote($note);
        $previousReasonCode = $client->close_reason_code;

        DB::transaction(function () use ($client, $platformId, $actorId, $previousReasonCode, $trimmedNote, $now, $beforeState, $request): void {
            Client::withoutRetentionRefresh(function () use ($client): void {
                $client->forceFill([
                    'closed_at' => null,
                    'close_reason_code' => null,
                    'close_reason_note' => null,
                    'closed_by' => null,
                    'purge_after' => null,
                ])->save();
            });

            TimelineEvent::create([
                'platform_id' => $platformId,
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'event_type' => 'client_case_reopened',
                'actor_id' => $actorId,
                'content' => [
                    'previous_reason_code' => $previousReasonCode,
                    'note' => $trimmedNote,
                ],
                'created_at' => $now,
            ]);

            $this->auditService->fromRequest(
                $request,
                $platformId,
                CrmAuditAction::CLIENT_REOPEN,
                'client',
                (int) $client->id,
                $beforeState,
                [
                    'closed_at' => null,
                    'reopened_at' => $now->toDateTimeString(),
                ],
                $trimmedNote ?? 'Case reopened',
            );
        });

        return $client->fresh(['platform', 'closedBy']);
    }

    public function bulkClose(
        array $clientIds,
        string $reasonCode,
        ?string $note,
        User $actor,
        Request $request,
    ): array {
        $this->validateReason($reasonCode, $note);

        $clients = Client::query()
            ->whereIn('id', $clientIds)
            ->orderBy('id')
            ->get();

        $results = [];
        $successByPlatform = [];

        foreach ($clients as $client) {
            try {
                $closed = $this->close($client, $reasonCode, $note, $actor, $request);
                $results[] = [
                    'client_id' => (int) $client->id,
                    'success' => true,
                    'cascaded_payments_count' => $closed['cascaded_payments_count'],
                ];
                $successByPlatform[(int) $client->platform_id][] = (int) $client->id;
            } catch (ClientCaseClosureException $exception) {
                $results[] = [
                    'client_id' => (int) $client->id,
                    'success' => false,
                    'error_code' => $exception->errorCode(),
                    'error_message' => $exception->getMessage(),
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'client_id' => (int) $client->id,
                    'success' => false,
                    'error_code' => 'unexpected_error',
                    'error_message' => $exception->getMessage(),
                ];
            }
        }

        // Report missing ids as failures so the FE can surface them.
        $foundIds = $clients->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($clientIds as $requestedId) {
            $requestedId = (int) $requestedId;
            if (!in_array($requestedId, $foundIds, true)) {
                $results[] = [
                    'client_id' => $requestedId,
                    'success' => false,
                    'error_code' => 'not_found',
                    'error_message' => 'Client not found.',
                ];
            }
        }

        $successCount = collect($results)->where('success', true)->count();
        $errorCount = count($results) - $successCount;

        // One audit summary per platform (matches CLIENT_BULK_DELETE precedent).
        foreach ($successByPlatform as $platformId => $clientIdsForPlatform) {
            $this->auditService->fromRequest(
                $request,
                (int) $platformId,
                CrmAuditAction::CLIENT_BULK_CLOSE_CASE,
                'platform',
                (int) $platformId,
                ['requested_client_ids' => $clientIds],
                [
                    'total' => count($clientIds),
                    'success_count' => count($clientIdsForPlatform),
                    'error_count' => $errorCount,
                    'reason_code' => $reasonCode,
                    'client_ids' => $clientIdsForPlatform,
                ],
                CrmClientCloseReason::label($reasonCode),
            );
        }

        return [
            'summary' => [
                'total' => count($results),
                'success' => $successCount,
                'errors' => $errorCount,
                'reason_code' => $reasonCode,
            ],
            'results' => $results,
        ];
    }

    private function cascadeOpenPayments(
        Client $client,
        string $reasonCode,
        string $reasonLabel,
        ?string $note,
        int $actorId,
        int $platformId,
        ?int $clientCloseAuditId,
        \DateTimeInterface $now,
        Request $request,
    ): array {
        $cascadedIds = [];

        $payments = Payment::query()
            ->where('client_id', $client->id)
            ->whereIn('status', self::CASCADE_PAYMENT_STATUSES)
            ->where(function ($q) {
                $q->where('reconciliation_state', '!=', 'resolved')
                    ->orWhereNull('reconciliation_state');
            })
            ->get();

        foreach ($payments as $payment) {
            $beforeState = [
                'status' => $payment->status,
                'reconciliation_state' => $payment->reconciliation_state,
                'resolution_code' => $payment->resolution_code,
            ];

            $meta = is_array($payment->resolution_meta_json) ? $payment->resolution_meta_json : [];
            $meta['closed_via_client'] = [
                'closed_via_client_id' => (int) $client->id,
                'closed_via_client_audit_id' => $clientCloseAuditId,
                'reason_code' => $reasonCode,
                'reason_label' => $reasonLabel,
                'note' => $note,
                'actor_id' => $actorId,
                'closed_at' => $now->format('Y-m-d H:i:s'),
            ];

            $payment->forceFill([
                'reconciliation_state' => 'resolved',
                'resolution_code' => $reasonCode,
                'resolution_meta_json' => $meta,
            ])->save();

            $this->paymentAttemptService->record(
                $payment,
                'closed_via_client',
                'closed',
                [
                    'provider' => 'crm_operator',
                    'error_code' => 'closed_via_client',
                    'error_message' => $reasonLabel . ($note ? ' — ' . $note : ''),
                    'request_meta' => $this->paymentAttemptService->requestMetaFromRequest($request, [
                        'client_id' => (int) $client->id,
                        'reason_code' => $reasonCode,
                    ]),
                    'response_meta' => [
                        'reason_code' => $reasonCode,
                        'closed_via_client_audit_id' => $clientCloseAuditId,
                    ],
                    'created_by' => $actorId,
                ],
            );

            $this->auditService->fromRequest(
                $request,
                $platformId,
                CrmAuditAction::PAYMENT_CLOSE_VIA_CLIENT,
                'payment',
                (int) $payment->id,
                $beforeState,
                [
                    'reconciliation_state' => 'resolved',
                    'resolution_code' => $reasonCode,
                    'closed_via_client_audit_id' => $clientCloseAuditId,
                ],
                $reasonLabel,
            );

            $cascadedIds[] = (int) $payment->id;
        }

        return $cascadedIds;
    }

    private function validateReason(string $reasonCode, ?string $note): void
    {
        if (!CrmClientCloseReason::isValid($reasonCode)) {
            throw ClientCaseClosureException::invalidReason($reasonCode);
        }

        if (CrmClientCloseReason::requiresNote($reasonCode) && trim((string) $note) === '') {
            throw ClientCaseClosureException::noteRequired($reasonCode);
        }
    }

    private function trimmedNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : $trimmed;
    }
}
