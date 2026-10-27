<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAttribution;
use App\Models\TimelineEvent;
use App\Services\PaymentAttributionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPaymentAttribution extends Command
{
    protected $signature = 'crm:backfill-payment-attribution {--after=0} {--limit=100} {--manifest=} {--restore=} {--apply} {--expected=}';

    protected $description = 'Preview bounded original-subject snapshots; apply only an identical reviewed manifest with a private backup.';

    public function handle(): int
    {
        if ($this->option('restore')) {
            return $this->restoreBackup();
        }
        $manifest = $this->option('manifest') ? json_decode(file_get_contents($this->option('manifest')), true, 512, JSON_THROW_ON_ERROR) : [];
        if ($manifest && (! is_array($manifest) || ! array_is_list($manifest) || count($manifest) > 200)) {
            throw new \RuntimeException('Bounded list required.');
        }
        $rows = $manifest ? Payment::whereIn('id', array_column($manifest, 'payment_id'))->orderBy('id')->get() : Payment::where('id', '>', (int) $this->option('after'))->whereNotNull('client_id')->orderBy('id')->limit(max(1, min(200, (int) $this->option('limit'))))->get();
        $proposals = [];
        foreach ($rows as $p) {
            if ($p->isVisitorPayment()) {
                continue;
            }
            if ($p->attribution) {
                continue;
            }
            if ($manifest) {
                $m = collect($manifest)->firstWhere('payment_id', $p->id);
                $proposal = $this->historical($p, $m);
            } else {
                $client = Client::where('platform_id', $p->platform_id)->find($p->client_id);
                if (! $client) {
                    continue;
                }
                $proposal = ['platform_id' => $p->platform_id, 'original_client_id' => $client->id, 'wp_user_id' => $client->wp_user_id, 'wp_post_id' => $client->wp_post_id,
                    'client_first_seen_at' => $client->created_at, 'attribution_state' => 'current', 'evidence_json' => ['method' => 'verified_current_backfill', 'deal_id' => $p->deal_id]];
            }
            $proposals[] = ['payment_id' => $p->id, 'before' => $p->getAttributes(), 'after' => $proposal];
        }
        $expected = hash('sha256', json_encode($proposals, JSON_THROW_ON_ERROR));
        $this->line(json_encode(['expected' => $expected, 'rows' => count($proposals), 'next_after' => $rows->last()?->id, 'proposals' => array_map(fn ($r) => ['payment_id' => $r['payment_id'], 'subject' => $r['after']['original_client_id'], 'state' => $r['after']['attribution_state']], $proposals)], JSON_PRETTY_PRINT));
        if (! $this->option('apply')) {
            $this->info('PREVIEW ONLY; no rows changed.');

            return self::SUCCESS;
        }
        if (! hash_equals($expected, (string) $this->option('expected'))) {
            throw new \RuntimeException('Preview changed; review a fresh dry run.');
        }
        $dir = storage_path('app/private/payment-attribution-backups');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Backup unavailable.');
        }chmod($dir, 0700);
        $path = $dir.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
        $bytes = json_encode(['format' => 'payment-attribution-backfill-v1', 'expected' => $expected, 'rows' => $proposals], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $f = fopen($path, 'x');
        if (! $f) {
            throw new \RuntimeException('Backup unavailable.');
        }$written = fwrite($f, $bytes);
        fflush($f);
        fclose($f);
        chmod($path, 0600);
        if ($written !== strlen($bytes) || ! hash_equals(hash('sha256', $bytes), hash_file('sha256', $path))) {
            throw new \RuntimeException('Backup verification failed.');
        }
        DB::transaction(function () use ($proposals) {
            foreach ($proposals as $r) {
                $p = Payment::whereKey($r['payment_id'])->lockForUpdate()->firstOrFail();
                if ($p->getAttributes() !== $r['before'] || $p->attribution) {
                    throw new \RuntimeException('Receipt drift detected.');
                }
                $row = PaymentAttribution::create(['payment_id' => $p->id] + $r['after']);
                app(PaymentAttributionService::class)->audit($p, 'payment_attribution_backfill', null, $row->only(['original_client_id', 'wp_post_id', 'attribution_state', 'evidence_json']), 'Reviewed exact original subject; no cash, client, deal or entitlement change.');
                if ($p->fresh()->getAttributes() !== $r['before']) {
                    throw new \RuntimeException('Receipt unexpectedly changed.');
                }
            }
        });
        $this->info('Verified '.count($proposals));
        $this->line('Backup: '.$path);
        $this->line('Restore preview: php artisan crm:backfill-payment-attribution --restore='.escapeshellarg($path));

        return self::SUCCESS;
    }

    private function restoreBackup(): int
    {
        $base = realpath(storage_path('app/private/payment-attribution-backups'));
        $path = realpath($this->option('restore'));
        if (! $base || ! $path || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Use an original private attribution backup.');
        }
        $backup = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($backup['format'] ?? '') !== 'payment-attribution-backfill-v1' || ! is_array($backup['rows'] ?? null) || count($backup['rows']) > 200) {
            throw new \RuntimeException('Unsupported backup.');
        }
        $hash = hash('sha256', file_get_contents($path));
        $check = function (array $r): void {
            $p = Payment::findOrFail($r['payment_id']);
            if ($p->getAttributes() !== $r['before']) {
                throw new \RuntimeException('Receipt changed since backfill; restore requires review.');
            }
            $a = PaymentAttribution::findOrFail($p->id);
            if ((int) $a->version !== 1) {
                throw new \RuntimeException('Attribution has newer evidence; preserve it.');
            }
            foreach ($r['after'] as $key => $value) {
                $actual = $a->getAttribute($key);
                if ($key === 'client_first_seen_at' && $value !== null) {
                    $actual = $actual?->toIso8601String();
                    $value = \Illuminate\Support\Carbon::parse($value)->toIso8601String();
                }
                if ($actual != $value) {
                    throw new \RuntimeException('Attribution differs from backup; restore refused.');
                }
            }
        };
        foreach ($backup['rows'] as $r) {
            $check($r);
        }
        $this->line(json_encode(['expected' => $hash, 'rows' => count($backup['rows']), 'effect' => 'Remove only unchanged task-created attribution snapshots; retain receipts, clients, benefits and audit history.']));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        if (! hash_equals($hash, (string) $this->option('expected'))) {
            throw new \RuntimeException('Review this restore preview before applying.');
        }
        DB::transaction(function () use ($backup, $check) {
            foreach ($backup['rows'] as $r) {
                $p = Payment::whereKey($r['payment_id'])->lockForUpdate()->firstOrFail();
                PaymentAttribution::whereKey($p->id)->lockForUpdate()->firstOrFail();
                $check($r);
                $before = PaymentAttribution::findOrFail($p->id)->toArray();
                app(PaymentAttributionService::class)->audit($p, 'payment_attribution_restore', $before, ['attribution_removed' => true], 'Restored unchanged attribution backfill from its original private backup.');
                PaymentAttribution::whereKey($p->id)->delete();
            }
        });
        foreach ($backup['rows'] as $r) {
            if (PaymentAttribution::find($r['payment_id']) || Payment::findOrFail($r['payment_id'])->getAttributes() !== $r['before']) {
                throw new \RuntimeException('Restore verification failed.');
            }
        }
        $this->info('Attribution restore verified; cash and benefits unchanged.');

        return self::SUCCESS;
    }

    private function historical(Payment $p, array $m): array
    {
        if ($p->client_id || (int) $p->platform_id !== (int) $m['platform_id']) {
            throw new \RuntimeException('Historical receipt identity changed.');
        }
        $deletion = AuditLog::whereKey($m['deletion_audit_id'])->where('platform_id', $p->platform_id)->where('entity_type', 'client')->where('entity_id', $m['original_client_id'])->whereIn('action', ['client_delete', 'client_auto_purge'])->firstOrFail();
        $snapshot = data_get($deletion->before_state, 'client', []);
        $post = (int) ($snapshot['wp_post_id'] ?? 0);
        if (! $post || $post !== (int) $m['wp_post_id']) {
            throw new \RuntimeException('Deletion identity mismatch.');
        }
        $direct = (int) $p->escort_post_id === $post && $post > 0;
        $rawDeal = (int) data_get($p->raw_payload, 'deal_id');
        $timeline = $rawDeal ? TimelineEvent::where('platform_id', $p->platform_id)->where('entity_type', 'client')->where('entity_id', $m['original_client_id'])->whereIn('event_type', ['profile_activated', 'deal_created'])->where('content->deal_id', $rawDeal)->first() : null;
        $activation = $rawDeal ? AuditLog::where('platform_id', $p->platform_id)->where('entity_type', 'deal')->where('entity_id', $rawDeal)->where('action', 'deal_activate')->where('after_state->payment_id', $p->id)->first() : null;
        if (! $direct && ! ($timeline && $activation)) {
            throw new \RuntimeException('Exact original attribution chain unavailable.');
        }

        return ['platform_id' => $p->platform_id, 'original_client_id' => (int) $m['original_client_id'], 'wp_user_id' => $direct ? $p->user_id : null, 'wp_post_id' => $post, 'client_first_seen_at' => null, 'attribution_state' => 'archived', 'evidence_json' => array_filter(['method' => $direct ? 'retained_wp_post_deletion' : 'activation_timeline_deletion', 'deletion_audit_id' => $deletion->id, 'timeline_id' => $timeline?->id, 'activation_audit_id' => $activation?->id, 'original_deal_id' => $rawDeal ?: null, 'benefit' => $activation ? ['payment_id' => $p->id, 'deal_id' => $rawDeal, 'activated_at' => $activation->created_at->toIso8601String(), 'expires_at' => data_get($activation->after_state, 'expires_at'), 'activation_audit_id' => $activation->id] : null])];
    }
}
