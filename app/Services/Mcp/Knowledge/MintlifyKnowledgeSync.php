<?php

namespace App\Services\Mcp\Knowledge;

use App\Models\McpKnowledgeChunk;
use App\Models\McpKnowledgeDocument;
use App\Models\McpKnowledgeSyncRun;
use App\Models\McpKnowledgeVersion;
use App\Models\McpSemanticRelease;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

class MintlifyKnowledgeSync
{
    public function stage(McpKnowledgeSyncRun $run): McpKnowledgeVersion
    {
        $run->update(['status' => 'running', 'started_at' => now(), 'heartbeat_at' => now(), 'attempt' => $run->attempt + 1]);
        try {
            $client = new Client(['timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false, 'http_errors' => false]);
            $manifest = (string) $client->get(config('mcp_knowledge.manifest'))->getBody();
            $matches = [];
            preg_match_all('#https://exoticonline\.mintlify\.app/([^\s?#]+\.md)#', $manifest, $matches);
            $eligible = array_intersect(array_unique($matches[1]), array_keys(config('mcp_knowledge.documents')));
            $ontology = app(OntologyRegistry::class)->active();
            $contents = [];
            foreach (array_slice($eligible, 0, (int) config('mcp_knowledge.max_documents')) as $slug) {
                $response = $client->get('https://'.config('mcp_knowledge.host').'/'.$slug, ['headers' => ['Accept' => 'text/markdown']]);
                if ($response->getStatusCode() !== 200 || ! str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/')) {
                    continue;
                }
                $body = (string) $response->getBody();
                if (strlen($body) > (int) config('mcp_knowledge.max_bytes')) {
                    continue;
                }
                $contents[$slug] = $this->sanitize($body);
            }
            $version = McpKnowledgeVersion::create(['version' => 'knowledge-'.substr(hash('sha256', $manifest.json_encode($contents)), 0, 16), 'status' => 'ready', 'manifest_sha256' => hash('sha256', $manifest), 'content_sha256' => hash('sha256', json_encode($contents)), 'ontology_version' => $ontology['version'], 'ontology_sha256' => $ontology['sha256'], 'validation_report' => ['eligible' => count($eligible), 'staged' => count($contents)]]);
            foreach ($contents as $slug => $body) {
                $meta = config('mcp_knowledge.documents.'.$slug);
                $doc = McpKnowledgeDocument::create(['version_id' => $version->id, 'canonical_uri' => $meta['uri'], 'source_url' => 'https://'.config('mcp_knowledge.host').'/'.$slug, 'title' => str_replace(['-', '/'], ' ', pathinfo($slug, PATHINFO_FILENAME)), 'audiences' => $meta['audiences'], 'lifecycle_stages' => $meta['stages'], 'departments' => $meta['audiences'], 'content_sha256' => hash('sha256', $body), 'classification_key' => $slug]);
                foreach (str_split($body, (int) config('mcp_knowledge.max_chunk_chars')) as $index => $chunk) {
                    McpKnowledgeChunk::create(['document_id' => $doc->id, 'ordinal' => $index, 'heading_path' => $doc->title, 'body' => $chunk, 'token_estimate' => max(1, (int) ceil(mb_strlen($chunk) / 4)), 'search_text' => $doc->title.' '.$chunk]);
                }
            }
            $run->update(['status' => 'complete', 'active_slot' => null, 'eligible' => count($eligible), 'changed' => count($contents), 'finished_at' => now()]);

            return $version;
        } catch (\Throwable $error) {
            $run->update(['status' => 'failed', 'active_slot' => null, 'error_code' => 'sync_failed', 'finished_at' => now()]);
            throw $error;
        }
    }

    public function promote(McpKnowledgeVersion $version, int $actorId): McpSemanticRelease
    {
        if ($version->status !== 'ready') {
            throw new \RuntimeException('Only a ready knowledge version can be promoted.');
        }

return \DB::transaction(function () use ($version, $actorId) {
            McpSemanticRelease::query()->where('active_slot', 1)->update(['active_slot' => null]);
            $release = McpSemanticRelease::create(['public_id' => (string) Str::uuid(), 'ontology_version' => $version->ontology_version, 'ontology_sha256' => $version->ontology_sha256, 'knowledge_version_id' => $version->id, 'active_slot' => 1, 'activated_by' => $actorId, 'activated_at' => now()]);
            $version->update(['promoted_by' => $actorId, 'promoted_at' => now()]);

            return $release;
        });
    }

    private function sanitize(string $body): string
    {
        $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $body) ?? '';

        return str_replace(['<iframe', '<object'], ['&lt;iframe', '&lt;object'], $body);
    }
}
