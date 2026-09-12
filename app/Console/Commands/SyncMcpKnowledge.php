<?php

namespace App\Console\Commands;

use App\Models\McpKnowledgeSyncRun;
use App\Services\Mcp\Knowledge\MintlifyKnowledgeSync;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncMcpKnowledge extends Command
{
    protected $signature = 'crm:mcp-sync-knowledge {--promote}';

    protected $description = 'Stage approved Mintlify knowledge; promotion remains explicit.';

    public function handle(MintlifyKnowledgeSync $sync): int
    {
        $run = McpKnowledgeSyncRun::create(['public_id' => (string) Str::uuid(), 'mode' => 'stage', 'status' => 'queued', 'active_slot' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $version = $sync->stage($run);
        if ($this->option('promote')) {
            $sync->promote($version, 0);
        } $this->line($version->version);

        return self::SUCCESS;
    }
}
