<?php

namespace App\Console\Commands;

use App\Services\University\MintlifySeedService;
use App\Services\University\UniversityPhase2Seeder;
use Illuminate\Console\Command;

class SeedUniversityCommand extends Command
{
    protected $signature = 'crm:seed-university {--legacy : Run the original Phase 1 stub seeder instead}';

    protected $description = 'Seed Exotic Online University courses, lessons, certification questions, glossary, badges, and daily drills with deep Phase 2 content.';

    public function handle(MintlifySeedService $legacy, UniversityPhase2Seeder $phase2): int
    {
        if ($this->option('legacy')) {
            $result = $legacy->seedDraftUniversity();
            $this->info('University legacy draft seed complete.');
        } else {
            $result = $phase2->run();
            $this->info('University Phase 2 content seed complete.');
        }

        $this->table(['Metric', 'Created'], collect($result)->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }
}
