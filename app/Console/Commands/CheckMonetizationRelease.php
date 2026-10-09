<?php

namespace App\Console\Commands;

use App\Services\Monetization\SetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckMonetizationRelease extends Command
{
    protected $signature = 'monetization:check-release {--database : Also verify guided-setup migration columns}';

    protected $description = 'Verify the required Monetize application files and compiled frontend before rollout';

    public function handle(SetupService $setup): int
    {
        $missing = $setup->deployment()['missing'];
        $manifest = json_decode(@file_get_contents(public_path('build/manifest.json')) ?: '{}', true);
        $entry = $manifest['resources/js/app.jsx'] ?? [];
        foreach (array_merge([$entry['file'] ?? 'manifest-entry-missing'], $entry['css'] ?? []) as $asset) {
            if (! is_file(public_path('build/'.$asset))) {
                $missing[] = 'public/build/'.$asset;
            }
        }
        if ($this->option('database') && ! Schema::hasColumns('content_monetization_settings', ['preflight_json', 'setup_json'])) {
            $missing[] = 'Guided setup migration: run php artisan migrate --force';
        }
        if ($missing) {
            $this->error('Monetize release is incomplete. Upload/pull the complete release before rollout.');
            foreach ($missing as $path) {
                $this->line($path);
            }

            return self::FAILURE;
        }
        $this->info('Monetize release files are complete. This does not verify WordPress delivery or a real payment.');

        return self::SUCCESS;
    }
}
