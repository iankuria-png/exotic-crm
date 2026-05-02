<?php

namespace App\Http\Controllers\CRM\Faq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faq\StoreFeedbackCommentRequest;
use App\Models\Faq\Feedback;
use App\Models\Faq\FeedbackComment;
use Illuminate\Http\Request;

class FeedbackCommentController extends Controller
{
    public function index(Request $request, Feedback $feedback)
    {
        $isAdmin = $this->isAdmin($request);

        $comments = $feedback->comments()
            ->with('user:id,name,email')
            ->when(!$isAdmin, fn ($query) => $query->where('is_internal', false))
            ->orderBy('created_at')
            ->get()
            ->map(fn (FeedbackComment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'is_internal' => $isAdmin ? (bool) $comment->is_internal : false,
                'created_at' => optional($comment->created_at)->toIso8601String(),
                'user' => $comment->user,
            ])
            ->values();

        return response()->json([
            'comments' => $comments,
        ]);
    }

    public function store(StoreFeedbackCommentRequest $request, Feedback $feedback)
    {
        $isAdmin = $this->isAdmin($request);

        $comment = $feedback->comments()->create([
            'user_id' => $request->user()->id,
            'body' => (string) $request->string('body'),
            'is_internal' => $isAdmin ? (bool) $request->boolean('is_internal') : false,
        ]);
        $comment->load('user:id,name,email');

        return response()->json([
            'message' => 'Feedback comment added.',
            'comment' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'is_internal' => $isAdmin ? (bool) $comment->is_internal : false,
                'created_at' => optional($comment->created_at)->toIso8601String(),
                'user' => $comment->user,
            ],
        ], 201);
    }

    public function destroy(Request $request, Feedback $feedback, FeedbackComment $comment)
    {
        abort_unless($comment->feedback_id === $feedback->id, 404);

        $isAdmin = $this->isAdmin($request);
        abort_unless($isAdmin || ((int) $comment->user_id === (int) $request->user()->id && !$comment->is_internal), 403);

        $comment->delete();

        return response()->json([
            'message' => 'Feedback comment deleted.',
        ]);
    }

    private function isAdmin(Request $request): bool
    {
        return in_array($request->user()->role, ['admin', 'sub_admin'], true);
    }
}
