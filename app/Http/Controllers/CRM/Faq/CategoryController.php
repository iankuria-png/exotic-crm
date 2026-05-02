<?php

namespace App\Http\Controllers\CRM\Faq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faq\StoreCategoryRequest;
use App\Http\Requests\Faq\UpdateCategoryRequest;
use App\Models\Faq\Category;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService
    ) {
    }

    public function index(Request $request)
    {
        $includeArticles = $request->boolean('include_articles');
        $isAdmin = in_array($request->user()->role, ['admin', 'sub_admin'], true);

        $query = Category::query()->orderBy('position')->orderBy('name');

        if ($includeArticles) {
            $query->with(['articles' => function ($articleQuery) use ($isAdmin) {
                if (!$isAdmin) {
                    $articleQuery->where('status', 'published');
                }

                $articleQuery->with(['ctas', 'media', 'category'])->orderBy('position')->orderBy('title');
            }]);
        }

        $categories = $query->get()->map(function (Category $category) use ($isAdmin) {
            $publishedCount = $category->articles()->where('status', 'published')->count();
            $draftCount = $isAdmin ? $category->articles()->where('status', 'draft')->count() : 0;

            return [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
                'description' => $category->description,
                'crm_page' => $category->crm_page,
                'position' => $category->position,
                'published_articles_count' => $publishedCount,
                'draft_articles_count' => $draftCount,
                'articles' => $category->relationLoaded('articles')
                    ? $category->articles->map(fn ($article) => [
                        'id' => $article->id,
                        'slug' => $article->slug,
                        'title' => $article->title,
                        'summary' => $article->summary,
                        'status' => $article->status,
                        'position' => $article->position,
                        'published_at' => optional($article->published_at)->toIso8601String(),
                    ])->values()
                    : [],
            ];
        })->values();

        return response()->json([
            'categories' => $categories,
        ]);
    }

    public function store(StoreCategoryRequest $request)
    {
        $category = Category::create($request->validated());

        $this->auditService->fromSystemRequest(
            $request,
            'faq_category_created',
            'faq_category',
            (int) $category->id,
            null,
            $category->toArray(),
            'Created FAQ category'
        );

        return response()->json([
            'message' => 'FAQ category created.',
            'category' => $category,
        ], 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $before = $category->toArray();
        $category->fill($request->validated());
        $category->save();

        $this->auditService->fromSystemRequest(
            $request,
            'faq_category_updated',
            'faq_category',
            (int) $category->id,
            $before,
            $category->fresh()->toArray(),
            'Updated FAQ category'
        );

        return response()->json([
            'message' => 'FAQ category updated.',
            'category' => $category->fresh(),
        ]);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:faq_categories,id'],
        ]);

        foreach (array_values($validated['ids']) as $index => $id) {
            Category::query()->whereKey($id)->update(['position' => $index + 1]);
        }

        return response()->json([
            'message' => 'FAQ categories reordered.',
        ]);
    }

    public function destroy(Request $request, Category $category)
    {
        $before = $category->toArray();
        $categoryId = (int) $category->id;
        $category->delete();

        $this->auditService->fromSystemRequest(
            $request,
            'faq_category_deleted',
            'faq_category',
            $categoryId,
            $before,
            null,
            'Deleted FAQ category'
        );

        return response()->json([
            'message' => 'FAQ category deleted.',
        ]);
    }
}
