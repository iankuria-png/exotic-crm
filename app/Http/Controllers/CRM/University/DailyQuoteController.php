<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Services\University\DailyQuoteService;
use Illuminate\Http\Request;

class DailyQuoteController extends Controller
{
    public function __construct(private readonly DailyQuoteService $quotes)
    {
    }

    public function show(Request $request)
    {
        $today = now();
        $tomorrow = now()->addDay();

        return response()->json([
            'quote' => $this->quotes->quoteFor($today),
            'tomorrow_quote' => $this->quotes->quoteFor($tomorrow),
            'can_submit' => in_array($request->user()->role, ['admin', 'sub_admin'], true),
        ]);
    }

    public function refresh(Request $request)
    {
        $validated = $request->validate([
            'exclude_quote' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'quote' => $this->quotes->suggestion($validated['exclude_quote'] ?? null),
        ]);
    }

    public function submitNextDay(Request $request)
    {
        $validated = $request->validate([
            'quote' => ['required', 'string', 'min:3', 'max:1000'],
            'author' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json([
            'message' => 'Tomorrow quote saved.',
            'quote' => $this->quotes->submitForTomorrow($validated, (int) $request->user()->id),
        ]);
    }
}
