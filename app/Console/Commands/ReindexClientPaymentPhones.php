<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\WpSyncService;
use App\Support\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReindexClientPaymentPhones extends Command
{
    protected $signature = 'crm:reindex-payment-phones {--platform=} {--after=0} {--limit=25} {--restore=} {--apply} {--expected=}';

    protected $description = 'Preview source-verified phone normalization; refuse collisions and preserve a private exact restore backup.';

    public function handle(): int
    {
        if ($this->option('restore')) {
            return $this->restore();
        }
        $market = Platform::findOrFail((int) $this->option('platform'));
        $clients = Client::where('platform_id', $market->id)->where('id', '>', (int) $this->option('after'))->orderBy('id')->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        $rows = [];
        $held = [];
        foreach ($clients as $c) {
            if (! $c->wp_post_id || ! $c->wp_user_id) {
                $held[] = ['client_id' => $c->id, 'reason' => 'source_identity_missing'];

                continue;
            }
            try {
                $source = WpSyncService::forPlatform($market->id)->getClient((int) $c->wp_post_id);
            } catch (\Throwable$e) {
                $held[] = ['client_id' => $c->id, 'reason' => 'source_unavailable'];

                continue;
            }
            if ((int) ($source['wp_user_id'] ?? 0) !== (int) $c->wp_user_id) {
                $held[] = ['client_id' => $c->id, 'reason' => 'source_identity_conflict'];

                continue;
            }
            $parsed = PhoneNormalizer::parse($source['phone'] ?? null, (string) $market->phone_prefix);
            $phone = $parsed['canonical'];
            if (! $phone) {
                $held[] = ['client_id' => $c->id, 'reason' => $parsed['reason']];

                continue;
            }
            if ($phone === $c->phone_normalized) {
                continue;
            }
            $rows[] = ['client_id' => $c->id, 'before' => $c->getAttributes(), 'after' => array_replace($c->getAttributes(), ['phone_normalized' => $phone]), 'source_digest' => hash('sha256', (string) ($source['phone'] ?? ''))];
        }
        $counts = array_count_values(array_column(array_column($rows, 'after'), 'phone_normalized'));
        $safe = [];
        foreach ($rows as $r) {
            $phone = $r['after']['phone_normalized'];
            if ($counts[$phone] > 1 || Client::where('platform_id', $market->id)->where('id', '!=', $r['client_id'])->where('phone_normalized', $phone)->exists()) {
                $held[] = ['client_id' => $r['client_id'], 'reason' => 'phone_collision'];
            } else {
                $safe[] = $r;
            }
        }
        $hash = hash('sha256', json_encode(['platform_id' => $market->id, 'prefix' => $market->phone_prefix, 'parser_version' => PhoneNormalizer::VERSION, 'rows' => $safe], JSON_THROW_ON_ERROR));
        $this->line(json_encode(['expected' => $hash, 'parser_version' => PhoneNormalizer::VERSION, 'market' => $market->id, 'next_after' => $clients->last()?->id, 'changes' => array_column($safe, 'client_id'), 'held' => $held], JSON_PRETTY_PRINT));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        if (! hash_equals($hash, (string) $this->option('expected'))) {
            throw new \RuntimeException('Review a fresh identical preview.');
        }
        $dir = storage_path('app/private/payment-phone-backups');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Backup unavailable.');
        }chmod($dir, 0700);
        $path = $dir.'/'.gmdate('YmdHis').'-'.bin2hex(random_bytes(6)).'.json';
        $body = json_encode(['format' => 'payment-phones-v1', 'rows' => $safe], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $f = fopen($path, 'x');
        if (! $f) {
            throw new \RuntimeException('Backup unavailable.');
        }$written = fwrite($f, $body);
        fflush($f);
        fclose($f);
        chmod($path, 0600);
        if ($written !== strlen($body) || ! hash_equals(hash('sha256', $body), hash_file('sha256', $path))) {
            throw new \RuntimeException('Backup verification failed.');
        }
        $this->writeRows($safe, false);
        $this->line('Verified '.count($safe).' cache-only phone changes. Backup: '.$path);
        $this->line('Restore preview: php artisan crm:reindex-payment-phones --restore='.escapeshellarg($path));

        return self::SUCCESS;
    }

    private function restore(): int
    {
        $base = realpath(storage_path('app/private/payment-phone-backups'));
        $path = realpath($this->option('restore'));
        if (! $base || ! $path || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Use an original private phone backup.');
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($data['format'] ?? '') !== 'payment-phones-v1' || ! is_array($data['rows'] ?? null) || count($data['rows']) > 100) {
            throw new \RuntimeException('Invalid backup.');
        }
        foreach ($data['rows'] as $r) {
            if (Client::findOrFail($r['client_id'])->getAttributes() !== $r['after']) {
                throw new \RuntimeException('Client changed; preserve newer evidence.');
            }
        }
        $hash = hash_file('sha256', $path);
        $this->line(json_encode(['expected' => $hash, 'restore_clients' => array_column($data['rows'], 'client_id')]));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        if (! hash_equals($hash, (string) $this->option('expected'))) {
            throw new \RuntimeException('Review restore preview first.');
        }
        $this->writeRows($data['rows'], true);
        $this->info('Exact phone restore verified.');

        return self::SUCCESS;
    }

    private function writeRows(array $rows, bool $restore): void
    {
        DB::transaction(function () use ($rows, $restore) {
            foreach ($rows as $r) {
                $c = Client::whereKey($r['client_id'])->lockForUpdate()->firstOrFail();
                $before = $restore ? $r['after'] : $r['before'];
                $after = $restore ? $r['before'] : $r['after'];
                if ($c->getAttributes() !== $before) {
                    throw new \RuntimeException('Client changed since preview.');
                }
                if (! $restore && Client::where('platform_id', $c->platform_id)->where('id', '!=', $c->id)->where('phone_normalized', $after['phone_normalized'])->exists()) {
                    throw new \RuntimeException('New phone collision; review again.');
                }
                DB::table('clients')->where('id', $c->id)->update(['phone_normalized' => $after['phone_normalized']]);
                if (! app(AuditService::class)->record(['platform_id' => $c->platform_id, 'entity_type' => 'client', 'entity_id' => $c->id, 'action' => $restore ? 'payment_phone_restore' : 'payment_phone_reindex', 'before_state' => ['phone_sha256' => hash('sha256', (string) $before['phone_normalized'])], 'after_state' => ['phone_sha256' => hash('sha256', (string) $after['phone_normalized']), 'parser_version' => PhoneNormalizer::VERSION, 'source_digest' => $r['source_digest']], 'reason' => 'Exact source identity and collision-checked cache normalization; no receipt, customer identity or benefit change.'])) {
                    throw new \RuntimeException('Audit unavailable.');
                }
                if ($c->fresh()->getAttributes() !== $after) {
                    throw new \RuntimeException('Phone post-state differs.');
                }
            }
        });
    }
}
