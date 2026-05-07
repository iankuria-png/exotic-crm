<?php

namespace App\Http\Controllers\CRM\Faq;

use App\Http\Controllers\Controller;
use App\Models\Faq\Article;
use App\Models\Faq\SearchLog;
use App\Services\FaqSearchService;
use App\Services\FaqSnippetService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ContextController extends Controller
{
    private const CRM_PAGES = ['dashboard', 'clients', 'client_detail', 'deals', 'payments', 'conversations', 'campaigns', 'leads', 'cross_cutting', 'team'];

    public function __construct(
        private readonly FaqSearchService $searchService,
        private readonly FaqSnippetService $snippetService,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'crm_page' => ['required', Rule::in(self::CRM_PAGES)],
            'surface' => ['nullable', Rule::in(['help_drawer'])],
            'search' => ['nullable', 'string'],
        ]);

        $crmPage = (string) $validated['crm_page'];
        $surface = (string) ($validated['surface'] ?? 'help_drawer');
        $search = trim((string) ($validated['search'] ?? ''));

        $scripts = $this->loadMappedArticles($crmPage, $surface, 'script', $search);
        $scriptIds = $scripts->pluck('id')->all();

        $explicitRunbooks = $this->loadMappedArticles($crmPage, $surface, 'runbook', $search, $scriptIds);
        $excludeIds = array_values(array_unique([...$scriptIds, ...$explicitRunbooks->pluck('id')->all()]));
        $categoryRunbooks = $this->loadCategoryRunbooks($crmPage, $search, $excludeIds);
        $runbooks = $explicitRunbooks->concat($categoryRunbooks)->unique('id')->values();

        $searchLog = null;
        if ($search !== '') {
            $searchLog = SearchLog::create([
                'user_id' => optional($request->user())->id,
                'query' => $search,
                'result_count' => (int) ($scripts->count() + $runbooks->count()),
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'scripts' => $scripts->map(fn (Article $article) => $this->serializeScriptArticle($article))->values(),
            'runbooks' => $runbooks->map(fn (Article $article) => $this->serializeRunbookArticle($article))->values(),
            'meta' => [
                'crm_page' => $crmPage,
                'surface' => $surface,
                'scripts_count' => $scripts->count(),
                'runbooks_count' => $runbooks->count(),
                'total_count' => $scripts->count() + $runbooks->count(),
            ],
            'search_log_id' => $searchLog?->id,
        ]);
    }

    private function loadMappedArticles(string $crmPage, string $surface, string $contextKind, string $search = '', array $excludeIds = []): Collection
    {
        $query = Article::query()
            ->select('faq_articles.*', 'fac.priority as context_priority')
            ->join('faq_article_contexts as fac', function ($join) use ($crmPage, $surface, $contextKind) {
                $join->on('fac.article_id', '=', 'faq_articles.id')
                    ->where('fac.crm_page', '=', $crmPage)
                    ->where('fac.surface', '=', $surface)
                    ->where('fac.context_kind', '=', $contextKind);
            })
            ->with(['category', 'contexts' => fn ($contextQuery) => $contextQuery->where('crm_page', $crmPage)->where('surface', $surface)->orderBy('priority')])
            ->where('faq_articles.status', 'published')
            ->when($excludeIds !== [], fn ($articleQuery) => $articleQuery->whereNotIn('faq_articles.id', $excludeIds));

        if ($search !== '') {
            $query = $this->searchService->apply($query, $search);
        }

        return $query
            ->orderBy('fac.priority')
            ->orderBy('faq_articles.position')
            ->orderByDesc('faq_articles.published_at')
            ->orderBy('faq_articles.title')
            ->get();
    }

    private function loadCategoryRunbooks(string $crmPage, string $search = '', array $excludeIds = []): Collection
    {
        $query = Article::query()
            ->with('category')
            ->where('status', 'published')
            ->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('crm_page', $crmPage))
            ->when($excludeIds !== [], fn ($articleQuery) => $articleQuery->whereNotIn('id', $excludeIds));

        if ($search !== '') {
            $query = $this->searchService->apply($query, $search);
        }

        return $query
            ->orderBy('position')
            ->orderByDesc('published_at')
            ->orderBy('title')
            ->get();
    }

    private function serializeScriptArticle(Article $article): array
    {
        return [
            'id' => $article->id,
            'slug' => $article->slug,
            'title' => $article->title,
            'summary' => $article->summary,
            'status' => $article->status,
            'context_priority' => (int) ($article->context_priority ?? optional($article->contexts->first())->priority ?? 0),
            'category' => $article->relationLoaded('category') ? [
                'id' => $article->category?->id,
                'slug' => $article->category?->slug,
                'name' => $article->category?->name,
                'crm_page' => $article->category?->crm_page,
            ] : null,
            'snippets' => $this->snippetService->extractCustomerSnippets($article),
        ];
    }

    private function serializeRunbookArticle(Article $article): array
    {
        return [
            'id' => $article->id,
            'slug' => $article->slug,
            'title' => $article->title,
            'summary' => $article->summary,
            'status' => $article->status,
            'context_priority' => isset($article->context_priority) ? (int) $article->context_priority : null,
            'category' => $article->relationLoaded('category') ? [
                'id' => $article->category?->id,
                'slug' => $article->category?->slug,
                'name' => $article->category?->name,
                'crm_page' => $article->category?->crm_page,
            ] : null,
        ];
    }
}
