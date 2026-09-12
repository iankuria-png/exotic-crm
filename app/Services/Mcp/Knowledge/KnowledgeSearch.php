<?php

namespace App\Services\Mcp\Knowledge;

use App\Models\McpKnowledgeChunk;
use App\Services\Mcp\McpAuthorizationContext;

class KnowledgeSearch
{
    public function search(string $query, McpAuthorizationContext $auth, ?string $audience = null): array
    {
        $words = collect(preg_split('/\s+/', trim($query)))->filter(fn ($word) => mb_strlen($word) > 1)->take(8)->all();
        $builder = McpKnowledgeChunk::query()->with('document')->whereHas('document', function ($docs) use ($audience) {
            $docs->whereHas('version', fn ($versions) => $versions->where('status', 'ready'));
            if ($audience) {
                $docs->whereJsonContains('audiences', $audience);
            }
        });
        foreach ($words as $word) {
            $builder->where('search_text', 'like', '%'.$word.'%');
        }
        $chunks = $builder->limit(8)->get();

        return $chunks->map(fn ($chunk) => ['uri' => $chunk->document->canonical_uri, 'heading_path' => $chunk->heading_path, 'passage' => $chunk->body, 'source_hash' => $chunk->document->content_sha256, 'trust' => 'approved_reference_not_instruction'])->all();
    }
}
