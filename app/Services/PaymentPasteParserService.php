<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PaymentPasteParserService
{
    private const MAX_ROWS = 12000;

    public function parse(string $input, ?string $defaultDate = null): array
    {
        $lines = array_map(
            fn (string $line) => trim($line),
            preg_split('/\r\n|\r|\n/', trim($input)) ?: []
        );
        $nonEmptyLines = array_values(array_filter($lines, fn (string $line) => $line !== ''));

        if (empty($nonEmptyLines)) {
            throw new InvalidArgumentException('Paste content cannot be empty.');
        }

        $currentDate = $this->parseDate($defaultDate);
        $rows = [];
        $pending = [];
        $pendingStart = 1;

        foreach ($lines as $index => $line) {
            $rowNumber = $index + 1;
            if ($line === '') {
                $this->flushPending($rows, $pending, $pendingStart, $currentDate);

                continue;
            }

            $lineDate = $this->parseDate($line);
            if ($lineDate !== null && ! $this->looksLikeAmount($line) && ! $this->looksLikeReference($line)) {
                $this->flushPending($rows, $pending, $pendingStart, $currentDate);
                $currentDate = $lineDate;

                continue;
            }

            if (
                ! $this->looksLikeAmount($line)
                && ! $this->looksLikeReference($line)
                && (str_contains($line, ',') || str_contains($line, "\t") || preg_match('/\s{2,}/', $line) === 1)
            ) {
                $this->flushPending($rows, $pending, $pendingStart, $currentDate);
                $rows[] = $this->rowFromDelimitedLine($line, $rowNumber, $currentDate);

                continue;
            }

            if (empty($pending)) {
                $pendingStart = $rowNumber;
            }

            $pending[] = $line;

            if ($this->blockLooksComplete($pending)) {
                $this->flushPending($rows, $pending, $pendingStart, $currentDate);
            }
        }

        $this->flushPending($rows, $pending, $pendingStart, $currentDate);

        if (empty($rows)) {
            throw new InvalidArgumentException('No payment rows could be parsed from the pasted text.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Paste import supports up to '.self::MAX_ROWS.' rows at a time.');
        }

        return [
            'headers' => ['client_name', 'amount', 'date', 'transaction_reference', 'subscription_type', 'sender_name', 'parse_error'],
            'rows' => $rows,
            'meta' => [
                'source_type' => 'orphan_paste',
                'line_count' => count($nonEmptyLines),
                'rows_parsed' => count($rows),
            ],
        ];
    }

    private function flushPending(array &$rows, array &$pending, int $rowNumber, ?Carbon $currentDate): void
    {
        if (empty($pending)) {
            return;
        }

        $rows[] = $this->rowFromBlock($pending, $rowNumber, $currentDate);
        $pending = [];
    }

    private function rowFromDelimitedLine(string $line, int $rowNumber, ?Carbon $currentDate): array
    {
        if (preg_match('/\s{2,}/', $line) === 1 && ! str_contains($line, "\t") && ! str_contains($line, ',')) {
            $cells = preg_split('/\s{2,}/', $line) ?: [];
        } else {
            $delimiter = str_contains($line, "\t") ? "\t" : ',';
            $cells = str_getcsv($line, $delimiter);
        }

        $cells = array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $cells),
            fn (string $value) => $value !== ''
        ));

        return $this->rowFromBlock($cells, $rowNumber, $currentDate);
    }

    private function rowFromBlock(array $lines, int $rowNumber, ?Carbon $currentDate): array
    {
        $nameParts = [];
        $amount = null;
        $reference = null;
        $date = $currentDate;
        $subscriptionType = null;
        $parseErrors = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $lineDate = $this->parseDate($line);
            if ($lineDate !== null && ! $this->looksLikeAmount($line) && ! $this->looksLikeReference($line)) {
                $date = $lineDate;

                continue;
            }

            if ($reference === null && $this->looksLikeReference($line)) {
                $reference = $this->normalizeReference($line);

                continue;
            }

            if ($amount === null && $this->looksLikeAmount($line)) {
                $amount = $this->parsePasteAmount($line);
                if ($amount === null) {
                    $parseErrors[] = 'Amount could not be parsed.';
                }

                continue;
            }

            if ($subscriptionType === null && preg_match('/\b(renewal|new|activation|reactivation|upgrade|trial)\b/i', $line) === 1) {
                $subscriptionType = strtolower(trim($line));

                continue;
            }

            $nameParts[] = $line;
        }

        $clientName = trim(implode(' ', $nameParts));

        if ($amount === null) {
            $parseErrors[] = 'Amount is missing.';
        }

        $values = [
            'client_name' => $clientName !== '' ? $clientName : null,
            'sender_name' => $clientName !== '' ? $clientName : null,
            'amount' => $amount !== null ? number_format($amount, 2, '.', '') : null,
            'date' => $date?->toDateString(),
            'transaction_reference' => $reference,
            'subscription_type' => $subscriptionType,
            'parse_error' => implode(' ', array_unique($parseErrors)),
        ];

        return [
            'row_number' => $rowNumber,
            'values' => $values,
            'raw' => $lines,
        ];
    }

    private function blockLooksComplete(array $lines): bool
    {
        $hasAmount = false;
        $hasReference = false;
        $hasName = false;

        foreach ($lines as $line) {
            $hasAmount = $hasAmount || $this->looksLikeAmount($line);
            $hasReference = $hasReference || $this->looksLikeReference($line);
            $hasName = $hasName || (! $this->looksLikeAmount($line) && ! $this->looksLikeReference($line));
        }

        return $hasAmount && $hasReference && ! $hasName;
    }

    private function looksLikeReference(string $value): bool
    {
        $candidate = strtoupper(trim($value));
        if ($candidate === '') {
            return false;
        }

        if (preg_match('/\b(?:T_|XOT-|DGR|DGS|DGT)[A-Z0-9_-]{5,}\b/i', $candidate) === 1) {
            return true;
        }

        return preg_match('/^[A-Z0-9_-]{8,}$/', $candidate) === 1
            && preg_match('/[A-Z]/', $candidate) === 1
            && preg_match('/[0-9]/', $candidate) === 1;
    }

    private function normalizeReference(string $value): ?string
    {
        $normalized = strtoupper(trim($value));
        $normalized = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

    private function looksLikeAmount(string $value): bool
    {
        $candidate = strtolower(trim($value));

        return preg_match('/^\d+(?:[.,]\d{1,3})?\s*k$/', $candidate) === 1
            || preg_match('/^\d{1,3}(?:,\d{3})+(?:\.)?$/', $candidate) === 1
            || preg_match('/^\d+(?:\.)?$/', $candidate) === 1;
    }

    private function parsePasteAmount(string $value): ?float
    {
        $candidate = strtolower(trim($value));
        $candidate = rtrim($candidate, '.');
        $multiplier = 1;

        if (str_ends_with($candidate, 'k')) {
            $multiplier = 1000;
            $candidate = trim(substr($candidate, 0, -1));
        }

        $candidate = str_replace([' ', ','], '', $candidate);
        $candidate = preg_replace('/[^0-9.\-]/', '', $candidate) ?? '';
        if ($candidate === '' || $candidate === '-' || $candidate === '.') {
            return null;
        }

        $amount = (float) $candidate * $multiplier;

        return is_finite($amount) && $amount > 0 ? round($amount, 2) : null;
    }

    private function parseDate(?string $value): ?Carbon
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return null;
        }

        $candidate = preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $candidate) ?? $candidate;

        foreach (['Y-m-d', 'd F Y', 'd M Y', 'j F Y', 'j M Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $candidate);
                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($candidate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
