<?php

namespace App\Console\Commands;

use App\Services\University\MintlifySeedService;
use Illuminate\Console\Command;

class SeedUniversityCommand extends Command
{
    protected $signature = 'crm:seed-university';

    protected $description = 'Seed Exotic Online University draft courses and certification questions.';

    public function handle(MintlifySeedService $seeder): int
    {
        $result = $seeder->seedDraftUniversity();

        $this->info('University draft seed complete.');
        $this->table(['Metric', 'Value'], collect($result)->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }
}
