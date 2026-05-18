<?php

namespace App\Console\Commands\Kyc;

use App\Support\KycTranslationCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportTranslations extends Command
{
    protected $signature = 'crm:kyc-export-translations {locale}';

    protected $description = 'Export KYC translation strings to a CSV artifact for a locale.';

    public function handle(): int
    {
        $locale = trim((string) $this->argument('locale'));
        if ($locale === '') {
            $this->error('Locale is required.');
            return self::FAILURE;
        }

        $dir = storage_path('app/kyc-translations/' . $locale);
        File::ensureDirectoryExists($dir);
        $path = $dir . '/kyc-' . $locale . '.csv';
        $existingPo = $dir . '/exotic-kyc-' . $locale . '.po';

        if (!File::exists($existingPo)) {
            $this->warn('No existing locale artifact was found for ' . $locale . '. A fresh CSV will be created.');
        }

        $handle = fopen($path, 'wb');
        if (!$handle) {
            $this->error('Unable to open export file for writing.');
            return self::FAILURE;
        }

        fputcsv($handle, ['key', 'source', 'translation']);
        foreach (KycTranslationCatalog::entries() as $entry) {
            fputcsv($handle, [$entry['key'], $entry['source'], '']);
        }
        fclose($handle);

        $this->info('KYC translation CSV written to: ' . $path);
        return self::SUCCESS;
    }
}
