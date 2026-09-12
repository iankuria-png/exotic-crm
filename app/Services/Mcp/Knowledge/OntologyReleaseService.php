<?php

namespace App\Services\Mcp\Knowledge;

use App\Models\McpSemanticRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OntologyReleaseService
{
    public function bootstrap(?int $actorId = null): McpSemanticRelease
    {
        return DB::transaction(function () use ($actorId) {
            $active = McpSemanticRelease::query()->where('active_slot', 1)->lockForUpdate()->first();
            if ($active) {
                return $active;
            }

            $ontology = app(OntologyRegistry::class)->active();

            return McpSemanticRelease::create([
                'public_id' => (string) Str::uuid(),
                'ontology_version' => $ontology['version'],
                'ontology_sha256' => $ontology['sha256'],
                'active_slot' => 1,
                'activated_by' => $actorId,
                'activated_at' => now(),
            ]);
        });
    }
}
