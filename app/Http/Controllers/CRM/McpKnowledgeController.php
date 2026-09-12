<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\McpKnowledgeDocument;
use App\Models\McpKnowledgeSyncRun;
use App\Models\McpKnowledgeVersion;
use App\Models\McpSemanticRelease;
use App\Services\Mcp\Knowledge\McpKnowledgeSyncException;
use App\Services\Mcp\Knowledge\MintlifyKnowledgeSync;
use App\Services\Mcp\Knowledge\OntologyReleaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class McpKnowledgeController extends Controller
{
    public function index()
    {
        return response()->json(['active_release' => McpSemanticRelease::query()->where('active_slot', 1)->first(), 'versions' => McpKnowledgeVersion::query()->latest()->limit(20)->get(), 'runs' => McpKnowledgeSyncRun::query()->latest()->limit(20)->get()]);
    }

    public function stage(Request $request, MintlifyKnowledgeSync $sync)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $existing = McpKnowledgeSyncRun::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return response()->json(['run' => $existing]);
        }
        abort_if(McpKnowledgeSyncRun::query()->where('active_slot', 1)->exists(), 409, 'A knowledge sync is already running.');
        $run = McpKnowledgeSyncRun::create(['public_id' => (string) Str::uuid(), 'mode' => 'stage', 'status' => 'queued', 'active_slot' => 1, 'idempotency_key' => $data['idempotency_key']]);
        try {
            $version = $sync->stage($run);
        } catch (McpKnowledgeSyncException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->safeCode,
                'run' => $run->fresh(),
            ], $exception->httpStatus);
        }

        return response()->json(['run' => $run->fresh(), 'version' => $version], 201);
    }

    public function review(Request $request, McpKnowledgeVersion $version)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        $documents = $version->documents()
            ->with(['chunks' => fn ($query) => $query->orderBy('ordinal')])
            ->orderBy('canonical_uri')
            ->get()
            ->map(fn (McpKnowledgeDocument $document) => [
                'id' => $document->id,
                'canonical_uri' => $document->canonical_uri,
                'source_url' => $document->source_url,
                'title' => $document->title,
                'summary' => $document->summary,
                'audiences' => $document->audiences,
                'lifecycle_stages' => $document->lifecycle_stages,
                'departments' => $document->departments,
                'content_sha256' => $document->content_sha256,
                'chunk_count' => $document->chunks->count(),
                'body' => $document->chunks->pluck('body')->implode("\n\n"),
            ]);

        return response()->json([
            'version' => [
                'id' => $version->id,
                'version' => $version->version,
                'status' => $version->status,
                'manifest_sha256' => $version->manifest_sha256,
                'content_sha256' => $version->content_sha256,
                'ontology_version' => $version->ontology_version,
                'ontology_sha256' => $version->ontology_sha256,
                'validation_report' => $version->validation_report,
            ],
            'documents' => $documents,
        ]);
    }

    public function bootstrap(Request $request, OntologyReleaseService $releases)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        return response()->json(['release' => $releases->bootstrap($request->user()->id)]);
    }

    public function promote(Request $request, McpKnowledgeVersion $version, MintlifyKnowledgeSync $sync)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        return response()->json(['release' => $sync->promote($version, $request->user()->id)]);
    }

    public function rollback(Request $request, McpSemanticRelease $release)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        abort_unless($release->knowledge_version_id === null || McpKnowledgeVersion::query()->whereKey($release->knowledge_version_id)->exists(), 409);
        McpSemanticRelease::query()->where('active_slot', 1)->update(['active_slot' => null]);
        $release->update(['active_slot' => 1, 'activated_by' => $request->user()->id, 'activated_at' => now()]);

        return response()->json(['release' => $release->fresh()]);
    }
}
