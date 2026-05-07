<?php

namespace App\Http\Controllers\CRM\Faq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faq\StoreArticleRequest;
use App\Http\Requests\Faq\UpdateArticleRequest;
use App\Models\Faq\Article;
use App\Models\Faq\ArticleContext;
use App\Models\Faq\Cta;
use App\Models\Faq\SearchLog;
use App\Services\AuditService;
use App\Services\FaqSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly FaqSearchService $searchService
    ) {
    }

    public function index(Request $request)
    {
        $isAdmin = $this->isAdmin($request);
        $query = Article::query()
            ->with(['category', 'ctas.walkthrough', 'media', 'contexts'])
            ->withCount(['feedback', 'media'])
            ->orderBy('position')
            ->orderByDesc('published_at')
            ->orderBy('title');

        if (!$isAdmin) {
            $query->where('status', 'published');
        }

        if ($request->filled('status') && $isAdmin) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->integer('category_id'));
        }

        if ($request->filled('category_slug')) {
            $categorySlug = (string) $request->string('category_slug');
            $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('slug', $categorySlug));
        }

        if ($request->filled('crm_page')) {
            $crmPage = (string) $request->string('crm_page');
            $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('crm_page', $crmPage));
        }

        $search = trim((string) $request->string('search'));
        if ($search !== '') {
            $query = $this->searchService->apply($query, $search);
        }

        if ($request->boolean('most_viewed')) {
            $query->reorder()->orderByDesc('view_count')->orderBy('title');
        }

        $perPage = min(max((int) $request->integer('per_page', 12), 1), 100);
        $articles = $query->paginate($perPage);

        $searchLog = null;
        if ($search !== '') {
            $searchLog = SearchLog::create([
                'user_id' => optional($request->user())->id,
                'query' => $search,
                'result_count' => (int) $articles->total(),
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'articles' => $articles->getCollection()->map(fn (Article $article) => $this->serializeArticle($article, $isAdmin))->values(),
            'pagination' => [
                'current_page' => $articles->currentPage(),
                'last_page' => $articles->lastPage(),
                'per_page' => $articles->perPage(),
                'total' => $articles->total(),
            ],
            'search_log_id' => $searchLog?->id,
        ]);
    }

    public function show(Request $request, Article $article)
    {
        $isAdmin = $this->isAdmin($request);
        if (!$isAdmin && $article->status !== 'published') {
            abort(404);
        }

        if ($request->filled('search_log_id')) {
            SearchLog::query()
                ->whereKey((int) $request->integer('search_log_id'))
                ->where(function ($query) use ($request) {
                    $query->whereNull('user_id')->orWhere('user_id', optional($request->user())->id);
                })
                ->update(['clicked_article_id' => $article->id]);
        }

        $article->load(['category', 'ctas.walkthrough', 'media', 'contexts', 'author:id,name', 'lastEditor:id,name']);
        $article->increment('view_count');
        $article->refresh();

        $related = Article::query()
            ->with('category')
            ->where('category_id', $article->category_id)
            ->whereKeyNot($article->id)
            ->when(!$isAdmin, fn ($query) => $query->where('status', 'published'))
            ->orderBy('position')
            ->orderBy('title')
            ->limit(5)
            ->get()
            ->map(fn (Article $relatedArticle) => $this->serializeArticle($relatedArticle, $isAdmin))
            ->values();

        return response()->json([
            'article' => $this->serializeArticle($article, $isAdmin, includeBodyDraft: $isAdmin, includeRelations: true),
            'related_articles' => $related,
        ]);
    }

    public function store(StoreArticleRequest $request)
    {
        $validated = $request->validated();
        $ctas = $validated['ctas'] ?? [];
        $contexts = $validated['contexts'] ?? [];
        unset($validated['ctas']);
        unset($validated['contexts']);

        $article = DB::transaction(function () use ($validated, $request, $ctas, $contexts) {
            $payload = $validated;
            $payload['author_id'] = $request->user()->id;
            $payload['last_editor_id'] = $request->user()->id;
            if (($payload['status'] ?? 'draft') === 'published' && empty($payload['published_at'])) {
                $payload['published_at'] = now();
            }

            $article = Article::create($payload);
            $this->syncCtas($article, $ctas);
            $this->syncContexts($article, $contexts);

            return $article->load(['category', 'ctas.walkthrough', 'media', 'contexts']);
        });

        $this->auditService->fromSystemRequest(
            $request,
            'faq_article_created',
            'faq_article',
            (int) $article->id,
            null,
            $this->articleAuditState($article),
            'Created FAQ article'
        );

        return response()->json([
            'message' => 'FAQ article created.',
            'article' => $this->serializeArticle($article, true, includeBodyDraft: true, includeRelations: true),
        ], 201);
    }

    public function update(UpdateArticleRequest $request, Article $article)
    {
        $validated = $request->validated();
        $ctas = $validated['ctas'] ?? null;
        $contexts = $validated['contexts'] ?? null;
        unset($validated['ctas']);
        unset($validated['contexts']);

        $before = $this->articleAuditState($article->load(['ctas', 'media', 'category', 'contexts']));

        $article = DB::transaction(function () use ($validated, $request, $article, $ctas, $contexts) {
            $payload = $validated;
            $payload['last_editor_id'] = $request->user()->id;
            if (($payload['status'] ?? null) === 'published' && !$article->published_at) {
                $payload['published_at'] = now();
            }
            if (($payload['status'] ?? null) !== 'published' && array_key_exists('status', $payload) && $payload['status'] !== 'published') {
                $payload['published_at'] = $payload['status'] === 'draft' ? null : $article->published_at;
            }

            $article->fill($payload);
            $article->save();

            if (is_array($ctas)) {
                $this->syncCtas($article, $ctas);
            }

            if (is_array($contexts)) {
                $this->syncContexts($article, $contexts);
            }

            return $article->load(['category', 'ctas.walkthrough', 'media', 'contexts']);
        });

        $this->auditService->fromSystemRequest(
            $request,
            'faq_article_updated',
            'faq_article',
            (int) $article->id,
            $before,
            $this->articleAuditState($article),
            'Updated FAQ article'
        );

        return response()->json([
            'message' => 'FAQ article updated.',
            'article' => $this->serializeArticle($article, true, includeBodyDraft: true, includeRelations: true),
        ]);
    }

    public function saveDraft(UpdateArticleRequest $request, Article $article)
    {
        $validated = $request->validated();
        $before = $this->articleAuditState($article->loadMissing('contexts'));

        $article->fill([
            'title' => $validated['title'] ?? $article->title,
            'summary' => array_key_exists('summary', $validated) ? $validated['summary'] : $article->summary,
            'body_draft' => $validated['body_draft'] ?? $validated['body'] ?? $article->body_draft,
            'last_editor_id' => $request->user()->id,
        ]);
        $article->save();

        if (is_array($validated['contexts'] ?? null)) {
            $this->syncContexts($article, $validated['contexts']);
        }

        $this->auditService->fromSystemRequest(
            $request,
            'faq_article_draft_saved',
            'faq_article',
            (int) $article->id,
            $before,
            $this->articleAuditState($article->fresh()->load('contexts')),
            'Saved FAQ article draft'
        );

        return response()->json([
            'message' => 'Draft saved.',
            'article' => $this->serializeArticle($article->fresh()->load(['category', 'ctas.walkthrough', 'media', 'contexts']), true, includeBodyDraft: true, includeRelations: true),
        ]);
    }

    public function publish(Request $request, Article $article)
    {
        $before = $this->articleAuditState($article->loadMissing('contexts'));
        $article->forceFill([
            'body' => $article->body_draft ?: $article->body,
            'status' => 'published',
            'published_at' => $article->published_at ?: now(),
            'last_editor_id' => $request->user()->id,
        ])->save();

        $this->auditService->fromSystemRequest(
            $request,
            'faq_article_published',
            'faq_article',
            (int) $article->id,
            $before,
            $this->articleAuditState($article->fresh()->load('contexts')),
            'Published FAQ article'
        );

        return response()->json([
            'message' => 'FAQ article published.',
            'article' => $this->serializeArticle($article->fresh()->load(['category', 'ctas.walkthrough', 'media', 'contexts']), true, includeBodyDraft: true, includeRelations: true),
        ]);
    }

    public function duplicate(Request $request, Article $article)
    {
        $article->loadMissing(['ctas', 'media', 'contexts']);

        $copy = DB::transaction(function () use ($request, $article) {
            $baseSlug = Str::limit($article->slug . '-copy', 170, '');
            $slug = $baseSlug;
            $suffix = 2;
            while (Article::query()->where('slug', $slug)->exists()) {
                $slug = Str::limit($baseSlug . '-' . $suffix, 180, '');
                $suffix++;
            }

            $clone = Article::create([
                'category_id' => $article->category_id,
                'slug' => $slug,
                'title' => $article->title . ' (Copy)',
                'summary' => $article->summary,
                'body' => $article->body,
                'body_draft' => $article->body_draft,
                'status' => 'draft',
                'author_id' => $request->user()->id,
                'last_editor_id' => $request->user()->id,
                'position' => $article->position,
            ]);

            foreach ($article->ctas as $cta) {
                $clone->ctas()->create([
                    'position' => $cta->position,
                    'kind' => $cta->kind,
                    'label' => $cta->label,
                    'target_path' => $cta->target_path,
                    'prefill_payload' => $cta->prefill_payload,
                    'walkthrough_id' => $cta->walkthrough_id,
                ]);
            }

            foreach ($article->media as $media) {
                $clone->media()->create([
                    'kind' => $media->kind,
                    'disk_path' => $media->disk_path,
                    'mime' => $media->mime,
                    'size_bytes' => $media->size_bytes,
                    'caption' => $media->caption,
                    'position' => $media->position,
                ]);
            }

            foreach ($article->contexts as $context) {
                $clone->contexts()->create([
                    'crm_page' => $context->crm_page,
                    'surface' => $context->surface,
                    'context_kind' => $context->context_kind,
                    'priority' => $context->priority,
                ]);
            }

            return $clone->load(['category', 'ctas.walkthrough', 'media', 'contexts']);
        });

        $this->auditService->fromSystemRequest(
            $request,
            'faq_article_duplicated',
            'faq_article',
            (int) $copy->id,
            null,
            $this->articleAuditState($copy),
            'Duplicated FAQ article'
        );

        return response()->json([
            'message' => 'FAQ article duplicated.',
            'article' => $this->serializeArticle($copy, true, includeBodyDraft: true, includeRelations: true),
        ], 201);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:faq_articles,id'],
        ]);

        foreach (array_values($validated['ids']) as $index => $id) {
            Article::query()->whereKey($id)->update(['position' => $index + 1]);
        }

        return response()->json([
            'message' => 'FAQ articles reordered.',
        ]);
    }

    public function destroy(Request $request, Article $article)
    {
        $before = $this->articleAuditState($article->load(['ctas', 'media', 'category', 'contexts']));
        $articleId = (int) $article->id;
        $article->delete();

        $this->auditService->fromSystemRequest(
            $request,
            'faq_article_deleted',
            'faq_article',
            $articleId,
            $before,
            null,
            'Deleted FAQ article'
        );

        return response()->json([
            'message' => 'FAQ article deleted.',
        ]);
    }

    private function syncCtas(Article $article, array $rows): void
    {
        $existingIds = $article->ctas()->pluck('id')->all();
        $seenIds = [];

        foreach (array_values($rows) as $index => $row) {
            $payload = [
                'position' => $row['position'] ?? ($index + 1),
                'kind' => $row['kind'],
                'label' => $row['label'],
                'target_path' => $row['target_path'] ?? null,
                'prefill_payload' => $row['prefill_payload'] ?? null,
                'walkthrough_id' => $row['walkthrough_id'] ?? null,
            ];

            if (!empty($row['id'])) {
                $cta = $article->ctas()->whereKey((int) $row['id'])->first();
                if ($cta) {
                    $cta->update($payload);
                    $seenIds[] = (int) $cta->id;
                    continue;
                }
            }

            $cta = $article->ctas()->create($payload);
            $seenIds[] = (int) $cta->id;
        }

        $deleteIds = array_values(array_diff($existingIds, $seenIds));
        if ($deleteIds !== []) {
            Cta::query()->whereIn('id', $deleteIds)->delete();
        }
    }

    private function syncContexts(Article $article, array $rows): void
    {
        $article->contexts()->delete();

        foreach (array_values($rows) as $index => $row) {
            if (empty($row['crm_page']) || empty($row['context_kind'])) {
                continue;
            }

            $article->contexts()->create([
                'crm_page' => $row['crm_page'],
                'surface' => $row['surface'] ?? 'help_drawer',
                'context_kind' => $row['context_kind'],
                'priority' => (int) ($row['priority'] ?? ($index + 1)),
            ]);
        }
    }

    private function serializeArticle(Article $article, bool $isAdmin, bool $includeBodyDraft = false, bool $includeRelations = false): array
    {
        return [
            'id' => $article->id,
            'category_id' => $article->category_id,
            'slug' => $article->slug,
            'title' => $article->title,
            'summary' => $article->summary,
            'body' => $article->body,
            'body_draft' => $includeBodyDraft ? $article->body_draft : null,
            'status' => $article->status,
            'position' => $article->position,
            'view_count' => (int) $article->view_count,
            'helpful_count' => (int) $article->helpful_count,
            'unhelpful_count' => (int) $article->unhelpful_count,
            'published_at' => optional($article->published_at)->toIso8601String(),
            'created_at' => optional($article->created_at)->toIso8601String(),
            'updated_at' => optional($article->updated_at)->toIso8601String(),
            'author' => $includeRelations && $article->relationLoaded('author') ? $article->author : null,
            'last_editor' => $includeRelations && $article->relationLoaded('lastEditor') ? $article->lastEditor : null,
            'category' => $article->relationLoaded('category') ? [
                'id' => $article->category?->id,
                'slug' => $article->category?->slug,
                'name' => $article->category?->name,
                'crm_page' => $article->category?->crm_page,
            ] : null,
            'contexts' => $article->relationLoaded('contexts')
                ? $article->contexts->map(fn (ArticleContext $context) => [
                    'id' => $context->id,
                    'crm_page' => $context->crm_page,
                    'surface' => $context->surface,
                    'context_kind' => $context->context_kind,
                    'priority' => (int) $context->priority,
                ])->values()
                : [],
            'ctas' => $article->relationLoaded('ctas')
                ? $article->ctas->map(fn ($cta) => [
                    'id' => $cta->id,
                    'position' => $cta->position,
                    'kind' => $cta->kind,
                    'label' => $cta->label,
                    'target_path' => $cta->target_path,
                    'prefill_payload' => $cta->prefill_payload,
                    'walkthrough_id' => $cta->walkthrough_id,
                    'walkthrough' => $cta->relationLoaded('walkthrough') && $cta->walkthrough ? [
                        'slug' => $cta->walkthrough->slug,
                        'name' => $cta->walkthrough->name,
                        'steps' => $cta->walkthrough->steps,
                    ] : null,
                ])->values()
                : [],
            'media' => $article->relationLoaded('media')
                ? $article->media->map(fn ($media) => [
                    'id' => $media->id,
                    'kind' => $media->kind,
                    'disk_path' => $isAdmin ? $media->disk_path : null,
                    'mime' => $media->mime,
                    'size_bytes' => (int) $media->size_bytes,
                    'caption' => $media->caption,
                    'position' => $media->position,
                    'url' => $media->url,
                ])->values()
                : [],
            'feedback_count' => isset($article->feedback_count) ? (int) $article->feedback_count : null,
            'media_count' => isset($article->media_count) ? (int) $article->media_count : null,
        ];
    }

    private function articleAuditState(Article $article): array
    {
        return [
            ...$article->toArray(),
            'contexts' => $article->relationLoaded('contexts')
                ? $article->contexts->map(fn (ArticleContext $context) => [
                    'crm_page' => $context->crm_page,
                    'surface' => $context->surface,
                    'context_kind' => $context->context_kind,
                    'priority' => (int) $context->priority,
                ])->values()->all()
                : [],
        ];
    }

    private function isAdmin(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'sub_admin'], true);
    }
}
