<?php

namespace App\Services\Mcp\Knowledge;

use App\Models\McpSemanticRelease;
use RuntimeException;

class OntologyRegistry
{
    public function active(): array
    {
        $release = McpSemanticRelease::query()->where('active_slot', 1)->first();
        $version = $release?->ontology_version ?: 'v1.0.0';
        $path = resource_path("mcp/ontology/{$version}.json");
        if (! is_file($path)) {
            throw new RuntimeException('The active MCP ontology is unavailable.');
        }
        $json = file_get_contents($path);
        $ontology = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', (string) $json);
        if ($release && ! hash_equals($release->ontology_sha256, $hash)) {
            throw new RuntimeException('The active MCP ontology hash does not match its release.');
        }

        return ['ontology' => $ontology, 'version' => (string) ($ontology['version'] ?? '1.0.0'), 'sha256' => $hash];
    }
}
