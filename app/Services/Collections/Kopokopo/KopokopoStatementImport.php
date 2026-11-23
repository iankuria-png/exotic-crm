<?php

namespace App\Services\Collections\Kopokopo;

use App\Models\Collections\CollectionProviderFeed;
use App\Models\User;
use App\Services\Collections\CollectionException;
use Illuminate\Support\Carbon;

class KopokopoStatementImport
{
    public const HEADER = ['Date', 'Reference ID', 'Sender Identifier', 'Name', 'Till No.', 'Account Number', 'Fees (KSh)', 'Amount (KSh)', 'MM System', 'Status', 'Claimed by', 'Orders'];

    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 10 * 1024 * 1024) {
            throw new CollectionException('provider_statement_file_invalid', 422);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) > 10 * 1024 * 1024) {
            throw new CollectionException('provider_statement_file_invalid', 422);
        }
        $h = fopen('php://temp', 'w+b');
        if ($h) {
            fwrite($h, $bytes);
            rewind($h);
        }
        if (! $h) {
            throw new CollectionException('provider_statement_file_invalid', 422);
        }
        $rows = [];
        $header = false;
        $line = 0;
        $parser = app(KopokopoReceiptParser::class);
        try {
            while (($row = fgetcsv($h)) !== false) {
                $line++;
                if (! $header) {
                    if ($row === self::HEADER) {
                        $header = true;
                    } elseif ($line > 3) {
                        throw new CollectionException('provider_statement_header_invalid', 422);
                    }

                    continue;
                }
                if ($row === [null] || $row === []) {
                    continue;
                }
                if (count($row) !== count(self::HEADER) || count($rows) >= 50000) {
                    throw new CollectionException('provider_statement_row_invalid', 422);
                }
                $r = array_combine(self::HEADER, $row);
                if ($r['Till No.'] !== '268491' || $r['MM System'] !== 'Safaricom' || ! in_array($r['Status'], ['Complete', 'Reversed'], true)) {
                    throw new CollectionException('provider_statement_receiving_scope_mismatch', 422);
                }
                if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $r['Date'])) {
                    throw new CollectionException('provider_statement_date_invalid', 422);
                }
                $at = Carbon::createFromFormat('!Y-m-d H:i', $r['Date'], 'Africa/Nairobi');
                if (! $at || $at->format('Y-m-d H:i') !== $r['Date']) {
                    throw new CollectionException('provider_statement_date_invalid', 422);
                }
                $sender = trim($r['Sender Identifier']);
                $hash = preg_match('/^[a-f0-9]{64}$/Di', $sender) ? strtolower($sender) : null;
                $phone = $hash ? null : $parser->phone($sender);
                if ($sender !== '' && ! $hash && ! $phone) {
                    throw new CollectionException('provider_statement_payer_identifier_invalid', 422);
                }
                $topic = $r['Status'] === 'Reversed' ? KopokopoReceiptParser::REVERSED : KopokopoReceiptParser::RECEIVED;
                $observation = $parser->resource(['till_number' => '268491', 'currency' => 'KES', 'reference' => $r['Reference ID'],
                    'amount' => str_replace(',', '', $r['Amount (KSh)']), 'status' => strtolower($r['Status']),
                    'origination_time' => $at->toIso8601String(), 'hashed_sender_phone' => $hash, 'sender_phone_number' => $phone,
                    'sender_first_name' => trim($r['Name'])], $topic, true);
                $rows[] = ['line' => $line, 'topic' => $topic, 'observation' => $observation];
            }
        } catch (\Throwable $e) {
            if ($e instanceof CollectionException) {
                throw $e;
            }
            throw new CollectionException('provider_statement_row_invalid', 422);
        } finally {
            fclose($h);
        }
        if (! $header || ! $rows) {
            throw new CollectionException('provider_statement_empty', 422);
        }

        return ['sha256' => hash('sha256', $bytes), 'rows' => $rows];
    }

    public function import(CollectionProviderFeed $feed, array $statement, User $actor): array
    {
        app(KopokopoFeedService::class)->scope($actor, true);
        app(KopokopoFeedService::class)->configuration($feed);
        $counts = ['accepted' => 0, 'duplicate' => 0, 'quarantined' => 0];
        foreach ($statement['rows'] as $row) {
            $body = json_encode($row['observation'], JSON_THROW_ON_ERROR);
            $result = app(KopokopoIngestService::class)->observation($feed, 'statement:'.hash('sha256', $body), $row['topic'], $row['observation'], $body, 'statement', true);
            $counts[$result['status']]++;
        }
        app(KopokopoFeedService::class)->audit($feed, $actor->id, 'kopokopo_statement_imported', ['source_sha256' => $statement['sha256'], 'rows' => count($statement['rows']), 'timezone' => 'Africa/Nairobi'] + $counts);

        return $counts;
    }
}
