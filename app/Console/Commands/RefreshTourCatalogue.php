<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Models\TourMarketSetting;
use App\Services\Tours\CatalogueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RefreshTourCatalogue extends Command
{
    protected $signature = 'tours:refresh-catalog {--home= : Refresh one home market}';

    protected $description = 'Refresh bounded Tours receiver discovery outside customer requests';

    public function handle(): int
    {
        if (! Schema::hasTable('tour_market_settings')) {
            $this->line('Tours schema is not installed; no work performed.');

            return self::SUCCESS;
        }
        $rows = TourMarketSetting::query()->when($this->option('home'), fn ($q, $id) => $q->where('platform_id', (int) $id))->get();
        $deadline = microtime(true) + 900;
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                $this->warn('Catalogue refresh budget reached; remaining evidence expires normally.');
                break;
            }
            if (empty($row->settings['enabled'])) {
                continue;
            }
            $home = Platform::find($row->platform_id);
            if (! $home || ! $home->is_active) {
                continue;
            }
            $result = app(CatalogueService::class)->refresh($home, $row, $deadline);
            $this->line(json_encode(['platform_id' => $home->id] + $result, JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
