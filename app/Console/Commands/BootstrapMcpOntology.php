<?php

namespace App\Console\Commands;

use App\Services\Mcp\Knowledge\OntologyReleaseService;
use Illuminate\Console\Command;

class BootstrapMcpOntology extends Command
{
    protected $signature = 'crm:mcp-bootstrap-ontology';

    protected $description = 'Create the initial immutable MCP ontology release.';

    public function handle(OntologyReleaseService $releases): int
    {
        $release = $releases->bootstrap();
        $this->info('MCP ontology release is active: '.$release->ontology_version.'.');

        return self::SUCCESS;
    }
}
