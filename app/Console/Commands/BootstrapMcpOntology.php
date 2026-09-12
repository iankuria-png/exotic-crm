<?php

namespace App\Console\Commands;

use App\Models\McpSemanticRelease;
use App\Services\Mcp\Knowledge\OntologyRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BootstrapMcpOntology extends Command
{
    protected $signature = 'crm:mcp-bootstrap-ontology';

    protected $description = 'Create the initial immutable MCP ontology release.';

    public function handle(OntologyRegistry $ontology): int
    {
        if (McpSemanticRelease::query()->where('active_slot', 1)->exists()) {
            $this->info('An MCP ontology release is already active.');

            return self::SUCCESS;
        } $active = $ontology->active();
        McpSemanticRelease::create(['public_id' => (string) Str::uuid(), 'ontology_version' => $active['version'], 'ontology_sha256' => $active['sha256'], 'active_slot' => 1, 'activated_at' => now()]);
        $this->info('MCP ontology release created.');

        return self::SUCCESS;
    }
}
