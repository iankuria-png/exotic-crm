<?php

namespace App\Console\Commands;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionDevice;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionParserRegistry;
use App\Services\Collections\CollectionReceiptService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seed payer-learning history from the receiving phone's own past credits. Every imported receipt is
 * historical_only: it can never activate, allocate or move money. Preview is the default.
 */
class ImportCollectionHistory extends Command
{
    protected $signature = 'collections:import-history {device} {jsonl} {--days=90} {--apply}';

    protected $description = 'Preview or import a private export of the enrolled phone\'s past credit SMS as historical-only receipts';

    public function handle(CollectionIngestService $ingest, CollectionReceiptService $receipts, CollectionParserRegistry $registry): int
    {
        $path = realpath($this->argument('jsonl'));
        if (! $path || ! is_readable($path) || (fileperms($path) & 0077) !== 0) {
            $this->error('Readable owner-only private JSONL export required.');

            return self::FAILURE;
        }
        $device = CollectionDevice::whereNull('revoked_at')->findOrFail($this->argument('device'));
        $bindings = DB::table('collection_sim_bindings')->where('device_id', $device->id)->whereNull('valid_until')->get();
        if ($bindings->count() !== 1) {
            $this->error('Exactly one active SIM binding is required.');

            return self::FAILURE;
        }
        $binding = $bindings->first();
        $account = CollectionAccount::findOrFail($binding->account_id);
        $parser = $registry->forAccount($account);
        $recipient = data_get($device->acceptance, 'history_recipient');
        if (! $parser || ! is_string($recipient) || $recipient === '') {
            $this->error('A reviewed provider parser and the enrolled receiving number are required.');

            return self::FAILURE;
        }
        $since = now()->subDays(max(1, min((int) $this->option('days'), 120)));
        $counts = ['lines' => 0, 'credits' => 0, 'outside_window' => 0, 'not_credit' => 0, 'already_imported' => 0, 'imported' => 0, 'receipts' => 0];
        $receiptIds = [];
        foreach (new \SplFileObject($path) as $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $counts['lines']++;
            $row = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            $at = Carbon::parse($row['receivedAt']);
            if ($at->lt($since) || $at->gt($device->cutover_at)) {
                $counts['outside_window']++;

                continue;
            }
            if (($parser->parse((string) $row['sender'], (string) $row['message'])['kind'] ?? '') !== 'credit') {
                $counts['not_credit']++;

                continue;
            }
            $counts['credits']++;
            $id = 'history-'.hash('sha256', $device->id."\0".$row['id']."\0".$row['message']);
            if (DB::table('collection_events')->where('device_id', $device->id)->where('external_event_id', $id)->exists()) {
                $counts['already_imported']++;

                continue;
            }
            if (! $this->option('apply')) {
                continue;
            }
            $payload = ['message' => (string) $row['message'], 'sender' => (string) $row['sender'], 'recipient' => $recipient,
                'simNumber' => (int) $binding->sim_number, 'receivedAt' => $at->toIso8601String()];
            if (data_get($device->acceptance, 'sim_identity_mode') === 'android_subscription_v1') {
                $payload += ['subscriptionId' => (int) data_get($device->acceptance, 'android_subscription_ids.'.(int) $binding->sim_number),
                    'carrierName' => $binding->carrier_name, 'recipientSource' => 'enrollment', 'measuredRecipient' => null];
            }
            $result = $ingest->accept($device, json_encode(['id' => $id, 'deviceId' => $device->external_device_id, 'event' => 'sms:received',
                'payload' => $payload], JSON_THROW_ON_ERROR), true);
            if (($result['status'] ?? '') !== 'accepted') {
                $this->error('Import stopped: '.($result['reason'] ?? $result['status'] ?? 'unknown'));

                return self::FAILURE;
            }
            $receipt = $receipts->process($result['event_id']);
            $counts['imported']++;
            if ($receipt) {
                $receiptIds[$receipt->id] = true;
            }
        }
        $counts['receipts'] = count($receiptIds);
        $this->line(json_encode(['account_id' => $account->id, 'since' => $since->toIso8601String(), 'until' => $device->cutover_at->toIso8601String(),
            'mode' => $this->option('apply') ? 'apply' : 'preview'] + $counts, JSON_THROW_ON_ERROR));
        if ($this->option('apply')) {
            DB::table('collection_audit')->insert(['account_id' => $account->id, 'actor_id' => null, 'action' => 'historical_receipts_imported',
                'context' => json_encode(['device_id' => $device->id, 'export_sha256' => hash_file('sha256', $path)] + $counts, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        }

        return self::SUCCESS;
    }
}
