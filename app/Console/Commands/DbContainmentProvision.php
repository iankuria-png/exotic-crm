<?php

namespace App\Console\Commands;

use App\Models\DbContainmentMarket;
use App\Models\Platform;
use Illuminate\Console\Command;

class DbContainmentProvision extends Command
{
    protected $signature = 'crm:db-containment-provision {platform} {file : Private 0600 JSON configuration outside public/}';

    protected $description = 'Provision a named market using private configuration; does not perform any containment';

    public function handle(): int
    {
        $platform = Platform::query()->findOrFail($this->argument('platform'));
        $path = $this->argument('file');
        $real = realpath($path);
        if (! $real || is_link($path) || str_starts_with($real, realpath(public_path()).'/') || (fileperms($real) & 0077)) {
            $this->error('Use a private 0600 regular config file outside public/.');

            return self::FAILURE;
        }
        $config = json_decode(file_get_contents($real), true, 64, JSON_THROW_ON_ERROR);
        foreach (['enabled', 'filesystem_enabled', 'quarantine_enabled'] as $field) {
            if (! is_bool($config[$field] ?? false)) {
                throw new \InvalidArgumentException('Explicit boolean switches required.');
            }
        }
        DbContainmentMarket::query()->updateOrCreate(['platform_id' => $platform->id], ['enabled' => $config['enabled'] ?? false, 'filesystem_enabled' => $config['filesystem_enabled'] ?? false, 'quarantine_enabled' => $config['quarantine_enabled'] ?? false, 'configuration' => $config['configuration'] ?? []]);
        $this->info('Encrypted containment configuration saved for market '.$platform->id.'. Global switches and canary prerequisites still apply.');

        return self::SUCCESS;
    }
}
