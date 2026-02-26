<?php

namespace App\Console\Commands;

use App\Models\PaymentImportBatch;
use App\Models\Platform;
use App\Models\User;
use App\Services\PaymentImportService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class ImportPayments extends Command
{
    protected $signature = 'crm:import-payments
        {platform_id : Target market/platform ID}
        {file : Path to CSV/XLSX file}
        {--commit : Commit immediately after preview}
        {--no-header : Parse file as data-only (no header row)}
        {--default-currency= : Override fallback currency}
        {--reason= : Import reason for audit context}
        {--actor-id= : User ID to attribute import actions to}';

    protected $description = 'Preview or commit payment imports from CSV/XLSX into CRM payment queue.';

    public function handle(PaymentImportService $paymentImportService): int
    {
        $platformId = (int) $this->argument('platform_id');
        $filePath = (string) $this->argument('file');
        $shouldCommit = (bool) $this->option('commit');
        $hasHeader = !$this->option('no-header');
        $reason = trim((string) ($this->option('reason') ?: ($shouldCommit ? 'CLI import commit' : 'CLI import preview')));
        $defaultCurrency = $this->option('default-currency');
        $actorIdOption = $this->option('actor-id');

        $platform = Platform::query()->find($platformId);
        if (!$platform) {
            $this->error("Platform {$platformId} not found.");
            return self::FAILURE;
        }

        if (!is_file($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        $actor = $this->resolveActor($actorIdOption);
        if (!$actor) {
            $this->error('No actor user found. Provide --actor-id or create an active user.');
            return self::FAILURE;
        }

        $uploadedFile = new UploadedFile(
            $filePath,
            basename($filePath),
            mime_content_type($filePath) ?: null,
            null,
            true
        );

        try {
            $preview = $paymentImportService->previewImport(
                $uploadedFile,
                $platform,
                (int) $actor->id,
                $hasHeader,
                $reason,
                $defaultCurrency ? (string) $defaultCurrency : null
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info("Preview batch #{$preview['batch_id']} created for {$platform->name}.");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total rows', (string) ($preview['summary']['total_rows'] ?? 0)],
                ['Valid rows', (string) ($preview['summary']['valid_rows'] ?? 0)],
                ['Duplicate rows', (string) ($preview['summary']['duplicate_rows'] ?? 0)],
                ['Invalid rows', (string) ($preview['summary']['invalid_rows'] ?? 0)],
            ]
        );

        if (!$shouldCommit) {
            $this->line('Preview complete. Re-run with --commit to persist payments.');
            return self::SUCCESS;
        }

        $batch = PaymentImportBatch::query()->find((int) $preview['batch_id']);
        if (!$batch) {
            $this->error('Preview batch could not be loaded for commit.');
            return self::FAILURE;
        }

        $commit = $paymentImportService->commitImport(
            $batch,
            (int) $actor->id,
            $reason
        );

        $this->info("Commit finished for batch #{$commit['batch_id']}.");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows committed (total)', (string) ($commit['summary']['committed_rows'] ?? 0)],
                ['Rows created now', (string) ($commit['summary']['created_now'] ?? 0)],
                ['Rows duplicate', (string) ($commit['summary']['duplicate_rows'] ?? 0)],
                ['Rows invalid', (string) ($commit['summary']['invalid_rows'] ?? 0)],
            ]
        );

        return self::SUCCESS;
    }

    private function resolveActor(mixed $actorIdOption): ?User
    {
        if ($actorIdOption !== null && trim((string) $actorIdOption) !== '') {
            return User::query()->where('status', 'active')->find((int) $actorIdOption);
        }

        return User::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }
}
