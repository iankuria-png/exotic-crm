<?php

namespace App\Console\Commands;

use App\Services\Mcp\Knowledge\SchemaDriftDetector;
use Illuminate\Console\Command;

class AuditMcpSchema extends Command
{
    protected $signature = 'crm:mcp-audit-schema';

    protected $description = 'Audit only MCP-declared schema dependencies.';

    public function handle(SchemaDriftDetector $detector): int
    {
        $audit = $detector->audit();
        $this->line("{$audit->public_id} {$audit->status}");

        return $audit->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
