<?php

namespace App\Http\Controllers\CRM\Faq;

use App\Http\Controllers\Controller;
use App\Models\Faq\Feedback;
use App\Models\Faq\FeedbackVote;
use Illuminate\Http\Request;

class FeedbackVoteController extends Controller
{
    public function toggle(Request $request, Feedback $feedback)
    {
        $existing = FeedbackVote::query()
            ->where('feedback_id', $feedback->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $voted = false;
        } else {
            FeedbackVote::create([
                'feedback_id' => $feedback->id,
                'user_id' => $request->user()->id,
            ]);
            $voted = true;
        }

        return response()->json([
            'message' => $voted ? 'Vote added.' : 'Vote removed.',
            'voted' => $voted,
            'votes_count' => $feedback->votes()->count(),
        ]);
    }
}
