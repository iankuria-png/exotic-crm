<?php

namespace App\Services\Mcp\Knowledge;

use App\Models\McpSchemaAudit;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SchemaDriftDetector
{
    private const TABLES = ['clients', 'payments', 'deals', 'platforms', 'payment_attempts', 'billing_webhook_events'];

    public function audit(): McpSchemaAudit
    {
        $ontology = app(OntologyRegistry::class)->active();
        $shape = [];
        foreach (self::TABLES as $table) {
            $shape[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : null;
        }
        $missing = array_keys(array_filter($shape, fn ($columns) => $columns === null));

        return McpSchemaAudit::query()->create([
            'public_id' => (string) Str::uuid(), 'ontology_version' => $ontology['version'], 'ontology_sha256' => $ontology['sha256'],
            'schema_fingerprint' => hash('sha256', json_encode($shape)), 'status' => $missing ? 'drift' : 'pass',
            'affected_capabilities' => $missing ? ['diagnostics'] : [], 'rule_ids' => $missing ? ['missing_required_table'] : [],
            'started_at' => now(), 'completed_at' => now(),
        ]);
    }
}
