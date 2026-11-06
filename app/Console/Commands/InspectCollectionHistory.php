<?php

namespace App\Console\Commands;

use App\Services\Collections\TzMpesaParser;
use Illuminate\Console\Command;
use XMLReader;

/** Deliberately has no apply/dispatch option and does not touch the database. */
class InspectCollectionHistory extends Command
{
    protected $signature = 'collections:inspect-history {xml}';

    protected $description = 'Read a private SMS archive and report parser counts only; never import cash or activate';

    public function handle(TzMpesaParser $parser): int
    {
        $path = realpath($this->argument('xml'));
        if (! $path || ! is_readable($path)) {
            $this->error('Readable local XML file required.');

            return self::FAILURE;
        }
        $xml = new XMLReader;
        $xml->open($path, null, LIBXML_NONET | LIBXML_COMPACT);
        $counts = [];
        $receipts = [];
        try {
            while ($xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->name !== 'sms') {
                    continue;
                }
                $result = $xml->getAttribute('type') === '1' ? $parser->parse((string) $xml->getAttribute('address'), (string) $xml->getAttribute('body')) : ['kind' => 'sent'];
                $key = $result['family'] ?? $result['kind'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                if ($result['kind'] === 'credit') {
                    $receipts[$result['reference']] = true;
                }
            }
        } finally {
            $xml->close();
        }
        $this->line(json_encode(['sha256' => hash_file('sha256', $path), 'counts' => $counts, 'distinct_credit_refs' => count($receipts),
            'historical_only' => true, 'database_writes' => 0, 'entitlement_dispatches' => 0], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
