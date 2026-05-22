<?php

namespace App\Services\Seo;

use App\Models\SeoBioFeedback;
use Illuminate\Support\Facades\Cache;

/**
 * Turns recent SeoBioFeedback rows into a short, prompt-ready instruction
 * block. The system prompt grows by ≤ ~300 tokens but the LLM learns what
 * the editors keep marking as off.
 *
 * Lookback: 30 days, top 12 most-recent rows per platform.
 * Tag frequency drives ranking (most-complained-about issues first).
 */
class FeedbackInsightService
{
    private const CACHE_TTL = 600; // 10 minutes — short enough to feel responsive after a thumbs-down
    private const LOOKBACK_DAYS = 30;
    private const MAX_ROWS = 12;
    private const TOP_TAGS = 4;
    private const TOP_COMMENTS = 3;

    /** Tag → natural-language editor instruction. */
    private const TAG_INSTRUCTIONS = [
        'too_long'        => 'Editors keep flagging bios as too long. Stay near the lower end of the word range.',
        'too_short'       => 'Editors keep flagging bios as too short. Use the full word range.',
        'too_generic'     => 'Editors flag generic copy. Use specific, profile-driven details and avoid stock phrases.',
        'off_tone'        => 'Editors flag the tone. Lean more toward the configured tone descriptor.',
        'repetitive'      => 'Editors flag repetition. Vary sentence openings, verbs, and structure.',
        'missing_contact' => 'Editors expect a contact mention. Include the configured contact channel naturally.',
        'too_formal'      => 'Editors find the copy too formal. Use everyday phrasing, contractions, and warmth.',
        'too_casual'      => 'Editors find the copy too casual. Polish the phrasing without becoming corporate.',
        'inaccurate'      => 'Editors flag inaccuracies. Stick strictly to the supplied profile facts.',
    ];

    /**
     * Return a feedback-summary block to splice into the system prompt.
     *
     * Returns '' when there's nothing actionable, so callers can prepend
     * unconditionally.
     */
    public function instructionsForPlatform(?int $platformId): string
    {
        if (!$platformId) {
            return '';
        }

        return Cache::remember(
            "seo_feedback_prompt_{$platformId}",
            self::CACHE_TTL,
            fn() => $this->build($platformId)
        );
    }

    /**
     * Public so the settings UI can preview what's currently influencing prompts.
     */
    public function summaryForPlatform(?int $platformId): array
    {
        if (!$platformId) {
            return [
                'total_recent' => 0,
                'positive'     => 0,
                'negative'     => 0,
                'top_tags'     => [],
                'recent_comments' => [],
                'prompt_injection' => '',
            ];
        }

        $rows = SeoBioFeedback::query()
            ->where('platform_id', $platformId)
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['rating', 'tag', 'comment', 'accepted', 'created_at']);

        $tagCounts = [];
        $comments = [];
        foreach ($rows as $row) {
            if ($row->tag && isset(self::TAG_INSTRUCTIONS[$row->tag])) {
                $tagCounts[$row->tag] = ($tagCounts[$row->tag] ?? 0) + 1;
            }
            if ($row->comment && trim((string) $row->comment) !== '' && count($comments) < self::TOP_COMMENTS) {
                $comments[] = ['text' => trim($row->comment), 'when' => $row->created_at->diffForHumans()];
            }
        }
        arsort($tagCounts);

        return [
            'total_recent'    => $rows->count(),
            'positive'        => $rows->where('rating', 1)->count(),
            'negative'        => $rows->where('rating', -1)->count(),
            'accepted'        => $rows->where('accepted', true)->count(),
            'top_tags'        => array_slice($tagCounts, 0, self::TOP_TAGS, true),
            'recent_comments' => $comments,
            'prompt_injection' => $this->instructionsForPlatform($platformId),
        ];
    }

    public function forgetPlatformCache(?int $platformId): void
    {
        if ($platformId) {
            Cache::forget("seo_feedback_prompt_{$platformId}");
        }
    }

    // -----------------------------------------------------------------

    private function build(int $platformId): string
    {
        $rows = SeoBioFeedback::query()
            ->where('platform_id', $platformId)
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->where(function ($q) {
                $q->whereNotNull('tag')->orWhereNotNull('comment')->orWhere('rating', '<', 0);
            })
            ->orderByDesc('created_at')
            ->limit(self::MAX_ROWS)
            ->get(['rating', 'tag', 'comment']);

        if ($rows->isEmpty()) {
            return '';
        }

        // Rank tag instructions by frequency (and exclude positives)
        $tagCounts = [];
        $comments = [];
        foreach ($rows as $row) {
            if ($row->tag && $row->tag !== 'perfect' && isset(self::TAG_INSTRUCTIONS[$row->tag])) {
                $tagCounts[$row->tag] = ($tagCounts[$row->tag] ?? 0) + 1;
            }
            if ($row->comment && trim((string) $row->comment) !== '' && count($comments) < self::TOP_COMMENTS) {
                $comments[] = trim($row->comment);
            }
        }
        arsort($tagCounts);

        $lines = [];
        foreach (array_slice($tagCounts, 0, self::TOP_TAGS, true) as $tag => $count) {
            $lines[] = '- ' . self::TAG_INSTRUCTIONS[$tag];
        }
        foreach ($comments as $c) {
            // Sanitize: collapse whitespace, clamp length, strip newlines so it stays one bullet.
            $c = trim(preg_replace('/\s+/', ' ', $c));
            if ($c === '') {
                continue;
            }
            $lines[] = '- Editor said: "' . mb_substr($c, 0, 240) . '"';
        }

        if (empty($lines)) {
            return '';
        }

        return "\nEditor preferences learned from recent feedback (apply silently, don't reference them):\n"
            . implode("\n", $lines);
    }
}
