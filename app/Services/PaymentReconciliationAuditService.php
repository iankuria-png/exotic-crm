<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentReconciliationBatch;
use App\Models\PaymentReconciliationRow;
use App\Models\Platform;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SplFileObject;

class PaymentReconciliationAuditService
{
    private const RESOLVED_REVIEW_STATUSES = ['confirmed_fraud', 'cleared', 'linked'];
    private const REVIEW_STATUSES = ['pending', 'reviewing', 'confirmed_fraud', 'cleared'];

    public function __construct(
        private readonly PaymentImportParserService $parserService
    ) {
    }

    /**
     * @param array<int,Platform> $platforms One or more markets to reconcile against. The first is the
     *                                        primary market used for authorization/audit; transaction
     *                                        codes are matched across ALL of them.
     */
    public function preview(UploadedFile|string $input, array $platforms, int $actorId, bool $hasHeader = true, ?string $reason = null): array
    {
        $platforms = array_values(array_filter($platforms));
        if (empty($platforms)) {
            throw new InvalidArgumentException('At least one market must be selected.');
        }

        return DB::transaction(function () use ($input, $platforms, $actorId, $hasHeader, $reason): array {
            [$parsed, $sourceType, $fileName, $mimeType, $metadata] = $this->parseInput($input, $hasHeader);
            $rows = $this->normalizeParsedRows($parsed, (bool) ($metadata['headerless'] ?? false));

            if (count($rows) === 0) {
                throw new InvalidArgumentException('The reconciliation source has no data rows.');
            }

            $primary = $platforms[0];
            $platformIds = array_values(array_unique(array_map(fn(Platform $p) => (int) $p->id, $platforms)));
            $fallbackCurrency = $this->resolveSharedCurrency($platforms);

            $batch = PaymentReconciliationBatch::query()->create([
                'platform_id' => (int) $primary->id,
                'platform_ids' => $platformIds,
                'fallback_currency' => $fallbackCurrency,
                'uploaded_by' => $actorId,
                'file_name' => $fileName,
                'file_mime' => $mimeType,
                'source_type' => $sourceType,
                'status' => 'reviewing',
                'reason' => $reason ? trim($reason) : null,
                'metadata' => [
                    'columns' => $parsed['headers'] ?? [],
                    'markets' => array_map(fn(Platform $p) => [
                        'id' => (int) $p->id,
                        'name' => $p->name,
                        'currency_code' => $p->currency_code ?: null,
                    ], $platforms),
                    ...$metadata,
                ],
            ]);

            $paymentMap = $this->buildPaymentMap($platformIds);
            $seenReferences = [];
            $responseRows = [];
            $counts = [
                'total_rows' => 0,
                'matched_rows' => 0,
                'mismatch_rows' => 0,
                'missing_rows' => 0,
                'unverifiable_rows' => 0,
                'duplicate_rows' => 0,
                'resolved_rows' => 0,
            ];

            foreach ($rows as $row) {
                $draft = $this->classifyRow($row, $paymentMap, $seenReferences, $platformIds, $fallbackCurrency);

                if ($draft['transaction_reference_norm']) {
                    $seenReferences[$draft['transaction_reference_norm']] = true;
                }

                $rowModel = PaymentReconciliationRow::query()->create([
                    'batch_id' => $batch->id,
                    'row_number' => $draft['row_number'],
                    'raw_row' => $draft['raw_row'],
                    'external_name' => $draft['external_name'],
                    'external_amount' => $draft['external_amount'],
                    'external_currency' => $draft['external_currency'],
                    'external_paid_at_text' => $draft['external_paid_at_text'],
                    'external_reference_raw' => $draft['external_reference_raw'],
                    'transaction_reference_norm' => $draft['transaction_reference_norm'],
                    'classification' => $draft['classification'],
                    'flags' => $draft['flags'],
                    'matched_payment_id' => $draft['matched_payment_id'],
                    'matched_client_id' => $draft['matched_client_id'],
                    'matched_confirmed_by' => $draft['matched_confirmed_by'],
                    'matched_platform_id' => $draft['matched_platform_id'],
                    'match_basis' => $draft['match_basis'],
                    'review_status' => 'pending',
                ]);

                $responseRows[] = $this->serializeRow($rowModel);
                $this->incrementClassificationCount($counts, $draft['classification']);
            }

            $counts['total_rows'] = count($responseRows);
            $batch->update($counts);

            return [
                'batch_id' => (int) $batch->id,
                'status' => $batch->status,
                'source_type' => $sourceType,
                'summary' => $counts,
                'headers' => $parsed['headers'] ?? [],
                'rows' => $responseRows,
            ];
        });
    }

    /**
     * Returns the single currency shared by every selected market, or null when they differ
     * (mixed-currency batch — per-row currency then comes from the matched payment only).
     *
     * @param array<int,Platform> $platforms
     */
    private function resolveSharedCurrency(array $platforms): ?string
    {
        $currencies = [];
        foreach ($platforms as $platform) {
            $code = $this->normalizeCurrencyOrNull($platform->currency_code);
            if ($code !== null) {
                $currencies[$code] = true;
            }
        }

        return count($currencies) === 1 ? array_key_first($currencies) : null;
    }

    public function updateRowReview(PaymentReconciliationRow $row, string $status, ?string $note, int $actorId): array
    {
        if (!in_array($status, self::REVIEW_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid review status.');
        }

        return DB::transaction(function () use ($row, $status, $note, $actorId): array {
            /** @var PaymentReconciliationRow $lockedRow */
            $lockedRow = PaymentReconciliationRow::query()
                ->with('batch')
                ->whereKey($row->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureBatchIsOpen($lockedRow);

            $before = $this->rowReviewState($lockedRow);

            $lockedRow->update([
                'review_status' => $status,
                'review_note' => $note ? trim($note) : null,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
            ]);

            $this->refreshResolvedRows($lockedRow->batch);
            $lockedRow->refresh();

            return [
                'row' => $lockedRow,
                'before' => $before,
                'after' => $this->rowReviewState($lockedRow),
            ];
        });
    }

    /**
     * Apply a single review status to many rows of one batch in a single transaction.
     *
     * @param array<int,int> $rowIds
     */
    public function bulkUpdateReview(PaymentReconciliationBatch $batch, array $rowIds, string $status, ?string $note, int $actorId): array
    {
        if (!in_array($status, self::REVIEW_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid review status.');
        }

        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));
        if (empty($rowIds)) {
            throw new InvalidArgumentException('No rows selected.');
        }

        return DB::transaction(function () use ($batch, $rowIds, $status, $note, $actorId): array {
            /** @var PaymentReconciliationBatch $lockedBatch */
            $lockedBatch = PaymentReconciliationBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($lockedBatch->status === 'closed') {
                throw new InvalidArgumentException('This reconciliation batch is closed. Reopen it before making row changes.');
            }

            $updated = PaymentReconciliationRow::query()
                ->where('batch_id', $lockedBatch->id)
                ->whereIn('id', $rowIds)
                ->update([
                    'review_status' => $status,
                    'review_note' => $note ? trim($note) : null,
                    'reviewed_by' => $actorId,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->refreshResolvedRows($lockedBatch);

            return [
                'batch' => $lockedBatch->fresh(),
                'updated' => (int) $updated,
                'status' => $status,
            ];
        });
    }

    public function linkRowToPayment(PaymentReconciliationRow $row, Payment $payment, int $actorId, ?string $note): array
    {
        return DB::transaction(function () use ($row, $payment, $actorId, $note): array {
            /** @var PaymentReconciliationRow $lockedRow */
            $lockedRow = PaymentReconciliationRow::query()
                ->with('batch')
                ->whereKey($row->id)
                ->lockForUpdate()
                ->firstOrFail();
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->ensureBatchIsOpen($lockedRow);

            if (!in_array((int) $lockedPayment->platform_id, $lockedRow->batch->platformIdSet(), true)) {
                throw new InvalidArgumentException('This reconciliation row can only be linked to a payment in one of the batch markets.');
            }

            $rowBefore = $this->rowReviewState($lockedRow);
            $paymentBefore = [
                'reconciliation_state' => $lockedPayment->reconciliation_state,
                'reconciliation_confidence' => $lockedPayment->reconciliation_confidence,
            ];

            $lockedRow->update([
                'matched_payment_id' => $lockedPayment->id,
                'matched_client_id' => $lockedPayment->client_id,
                'matched_confirmed_by' => $lockedPayment->confirmed_by,
                'matched_platform_id' => $lockedPayment->platform_id,
                'review_status' => 'linked',
                'review_note' => $note ? trim($note) : null,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'match_basis' => array_filter([
                    ...(is_array($lockedRow->match_basis) ? $lockedRow->match_basis : []),
                    'manual_link' => [
                        'payment_id' => (int) $lockedPayment->id,
                        'actor_id' => $actorId,
                    ],
                ]),
            ]);

            $lockedPayment->forceFill([
                'reconciliation_state' => 'manual_review',
            ])->save();

            $this->refreshResolvedRows($lockedRow->batch);
            $lockedRow->refresh();
            $lockedPayment->refresh();

            return [
                'row' => $lockedRow,
                'payment' => $lockedPayment,
                'row_before' => $rowBefore,
                'row_after' => $this->rowReviewState($lockedRow),
                'payment_before' => $paymentBefore,
                'payment_after' => [
                    'reconciliation_state' => $lockedPayment->reconciliation_state,
                    'reconciliation_confidence' => $lockedPayment->reconciliation_confidence,
                ],
            ];
        });
    }

    public function candidatesForRow(PaymentReconciliationRow $row): array
    {
        $row->loadMissing('batch');
        $this->ensureBatchIsOpen($row);

        $platformIds = $row->batch->platformIdSet();
        $candidates = [];

        if ($row->transaction_reference_norm) {
            $payments = Payment::query()
                ->with(['client:id,name', 'platform:id,name'])
                ->whereIn('platform_id', $platformIds)
                ->whereNotNull('transaction_reference')
                ->get(['id', 'platform_id', 'client_id', 'amount', 'currency', 'transaction_reference', 'status', 'confirmed_by', 'created_at']);

            foreach ($payments as $payment) {
                if ($this->normalizeReference($payment->transaction_reference) === $row->transaction_reference_norm) {
                    $candidates[] = $this->serializePaymentCandidate($payment, 'reference_exact');
                }
            }
        }

        if ($row->external_name) {
            $clientIds = Client::query()
                ->whereIn('platform_id', $platformIds)
                ->where('name', 'like', '%' . $row->external_name . '%')
                ->limit(20)
                ->pluck('id');

            if ($clientIds->isNotEmpty()) {
                Payment::query()
                    ->with(['client:id,name', 'platform:id,name'])
                    ->whereIn('platform_id', $platformIds)
                    ->whereIn('client_id', $clientIds)
                    ->latest()
                    ->limit(20)
                    ->get(['id', 'platform_id', 'client_id', 'amount', 'currency', 'transaction_reference', 'status', 'confirmed_by', 'created_at'])
                    ->each(function (Payment $payment) use (&$candidates) {
                        $candidates[] = $this->serializePaymentCandidate($payment, 'client_name');
                    });
            }
        }

        if ($row->external_amount !== null) {
            Payment::query()
                ->with(['client:id,name', 'platform:id,name'])
                ->whereIn('platform_id', $platformIds)
                ->whereBetween('amount', [(float) $row->external_amount - 0.5, (float) $row->external_amount + 0.5])
                ->latest()
                ->limit(20)
                ->get(['id', 'platform_id', 'client_id', 'amount', 'currency', 'transaction_reference', 'status', 'confirmed_by', 'created_at'])
                ->each(function (Payment $payment) use (&$candidates) {
                    $candidates[] = $this->serializePaymentCandidate($payment, 'amount_exact');
                });
        }

        return collect($candidates)
            ->unique('payment_id')
            ->values()
            ->all();
    }

    private function parseInput(UploadedFile|string $input, bool $hasHeader): array
    {
        if ($input instanceof UploadedFile) {
            $parsed = $this->parserService->parseUploadedFile($input, $hasHeader);

            return [
                $parsed,
                'spreadsheet',
                $input->getClientOriginalName(),
                $input->getClientMimeType(),
                [
                    'has_header' => $hasHeader,
                    'headerless' => !$hasHeader,
                ],
            ];
        }

        $paste = trim($input);
        if ($paste === '') {
            throw new InvalidArgumentException('Paste content cannot be empty.');
        }

        if (!str_contains($paste, "\n") && !str_contains($paste, ',') && !str_contains($paste, "\t")) {
            return [
                [
                    'headers' => ['transaction_id'],
                    'rows' => [[
                        'row_number' => 1,
                        'values' => ['transaction_id' => $paste],
                        'raw' => [$paste],
                    ]],
                ],
                'paste',
                'pasted-code.csv',
                'text/csv',
                [
                    'has_header' => true,
                    'single_code' => true,
                    'headerless' => false,
                ],
            ];
        }

        $firstLine = strtok($paste, "\r\n") ?: '';
        $pasteHasHeader = $this->lineLooksLikeHeader($firstLine);
        $tempPath = tempnam(sys_get_temp_dir(), 'payment-recon-paste-');
        if ($tempPath === false) {
            throw new InvalidArgumentException('Unable to prepare pasted rows for parsing.');
        }

        $csvPath = $tempPath . '.csv';
        rename($tempPath, $csvPath);

        $file = new SplFileObject($csvPath, 'wb');
        foreach (preg_split('/\r\n|\r|\n/', $paste) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $delimiter = str_contains($line, "\t") ? "\t" : ',';
            $cells = str_getcsv($line, $delimiter);
            $file->fputcsv($cells);
        }
        $file = null;

        $uploaded = new UploadedFile($csvPath, 'pasted-reconciliation.csv', 'text/csv', null, true);
        $parsed = $this->parserService->parseUploadedFile($uploaded, $pasteHasHeader);
        @unlink($csvPath);

        return [
            $parsed,
            'paste',
            'pasted-reconciliation.csv',
            'text/csv',
            [
                'has_header' => $pasteHasHeader,
                'headerless' => !$pasteHasHeader,
                'delimiter_normalized' => true,
            ],
        ];
    }

    private function normalizeParsedRows(array $parsed, bool $headerless): array
    {
        $rows = [];
        foreach (($parsed['rows'] ?? []) as $row) {
            $values = $row['values'] ?? [];
            if ($headerless) {
                $values = $this->mapHeaderlessValues($values);
            }

            $rows[] = [
                'row_number' => (int) ($row['row_number'] ?? count($rows) + 1),
                'values' => $values,
            ];
        }

        return $rows;
    }

    private function mapHeaderlessValues(array $values): array
    {
        $mapped = [];
        $aliases = [
            'column_1' => 'client_name',
            'column_2' => 'amount_paid',
            'column_3' => 'date_paid',
            'column_4' => 'transaction_id',
            'column_5' => 'activated',
            'column_6' => 'who_activated',
            'column_7' => 'crm_transaction_id',
        ];

        foreach ($values as $key => $value) {
            $mapped[$aliases[$key] ?? $key] = $value;
        }

        return $mapped;
    }

    /**
     * @param array<int,int> $platformIds The markets the batch covers (codes matched across all).
     */
    private function classifyRow(array $row, array $paymentMap, array $seenReferences, array $platformIds, ?string $fallbackCurrency): array
    {
        $values = $row['values'] ?? [];
        $externalName = $this->firstNonEmpty($values, ['client_name', 'name', 'client']);
        $externalAmount = $this->parseAmount($this->firstNonEmpty($values, ['amount_paid', 'amount', 'paid_amount']));
        // Currency from the sheet if present, otherwise resolved per-row below (matched payment, then
        // the batch's shared currency). Never hardcoded.
        $rowCurrency = $this->normalizeCurrencyOrNull($this->firstNonEmpty($values, ['currency', 'currency_code']));
        $externalPaidAtText = $this->firstNonEmpty($values, ['date_paid', 'date', 'payment_date']);
        $externalReferenceRaw = $this->firstNonEmpty($values, ['transaction_id', 'transaction_reference', 'reference', 'receipt', 'crm_transaction_id']);
        $reference = $this->usableReference($externalReferenceRaw);

        $classification = 'unverifiable';
        $flags = [];
        $matchBasis = [];
        $matchedPaymentId = null;
        $matchedClientId = null;
        $matchedConfirmedBy = null;
        $matchedPlatformId = null;
        $externalCurrency = $rowCurrency ?? $fallbackCurrency;

        if ($reference && isset($seenReferences[$reference])) {
            $classification = 'duplicate_in_file';
            $matchBasis = ['duplicate_reference' => $reference];
        } elseif ($reference) {
            $hits = $paymentMap[$reference] ?? [];
            if (count($hits) === 1) {
                $hit = $hits[0];
                $classification = 'matched';
                $matchedPaymentId = $hit['payment_id'];
                $matchedClientId = $hit['client_id'];
                $matchedConfirmedBy = $hit['confirmed_by'];
                $matchedPlatformId = $hit['platform_id'];
                // The matched payment is authoritative for currency display.
                $externalCurrency = $rowCurrency ?? $hit['currency'] ?? $fallbackCurrency;
                $matchBasis = [
                    'basis' => 'reference_exact',
                    'payment_status' => $hit['status'],
                    'payment_reference' => $hit['transaction_reference'],
                    'platform_id' => $hit['platform_id'],
                ];

                if ($externalAmount !== null && $hit['amount'] !== null && abs($externalAmount - (float) $hit['amount']) > 0.5) {
                    $classification = 'amount_mismatch';
                    $flags['amount_delta'] = round($externalAmount - (float) $hit['amount'], 2);
                    $flags['crm_amount'] = (float) $hit['amount'];
                }

                if ($externalName && $hit['client_name'] && $this->normalizeName($externalName) !== $this->normalizeName($hit['client_name'])) {
                    $flags['name_mismatch'] = [
                        'external_name' => $externalName,
                        'crm_name' => $hit['client_name'],
                    ];
                }
            } elseif (count($hits) > 1) {
                $classification = 'duplicate_in_crm';
                // A code recorded in more than one market is itself a fraud signal.
                $platformsHit = array_values(array_unique(array_map(fn($h) => $h['platform_id'], $hits)));
                $flags['cross_market'] = count($platformsHit) > 1;
                $matchBasis = [
                    'basis' => 'reference_collision',
                    'payments' => $hits,
                    'platform_ids' => $platformsHit,
                ];
            } else {
                $classification = 'missing';
                $matchBasis = [
                    'basis' => 'reference_not_found',
                    'platform_ids' => $platformIds,
                ];
            }
        } else {
            $matchBasis = ['basis' => 'no_usable_reference'];
        }

        return [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'raw_row' => $values,
            'external_name' => $externalName,
            'external_amount' => $externalAmount,
            'external_currency' => $externalCurrency,
            'external_paid_at_text' => $externalPaidAtText,
            'external_reference_raw' => $externalReferenceRaw,
            'transaction_reference_norm' => $reference,
            'classification' => $classification,
            'flags' => $flags,
            'matched_payment_id' => $matchedPaymentId,
            'matched_client_id' => $matchedClientId,
            'matched_confirmed_by' => $matchedConfirmedBy,
            'matched_platform_id' => $matchedPlatformId,
            'match_basis' => $matchBasis,
        ];
    }

    /**
     * @param array<int,int> $platformIds
     */
    private function buildPaymentMap(array $platformIds): array
    {
        $map = [];
        Payment::query()
            ->with(['client:id,name', 'confirmedBy:id,name,email'])
            ->whereIn('platform_id', $platformIds)
            ->whereNotNull('transaction_reference')
            ->get(['id', 'platform_id', 'client_id', 'confirmed_by', 'amount', 'currency', 'transaction_reference', 'status'])
            ->each(function (Payment $payment) use (&$map) {
                $reference = $this->normalizeReference($payment->transaction_reference);
                if (!$reference) {
                    return;
                }

                $map[$reference][] = [
                    'payment_id' => (int) $payment->id,
                    'platform_id' => (int) $payment->platform_id,
                    'amount' => $payment->amount !== null ? (float) $payment->amount : null,
                    'currency' => $payment->currency,
                    'client_id' => $payment->client_id ? (int) $payment->client_id : null,
                    'client_name' => $payment->client?->name,
                    'confirmed_by' => $payment->confirmed_by ? (int) $payment->confirmed_by : null,
                    'confirmed_by_name' => $payment->confirmedBy?->name,
                    'status' => $payment->status,
                    'transaction_reference' => $payment->transaction_reference,
                ];
            });

        return $map;
    }

    private function lineLooksLikeHeader(string $line): bool
    {
        $delimiter = str_contains($line, "\t") ? "\t" : ',';
        $cells = str_getcsv($line, $delimiter);
        $aliases = [
            'clientname',
            'name',
            'client',
            'amountpaid',
            'amount',
            'paidamount',
            'datepaid',
            'date',
            'paymentdate',
            'transactionid',
            'transactionreference',
            'reference',
            'receipt',
            'crmtransactionid',
        ];

        foreach ($cells as $cell) {
            $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower((string) $cell)) ?? '';
            if (in_array($normalized, $aliases, true)) {
                return true;
            }
        }

        return false;
    }

    private function usableReference(?string $value): ?string
    {
        $raw = strtoupper(trim((string) $value));
        $reference = $this->normalizeReference($value);
        if ($reference === null || strlen($reference) < 5) {
            return null;
        }

        $junk = [
            'WAVEAGENTDIRECTDEPOSIT',
            'DIRECTTRANSFER',
            'CODENOTVISSIBLE',
            'CODENOTVISIBLE',
        ];

        if (in_array($reference, $junk, true)) {
            return null;
        }

        foreach (['WAVE AGENT DIRECT DEPOSIT', 'DIRECT TRANSFER', 'CODE NOT VISSIBLE', 'CODE NOT VISIBLE'] as $phrase) {
            if (str_contains($raw, $phrase)) {
                return null;
            }
        }

        return $reference;
    }

    private function normalizeReference(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

    private function firstNonEmpty(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function parseAmount(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(' ', '', $normalized);
        if (str_contains($normalized, ',') && !str_contains($normalized, '.')) {
            $normalized = str_replace(',', '', $normalized);
        } elseif (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace(',', '', $normalized);
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.') {
            return null;
        }

        $amount = (float) $normalized;
        if (!is_finite($amount)) {
            return null;
        }

        return round($amount, 2);
    }

    private function normalizeCurrencyOrNull(?string $value): ?string
    {
        $candidate = strtoupper(trim((string) $value));
        if ($candidate === '') {
            return null;
        }

        return preg_match('/[A-Z]{3}/', $candidate, $matches) === 1 ? $matches[0] : null;
    }

    private function normalizeName(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
    }

    private function incrementClassificationCount(array &$counts, string $classification): void
    {
        if ($classification === 'matched') {
            $counts['matched_rows'] += 1;
        } elseif ($classification === 'amount_mismatch') {
            $counts['mismatch_rows'] += 1;
        } elseif ($classification === 'missing') {
            $counts['missing_rows'] += 1;
        } elseif ($classification === 'unverifiable') {
            $counts['unverifiable_rows'] += 1;
        } elseif (in_array($classification, ['duplicate_in_file', 'duplicate_in_crm'], true)) {
            $counts['duplicate_rows'] += 1;
        }
    }

    private function refreshResolvedRows(PaymentReconciliationBatch $batch): void
    {
        $batch->update([
            'resolved_rows' => PaymentReconciliationRow::query()
                ->where('batch_id', $batch->id)
                ->whereIn('review_status', self::RESOLVED_REVIEW_STATUSES)
                ->count(),
        ]);
    }

    private function ensureBatchIsOpen(PaymentReconciliationRow $row): void
    {
        $batch = $row->relationLoaded('batch') ? $row->batch : $row->batch()->first();
        if ($batch && $batch->status === 'closed') {
            throw new InvalidArgumentException('This reconciliation batch is closed. Reopen it before making row changes.');
        }
    }

    private function rowReviewState(PaymentReconciliationRow $row): array
    {
        return [
            'review_status' => $row->review_status,
            'review_note' => $row->review_note,
            'reviewed_by' => $row->reviewed_by ? (int) $row->reviewed_by : null,
            'reviewed_at' => $row->reviewed_at?->toDateTimeString(),
            'matched_payment_id' => $row->matched_payment_id ? (int) $row->matched_payment_id : null,
        ];
    }

    private function serializeRow(PaymentReconciliationRow $row): array
    {
        return [
            'id' => (int) $row->id,
            'row_number' => (int) $row->row_number,
            'external_name' => $row->external_name,
            'external_amount' => $row->external_amount !== null ? (float) $row->external_amount : null,
            'external_currency' => $row->external_currency,
            'external_paid_at_text' => $row->external_paid_at_text,
            'external_reference_raw' => $row->external_reference_raw,
            'transaction_reference_norm' => $row->transaction_reference_norm,
            'classification' => $row->classification,
            'flags' => $row->flags,
            'raw_row' => $row->raw_row,
            'matched_payment_id' => $row->matched_payment_id ? (int) $row->matched_payment_id : null,
            'matched_client_id' => $row->matched_client_id ? (int) $row->matched_client_id : null,
            'matched_confirmed_by' => $row->matched_confirmed_by ? (int) $row->matched_confirmed_by : null,
            'matched_platform_id' => $row->matched_platform_id ? (int) $row->matched_platform_id : null,
            'match_basis' => $row->match_basis,
            'review_status' => $row->review_status,
            'review_note' => $row->review_note,
            'reviewed_by' => $row->reviewed_by ? (int) $row->reviewed_by : null,
            'reviewed_at' => $row->reviewed_at?->toDateTimeString(),
        ];
    }

    private function serializePaymentCandidate(Payment $payment, string $basis): array
    {
        return [
            'payment_id' => (int) $payment->id,
            'basis' => $basis,
            'platform_id' => (int) $payment->platform_id,
            'platform_name' => $payment->platform?->name,
            'client_id' => $payment->client_id ? (int) $payment->client_id : null,
            'client_name' => $payment->client?->name,
            'amount' => $payment->amount !== null ? (float) $payment->amount : null,
            'currency' => $payment->currency,
            'transaction_reference' => $payment->transaction_reference,
            'status' => $payment->status,
            'confirmed_by' => $payment->confirmed_by ? (int) $payment->confirmed_by : null,
            'created_at' => $payment->created_at?->toDateTimeString(),
        ];
    }
}
