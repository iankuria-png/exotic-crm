<?php

namespace App\Http\Controllers\CRM\Faq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faq\StoreFeedbackRequest;
use App\Http\Requests\Faq\UpdateFeedbackRequest;
use App\Models\Faq\Article;
use App\Models\Faq\Feedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FeedbackController extends Controller
{
    public function index(Request $request)
    {
        $isAdmin = $this->isAdmin($request);
        $query = Feedback::query()
            ->with(['user:id,name,email', 'article:id,slug,title', 'duplicateOf:id,title,status', 'resolver:id,name'])
            ->withCount(['votes', 'comments'])
            ->orderByDesc('status_changed_at')
            ->orderByDesc('updated_at');

        if ($request->filled('kind')) {
            $query->where('kind', (string) $request->string('kind'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', (string) $request->string('severity'));
        }

        if ($request->filled('article_id')) {
            $query->where('article_id', (int) $request->integer('article_id'));
        }

        $tab = (string) $request->string('tab');
        if ($tab === 'mine' || $request->boolean('mine')) {
            $query->where('user_id', $request->user()->id);
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $items = $query->paginate($perPage);

        $submitterUpdateCount = Feedback::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('status_changed_at')
            ->where(function ($builder) {
                $builder->whereNull('last_seen_at')->orWhereColumn('status_changed_at', '>', 'last_seen_at');
            })
            ->count();

        return response()->json([
            'feedback' => $items->getCollection()->map(fn (Feedback $feedback) => $this->serializeFeedback($feedback, $isAdmin))->values(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'meta' => [
                'admin_new_count' => $isAdmin ? Feedback::query()->where('status', 'new')->count() : 0,
                'submitter_update_count' => $submitterUpdateCount,
            ],
        ]);
    }

    public function show(Request $request, Feedback $feedback)
    {
        $isAdmin = $this->isAdmin($request);
        $feedback->load([
            'user:id,name,email',
            'article:id,slug,title',
            'duplicateOf:id,title,status',
            'resolver:id,name',
            'votes',
            'comments.user:id,name,email',
        ]);

        if ((int) $feedback->user_id === (int) $request->user()->id) {
            $feedback->forceFill(['last_seen_at' => now()])->save();
            $feedback->refresh();
            $feedback->load([
                'user:id,name,email',
                'article:id,slug,title',
                'duplicateOf:id,title,status',
                'resolver:id,name',
                'votes',
                'comments.user:id,name,email',
            ]);
        }

        return response()->json([
            'feedback' => $this->serializeFeedback($feedback, $isAdmin, includeComments: true),
        ]);
    }

    public function store(StoreFeedbackRequest $request)
    {
        $validated = $request->validated();

        $feedback = new Feedback([
            'article_id' => $validated['article_id'] ?? null,
            'user_id' => $request->user()->id,
            'kind' => $validated['kind'],
            'helpful' => $validated['helpful'] ?? null,
            'title' => $validated['title'] ?? $this->defaultTitleForKind($validated['kind']),
            'comment' => $validated['comment'] ?? null,
            'severity' => $validated['severity'] ?? null,
            'context_path' => $validated['context_path'] ?? null,
            'context_meta' => $validated['context_meta'] ?? null,
            'status' => $validated['status'] ?? 'new',
            'status_changed_at' => now(),
            'status_history' => [[
                'status' => $validated['status'] ?? 'new',
                'changed_at' => now()->toIso8601String(),
                'actor_id' => $request->user()->id,
                'note' => 'Feedback submitted',
            ]],
        ]);
        $feedback->save();

        if ($request->hasFile('screenshot')) {
            $file = $request->file('screenshot');
            $path = sprintf('faq-feedback/%d/%s.%s', $feedback->id, Str::uuid()->toString(), strtolower((string) $file->getClientOriginalExtension()));
            Storage::disk('public')->putFileAs(sprintf('faq-feedback/%d', $feedback->id), $file, basename($path));
            $feedback->forceFill(['screenshot_disk_path' => $path])->save();
        }

        $this->updateArticleHelpfulnessCounters($feedback);

        $feedback->load(['user:id,name,email', 'article:id,slug,title']);

        return response()->json([
            'message' => 'Feedback submitted.',
            'feedback' => $this->serializeFeedback($feedback, $this->isAdmin($request)),
        ], 201);
    }

    public function update(UpdateFeedbackRequest $request, Feedback $feedback)
    {
        $validated = $request->validated();
        $before = $feedback->toArray();

        if (array_key_exists('status', $validated) && $validated['status'] !== $feedback->status) {
            $history = is_array($feedback->status_history) ? $feedback->status_history : [];
            $history[] = [
                'status' => $validated['status'],
                'changed_at' => now()->toIso8601String(),
                'actor_id' => $request->user()->id,
                'note' => $validated['admin_notes'] ?? null,
            ];

            $feedback->status_history = $history;
            $feedback->status_changed_at = now();
            if (in_array($validated['status'], ['resolved', 'shipped'], true)) {
                $feedback->resolved_at = now();
                $feedback->resolved_by = $request->user()->id;
            }
        }

        $feedback->fill($validated);
        $feedback->save();
        $feedback->load(['user:id,name,email', 'article:id,slug,title', 'duplicateOf:id,title,status', 'resolver:id,name', 'comments.user:id,name,email', 'votes']);

        return response()->json([
            'message' => 'Feedback updated.',
            'before' => $before,
            'feedback' => $this->serializeFeedback($feedback, true, includeComments: true),
        ]);
    }

    public function destroy(Feedback $feedback)
    {
        if ($feedback->screenshot_disk_path) {
            Storage::disk('public')->delete($feedback->screenshot_disk_path);
        }

        $feedback->delete();

        return response()->json([
            'message' => 'Feedback deleted.',
        ]);
    }

    private function updateArticleHelpfulnessCounters(Feedback $feedback): void
    {
        if (!$feedback->article_id) {
            return;
        }

        /** @var Article|null $article */
        $article = Article::query()->find($feedback->article_id);
        if (!$article) {
            return;
        }

        if ($feedback->kind === 'helpful' || $feedback->helpful === true) {
            $article->increment('helpful_count');
        } elseif ($feedback->kind === 'unhelpful' || $feedback->helpful === false) {
            $article->increment('unhelpful_count');
        }
    }

    private function serializeFeedback(Feedback $feedback, bool $isAdmin, bool $includeComments = false): array
    {
        $comments = [];
        if ($includeComments && $feedback->relationLoaded('comments')) {
            $comments = $feedback->comments
                ->filter(fn ($comment) => $isAdmin || !$comment->is_internal)
                ->map(fn ($comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'is_internal' => $isAdmin ? (bool) $comment->is_internal : false,
                    'created_at' => optional($comment->created_at)->toIso8601String(),
                    'user' => $comment->relationLoaded('user') ? $comment->user : null,
                ])
                ->values()
                ->all();
        }

        return [
            'id' => $feedback->id,
            'article_id' => $feedback->article_id,
            'user_id' => $feedback->user_id,
            'kind' => $feedback->kind,
            'helpful' => $feedback->helpful,
            'title' => $feedback->title,
            'comment' => $feedback->comment,
            'severity' => $feedback->severity,
            'context_path' => $feedback->context_path,
            'context_meta' => $feedback->context_meta,
            'status' => $feedback->status,
            'duplicate_of_id' => $feedback->duplicate_of_id,
            'admin_notes' => $isAdmin ? $feedback->admin_notes : null,
            'resolved_at' => optional($feedback->resolved_at)->toIso8601String(),
            'resolved_by' => $feedback->resolved_by,
            'status_changed_at' => optional($feedback->status_changed_at)->toIso8601String(),
            'last_seen_at' => optional($feedback->last_seen_at)->toIso8601String(),
            'status_history' => $feedback->status_history ?? [],
            'created_at' => optional($feedback->created_at)->toIso8601String(),
            'updated_at' => optional($feedback->updated_at)->toIso8601String(),
            'screenshot_url' => $feedback->screenshot_url,
            'user' => $feedback->relationLoaded('user') ? $feedback->user : null,
            'article' => $feedback->relationLoaded('article') && $feedback->article ? [
                'id' => $feedback->article->id,
                'slug' => $feedback->article->slug,
                'title' => $feedback->article->title,
            ] : null,
            'duplicate_of' => $feedback->relationLoaded('duplicateOf') && $feedback->duplicateOf ? [
                'id' => $feedback->duplicateOf->id,
                'title' => $feedback->duplicateOf->title,
                'status' => $feedback->duplicateOf->status,
            ] : null,
            'resolver' => $feedback->relationLoaded('resolver') ? $feedback->resolver : null,
            'votes_count' => isset($feedback->votes_count) ? (int) $feedback->votes_count : $feedback->votes()->count(),
            'comments_count' => isset($feedback->comments_count) ? (int) $feedback->comments_count : $feedback->comments()->count(),
            'has_unread_update' => $feedback->status_changed_at && (!$feedback->last_seen_at || $feedback->status_changed_at->gt($feedback->last_seen_at)),
            'comments' => $comments,
        ];
    }

    private function defaultTitleForKind(string $kind): string
    {
        return match ($kind) {
            'bug' => 'Bug report',
            'feature_request' => 'Feature request',
            'article_suggestion' => 'Article suggestion',
            'helpful' => 'Helpful article feedback',
            'unhelpful' => 'Unhelpful article feedback',
            default => 'General feedback',
        };
    }

    private function isAdmin(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'sub_admin'], true);
    }
}
