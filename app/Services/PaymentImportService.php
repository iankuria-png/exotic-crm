<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentImportBatch;
use App\Models\PaymentImportRow;
use App\Models\Platform;
use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentImportService
{
    private const MAX_ROWS_PER_IMPORT = 5000;

    public function __construct(
        private readonly PaymentImportParserService $parserService
    ) {
    }

    public function previewImport(
        UploadedFile $file,
        Platform $platform,
        int $actorId,
        bool $hasHeader = true,
        ?string $reason = null,
        ?string $defaultCurrency = null
    ): array {
        $parsed = $this->parserService->parseUploadedFile($file, $hasHeader);
        $rows = $parsed['rows'] ?? [];

        if (count($rows) === 0) {
            throw new InvalidArgumentException('The uploaded file has no data rows.');
        }

        if (count($rows) > self::MAX_ROWS_PER_IMPORT) {
            throw new InvalidArgumentException('Import preview supports up to 5000 rows per file.');
        }

        $currency = $this->parseCurrency($defaultCurrency, $platform->currency_code ?: 'KES');
        $batch = PaymentImportBatch::query()->create([
            'platform_id' => $platform->id,
            'uploaded_by' => $actorId,
            'file_name' => $file->getClientOriginalName(),
            'file_mime' => $file->getClientMimeType(),
            'status' => 'previewed',
            'reason' => $reason ? trim($reason) : null,
            'metadata' => [
                'has_header' => $hasHeader,
                'columns' => $parsed['headers'] ?? [],
                'default_currency' => $currency,
            ],
        ]);

        $draftRows = [];
        $referenceValues = [];
        $legacyHashes = [];

        foreach ($rows as $row) {
            $normalized = $this->normalizeImportRow(
                $row['values'] ?? [],
                $platform,
                $currency
            );

            $reference = $normalized['normalized_row']['transaction_reference'] ?? null;
            $legacyHash = $normalized['legacy_hash'] ?? null;

            if ($reference) {
                $referenceValues[] = $reference;
            }
            if ($legacyHash) {
                $legacyHashes[] = $legacyHash;
            }

            $draftRows[] = [
                'row_number' => (int) ($row['row_number'] ?? 0),
                'raw_row' => $row['values'] ?? [],
                'normalized_row' => $normalized['normalized_row'],
                'errors' => $normalized['errors'],
                'transaction_reference_norm' => $reference,
                'legacy_hash' => $legacyHash,
                'status' => 'valid',
                'duplicate_type' => null,
                'duplicate_payment_id' => null,
                'suggested_match' => null,
            ];
        }

        [$existingByReference, $existingByHash] = $this->resolveExistingDuplicates(
            (int) $platform->id,
            $referenceValues,
            $legacyHashes
        );

        $seenReferenceInFile = [];
        $seenHashInFile = [];
        $phonesForSuggestions = [];

        foreach ($draftRows as $index => $draft) {
            $errors = $draft['errors'] ?? [];
            $reference = $draft['transaction_reference_norm'];
            $legacyHash = $draft['legacy_hash'];

            if (!empty($errors)) {
                $draftRows[$index]['status'] = 'invalid';
                continue;
            }

            if ($reference && isset($seenReferenceInFile[$reference])) {
                $draftRows[$index]['status'] = 'duplicate';
                $draftRows[$index]['duplicate_type'] = 'duplicate_in_file_reference';
                continue;
            }

            if ($reference && isset($existingByReference[$reference])) {
                $draftRows[$index]['status'] = 'duplicate';
                $draftRows[$index]['duplicate_type'] = 'duplicate_existing_reference';
                $draftRows[$index]['duplicate_payment_id'] = $existingByReference[$reference];
                continue;
            }

            if ($legacyHash && isset($seenHashInFile[$legacyHash])) {
                $draftRows[$index]['status'] = 'duplicate';
                $draftRows[$index]['duplicate_type'] = 'duplicate_in_file_hash';
                continue;
            }

            if ($legacyHash && isset($existingByHash[$legacyHash])) {
                $draftRows[$index]['status'] = 'duplicate';
                $draftRows[$index]['duplicate_type'] = 'duplicate_existing_hash';
                $draftRows[$index]['duplicate_payment_id'] = $existingByHash[$legacyHash];
                continue;
            }

            if ($reference) {
                $seenReferenceInFile[$reference] = true;
            }
            if ($legacyHash) {
                $seenHashInFile[$legacyHash] = true;
            }

            $phone = (string) ($draft['normalized_row']['phone'] ?? '');
            if ($phone !== '') {
                $phonesForSuggestions[$phone] = true;
            }
        }

        $suggestedByPhone = $this->buildSuggestedMatches(
            (int) $platform->id,
            array_keys($phonesForSuggestions)
        );

        $totals = [
            'total_rows' => count($draftRows),
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
        ];
        $responseRows = [];

        foreach ($draftRows as $draft) {
            $status = $draft['status'];
            if ($status === 'valid') {
                $totals['valid_rows'] += 1;
            } elseif ($status === 'invalid') {
                $totals['invalid_rows'] += 1;
            } else {
                $totals['duplicate_rows'] += 1;
            }

            $phone = (string) ($draft['normalized_row']['phone'] ?? '');
            $suggestedMatch = $status === 'valid' && $phone !== ''
                ? ($suggestedByPhone[$phone] ?? null)
                : null;

            $rowModel = PaymentImportRow::query()->create([
                'batch_id' => $batch->id,
                'row_number' => $draft['row_number'],
                'status' => $status,
                'raw_row' => $draft['raw_row'],
                'normalized_row' => $draft['normalized_row'],
                'validation_errors' => $draft['errors'],
                'duplicate_type' => $draft['duplicate_type'],
                'duplicate_payment_id' => $draft['duplicate_payment_id'],
                'transaction_reference_norm' => $draft['transaction_reference_norm'],
                'legacy_hash' => $draft['legacy_hash'],
                'suggested_match' => $suggestedMatch,
            ]);

            $responseRows[] = [
                'id' => $rowModel->id,
                'row_number' => $rowModel->row_number,
                'status' => $rowModel->status,
                'normalized_row' => $rowModel->normalized_row,
                'validation_errors' => $rowModel->validation_errors,
                'duplicate_type' => $rowModel->duplicate_type,
                'duplicate_payment_id' => $rowModel->duplicate_payment_id,
                'suggested_match' => $rowModel->suggested_match,
            ];
        }

        $batch->update([
            'total_rows' => $totals['total_rows'],
            'valid_rows' => $totals['valid_rows'],
            'invalid_rows' => $totals['invalid_rows'],
            'duplicate_rows' => $totals['duplicate_rows'],
            'committed_rows' => 0,
        ]);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'summary' => $totals,
            'headers' => $parsed['headers'] ?? [],
            'rows' => $responseRows,
        ];
    }

    public function commitImport(PaymentImportBatch $batch, int $actorId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($batch, $actorId, $reason) {
            /** @var PaymentImportBatch $lockedBatch */
            $lockedBatch = PaymentImportBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBatch->status === 'committed') {
                return [
                    'batch_id' => $lockedBatch->id,
                    'status' => $lockedBatch->status,
                    'summary' => [
                        'total_rows' => (int) $lockedBatch->total_rows,
                        'valid_rows' => (int) $lockedBatch->valid_rows,
                        'invalid_rows' => (int) $lockedBatch->invalid_rows,
                        'duplicate_rows' => (int) $lockedBatch->duplicate_rows,
                        'committed_rows' => (int) $lockedBatch->committed_rows,
                        'created_now' => 0,
                    ],
                    'payments_created' => [],
                ];
            }

            $rows = PaymentImportRow::query()
                ->where('batch_id', $lockedBatch->id)
                ->where('status', 'valid')
                ->orderBy('row_number')
                ->lockForUpdate()
                ->get();

            $referenceValues = $rows
                ->pluck('transaction_reference_norm')
                ->filter(fn($value) => is_string($value) && $value !== '')
                ->values()
                ->all();
            $legacyHashes = $rows
                ->pluck('legacy_hash')
                ->filter(fn($value) => is_string($value) && $value !== '')
                ->values()
                ->all();

            [$existingByReference, $existingByHash] = $this->resolveExistingDuplicates(
                (int) $lockedBatch->platform_id,
                $referenceValues,
                $legacyHashes
            );

            $createdPaymentIds = [];
            $createdNow = 0;

            foreach ($rows as $row) {
                $normalized = is_array($row->normalized_row) ? $row->normalized_row : [];
                $reference = (string) ($row->transaction_reference_norm ?? '');
                $legacyHash = (string) ($row->legacy_hash ?? '');

                if ($reference !== '' && isset($existingByReference[$reference])) {
                    $row->update([
                        'status' => 'duplicate',
                        'duplicate_type' => 'duplicate_existing_reference',
                        'duplicate_payment_id' => $existingByReference[$reference],
                    ]);
                    continue;
                }

                if ($legacyHash !== '' && isset($existingByHash[$legacyHash])) {
                    $row->update([
                        'status' => 'duplicate',
                        'duplicate_type' => 'duplicate_existing_hash',
                        'duplicate_payment_id' => $existingByHash[$legacyHash],
                    ]);
                    continue;
                }

                $amount = isset($normalized['amount']) ? (float) $normalized['amount'] : 0;
                $currency = $this->parseCurrency($normalized['currency'] ?? null, 'KES');
                $status = $this->parseStatus($normalized['status'] ?? null);
                $paidAt = $this->parseDate($normalized['paid_at'] ?? null);

                $rawPayload = [
                    'source' => 'excel_import',
                    'import' => [
                        'batch_id' => $lockedBatch->id,
                        'row_id' => $row->id,
                        'row_number' => $row->row_number,
                        'file_name' => $lockedBatch->file_name,
                        'actor_id' => $actorId,
                        'reason' => $reason ? trim($reason) : ($lockedBatch->reason ?: null),
                    ],
                    'normalized_row' => $normalized,
                    'raw_row' => is_array($row->raw_row) ? $row->raw_row : [],
                ];

                $payload = [
                    'platform_id' => (int) $lockedBatch->platform_id,
                    'phone' => $normalized['phone'] ?? null,
                    'amount' => $amount,
                    'currency' => $currency,
                    'transaction_reference' => $reference !== '' ? $reference : null,
                    'status' => $status,
                    'source' => 'excel_import',
                    'import_batch_id' => $lockedBatch->id,
                    'import_legacy_hash' => $legacyHash !== '' ? $legacyHash : null,
                    'raw_payload' => $rawPayload,
                ];

                if (!empty($normalized['product_id']) && is_numeric($normalized['product_id'])) {
                    $payload['product_id'] = (int) $normalized['product_id'];
                }

                if ($paidAt) {
                    $payload['created_at'] = $paidAt;
                    $payload['updated_at'] = now();
                }

                $payment = Payment::query()->create($payload);

                $row->update([
                    'status' => 'committed',
                    'applied_payment_id' => $payment->id,
                    'duplicate_type' => null,
                    'duplicate_payment_id' => null,
                ]);

                if ($reference !== '') {
                    $existingByReference[$reference] = $payment->id;
                }
                if ($legacyHash !== '') {
                    $existingByHash[$legacyHash] = $payment->id;
                }

                $createdNow += 1;
                $createdPaymentIds[] = $payment->id;
            }

            $totalRows = PaymentImportRow::query()->where('batch_id', $lockedBatch->id)->count();
            $validRows = PaymentImportRow::query()->where('batch_id', $lockedBatch->id)->where('status', 'valid')->count();
            $invalidRows = PaymentImportRow::query()->where('batch_id', $lockedBatch->id)->where('status', 'invalid')->count();
            $duplicateRows = PaymentImportRow::query()->where('batch_id', $lockedBatch->id)->where('status', 'duplicate')->count();
            $committedRows = PaymentImportRow::query()->where('batch_id', $lockedBatch->id)->where('status', 'committed')->count();

            $lockedBatch->update([
                'status' => 'committed',
                'reason' => $reason ? trim($reason) : $lockedBatch->reason,
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
                'duplicate_rows' => $duplicateRows,
                'committed_rows' => $committedRows,
                'committed_at' => now(),
            ]);

            return [
                'batch_id' => $lockedBatch->id,
                'status' => $lockedBatch->status,
                'summary' => [
                    'total_rows' => $totalRows,
                    'valid_rows' => $validRows,
                    'invalid_rows' => $invalidRows,
                    'duplicate_rows' => $duplicateRows,
                    'committed_rows' => $committedRows,
                    'created_now' => $createdNow,
                ],
                'payments_created' => $createdPaymentIds,
            ];
        });
    }

    private function normalizeImportRow(array $row, Platform $platform, string $defaultCurrency): array
    {
        $phonePrefix = (string) ($platform->phone_prefix ?: '254');

        $rawAmount = $this->firstNonEmpty($row, [
            'amount', 'payment_amount', 'amount_paid', 'paid_amount', 'total',
        ]);
        $rawPhone = $this->firstNonEmpty($row, [
            'phone', 'phone_number', 'phone_no', 'msisdn', 'contact_phone',
        ]);
        $rawStatus = $this->firstNonEmpty($row, [
            'status', 'payment_status',
        ]);
        $rawReference = $this->firstNonEmpty($row, [
            'transaction_reference', 'transaction_id', 'reference', 'receipt', 'receipt_no', 'mpesa_code', 'payment_code',
        ]);
        $rawCurrency = $this->firstNonEmpty($row, [
            'currency', 'currency_code',
        ]);
        $rawPaidAt = $this->firstNonEmpty($row, [
            'date', 'payment_date', 'paid_at', 'payment_time', 'timestamp',
        ]);
        $rawProfileUrl = $this->firstNonEmpty($row, [
            'profile_url', 'url', 'escort_profile_url',
        ]);
        $rawSubscriptionType = $this->firstNonEmpty($row, [
            'subscription_type', 'plan', 'plan_type', 'subscription_plan',
        ]);
        $rawProductId = $this->firstNonEmpty($row, [
            'product_id',
        ]);

        $amount = $this->parseAmount($rawAmount);
        $phone = PhoneNormalizer::normalize($rawPhone, $phonePrefix);
        $status = $this->parseStatus($rawStatus);
        $currency = $this->parseCurrency($rawCurrency, $defaultCurrency);
        $reference = $this->normalizeReference($rawReference);
        $paidAt = $this->parseDate($rawPaidAt);
        $profileUrl = trim((string) ($rawProfileUrl ?? ''));
        $subscriptionType = trim((string) ($rawSubscriptionType ?? ''));

        $errors = [];

        if ($amount === null || $amount <= 0) {
            $errors[] = 'Amount is required and must be greater than zero.';
        }

        if ($phone === null && $reference === null && $profileUrl === '') {
            $errors[] = 'At least one identifier is required: phone, transaction reference, or profile URL.';
        }

        if ($rawPhone !== null && trim((string) $rawPhone) !== '' && $phone === null) {
            $errors[] = 'Phone number could not be normalized for this market.';
        }

        $normalizedRow = [
            'phone' => $phone,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'transaction_reference' => $reference,
            'paid_at' => $paidAt?->toDateTimeString(),
            'profile_url' => $profileUrl !== '' ? $profileUrl : null,
            'subscription_type' => $subscriptionType !== '' ? $subscriptionType : null,
        ];

        if ($rawProductId !== null && trim((string) $rawProductId) !== '' && is_numeric($rawProductId)) {
            $normalizedRow['product_id'] = (int) $rawProductId;
        }

        $legacyHash = hash('sha256', implode('|', [
            (string) $platform->id,
            $reference ?? '',
            $phone ?? '',
            $amount !== null ? number_format($amount, 2, '.', '') : '',
            $currency,
            $paidAt?->toDateString() ?? '',
            strtolower($profileUrl),
            strtolower($subscriptionType),
        ]));

        return [
            'normalized_row' => $normalizedRow,
            'legacy_hash' => $legacyHash,
            'errors' => $errors,
        ];
    }

    private function resolveExistingDuplicates(int $platformId, array $references, array $legacyHashes): array
    {
        $referenceSet = collect($references)
            ->filter(fn($value) => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        $hashSet = collect($legacyHashes)
            ->filter(fn($value) => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($referenceSet) && empty($hashSet)) {
            return [[], []];
        }

        $payments = Payment::query()
            ->where('platform_id', $platformId)
            ->where(function ($query) use ($hashSet) {
                if (!empty($hashSet)) {
                    $query->whereIn('import_legacy_hash', $hashSet);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orWhere(function ($query) use ($platformId) {
                $query->where('platform_id', $platformId)
                    ->whereNotNull('transaction_reference');
            })
            ->get(['id', 'transaction_reference', 'import_legacy_hash']);

        $byReference = [];
        $byHash = [];

        foreach ($payments as $payment) {
            $normalizedReference = $this->normalizeReference($payment->transaction_reference);
            if ($normalizedReference && in_array($normalizedReference, $referenceSet, true)) {
                $byReference[$normalizedReference] = $payment->id;
            }

            if ($payment->import_legacy_hash && in_array($payment->import_legacy_hash, $hashSet, true)) {
                $byHash[$payment->import_legacy_hash] = $payment->id;
            }
        }

        return [$byReference, $byHash];
    }

    private function buildSuggestedMatches(int $platformId, array $phones): array
    {
        if (empty($phones)) {
            return [];
        }

        $clients = Client::query()
            ->where('platform_id', $platformId)
            ->whereIn('phone_normalized', $phones)
            ->get(['id', 'name', 'phone_normalized']);

        $grouped = [];
        foreach ($clients as $client) {
            $grouped[$client->phone_normalized][] = [
                'client_id' => $client->id,
                'client_name' => $client->name,
            ];
        }

        $suggestions = [];
        foreach ($grouped as $phone => $matches) {
            if (count($matches) === 1) {
                $suggestions[$phone] = [
                    'confidence' => 'auto_high',
                    'basis' => 'phone_exact',
                    'client_id' => $matches[0]['client_id'],
                    'client_name' => $matches[0]['client_name'],
                ];
                continue;
            }

            $suggestions[$phone] = [
                'confidence' => 'auto_low',
                'basis' => 'phone_collision',
                'candidate_count' => count($matches),
                'candidates' => array_slice($matches, 0, 5),
            ];
        }

        return $suggestions;
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
            $normalized = str_replace(',', '.', $normalized);
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

    private function parseStatus(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return 'completed';
        }

        $map = [
            'paid' => 'completed',
            'complete' => 'completed',
            'completed' => 'completed',
            'success' => 'completed',
            'successful' => 'completed',
            'settled' => 'completed',
            'pending' => 'pending',
            'awaiting' => 'pending',
            'initiated' => 'initiated',
            'in_progress' => 'initiated',
            'failed' => 'failed',
            'declined' => 'failed',
            'cancelled' => 'failed',
            'canceled' => 'failed',
            'reversed' => 'failed',
        ];

        return $map[$normalized] ?? 'completed';
    }

    private function parseCurrency(?string $value, string $fallback): string
    {
        $candidate = strtoupper(trim((string) $value));
        if ($candidate === '') {
            $candidate = strtoupper(trim($fallback));
        }

        if (preg_match('/[A-Z]{3}/', $candidate, $matches) === 1) {
            return $matches[0];
        }

        return 'KES';
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        $candidate = trim($value);
        if ($candidate === '') {
            return null;
        }

        if (is_numeric($candidate)) {
            $serial = (float) $candidate;
            if ($serial >= 20000 && $serial <= 90000) {
                return Carbon::create(1899, 12, 30, 0, 0, 0)->addDays((int) floor($serial));
            }
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'd-m-Y',
            'm/d/Y H:i:s',
            'm/d/Y H:i',
            'm/d/Y',
            'Y/m/d H:i:s',
            'Y/m/d H:i',
            'Y/m/d',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $candidate);
                if ($parsed !== false) {
                    return $parsed;
                }
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($candidate);
        } catch (\Throwable) {
            return null;
        }
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
}
