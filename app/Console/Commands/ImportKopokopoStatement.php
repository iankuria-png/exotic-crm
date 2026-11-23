<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Collections\CollectionException;
use App\Services\Collections\Kopokopo\KopokopoFeedService;
use App\Services\Collections\Kopokopo\KopokopoStatementImport;
use Illuminate\Console\Command;

class ImportKopokopoStatement extends Command
{
    protected $signature = 'collections:kopokopo-import {file} {--apply} {--actor=} {--expected-sha256=}';

    protected $description = 'Preview or explicitly import historical Kenya till 268491 receipts; never grants service.';

    public function handle(KopokopoStatementImport $import, KopokopoFeedService $feeds): int
    {
        try {
            $statement = $import->read($this->argument('file'));
            $out = ['mode' => $this->option('apply') ? 'apply' : 'preview', 'till' => '268491', 'rows' => count($statement['rows']),
                'historical_only' => true, 'sha256' => $statement['sha256']];
            if ($this->option('apply')) {
                if (! hash_equals($statement['sha256'], (string) $this->option('expected-sha256')) || ! ctype_digit((string) $this->option('actor'))) {
                    throw new CollectionException('provider_import_actor_and_preview_hash_required', 422);
                }
                $feed = $feeds->feed() ?? throw new CollectionException('provider_setup_required', 409);
                $out += $import->import($feed, $statement, User::findOrFail((int) $this->option('actor')));
            }
            $this->line(json_encode($out, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (CollectionException $e) {
            $this->error($e->reason);

            return self::FAILURE;
        }
    }
}
