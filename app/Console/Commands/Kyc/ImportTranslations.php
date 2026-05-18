<?php

namespace App\Console\Commands\Kyc;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

class ImportTranslations extends Command
{
    protected $signature = 'crm:kyc-import-translations {locale} {file}';

    protected $description = 'Import a KYC translation CSV and generate deployable .po/.mo artifacts.';

    public function handle(): int
    {
        $locale = trim((string) $this->argument('locale'));
        $inputFile = (string) $this->argument('file');
        if (!File::exists($inputFile)) {
            $this->error('Translation file not found: ' . $inputFile);
            return self::FAILURE;
        }

        $rows = $this->readCsv($inputFile);
        if ($rows === []) {
            $this->error('No translation rows were found in the CSV.');
            return self::FAILURE;
        }

        $dir = storage_path('app/kyc-translations/' . $locale);
        File::ensureDirectoryExists($dir);

        $poPath = $dir . '/exotic-kyc-' . $locale . '.po';
        $moPath = $dir . '/exotic-kyc-' . $locale . '.mo';
        $zipPath = $dir . '/exotic-kyc-' . $locale . '-artifact.zip';

        File::put($poPath, $this->buildPo($locale, $rows));

        $process = new Process(['msgfmt', $poPath, '-o', $moPath]);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->error('msgfmt failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
            return self::FAILURE;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Unable to create artifact zip.');
            return self::FAILURE;
        }
        $zip->addFile($poPath, basename($poPath));
        $zip->addFile($moPath, basename($moPath));
        $zip->addFile($inputFile, basename($inputFile));
        $zip->close();

        $this->info('KYC translation artifacts created:');
        $this->line('PO:  ' . $poPath);
        $this->line('MO:  ' . $moPath);
        $this->line('ZIP: ' . $zipPath);

        return self::SUCCESS;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return [];
        }

        $header = fgetcsv($handle) ?: [];
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'key' => (string) ($row['key'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'translation' => (string) ($row['translation'] ?? ''),
            ];
        }
        fclose($handle);

        return array_values(array_filter($rows, fn (array $row) => $row['key'] !== '' && $row['source'] !== ''));
    }

    private function buildPo(string $locale, array $rows): string
    {
        $header = [
            'msgid ""',
            'msgstr ""',
            '"Project-Id-Version: exotic-kyc\\n"',
            '"Language: ' . addslashes($locale) . '\\n"',
            '"Content-Type: text/plain; charset=UTF-8\\n"',
            '"Content-Transfer-Encoding: 8bit\\n"',
            '',
        ];

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = '#. ' . $row['key'];
            $entries[] = 'msgid "' . $this->escapePo($row['source']) . '"';
            $entries[] = 'msgstr "' . $this->escapePo($row['translation']) . '"';
            $entries[] = '';
        }

        return implode("\n", array_merge($header, $entries));
    }

    private function escapePo(string $value): string
    {
        return str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', ''], $value);
    }
}
