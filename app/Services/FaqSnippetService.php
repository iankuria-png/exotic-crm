<?php

namespace App\Services;

use App\Models\Faq\Article;
use Illuminate\Support\Str;

class FaqSnippetService
{
    private const ALLOWED_HEADINGS = [
        'quick reply' => 'Quick reply',
        'warmer version' => 'Warmer version',
        'call version' => 'Call version',
        'escalation' => 'Escalation',
    ];

    public function extractCustomerSnippets(Article $article): array
    {
        $sections = $this->parseSections((string) ($article->body ?: ''));

        $snippets = [];
        foreach ($sections as $section) {
            $normalizedHeading = Str::of($section['heading'])->lower()->squish()->value();
            $label = self::ALLOWED_HEADINGS[$normalizedHeading] ?? null;
            $content = trim((string) ($section['content'] ?? ''));

            if (!$label || $content === '') {
                continue;
            }

            $snippets[] = [
                'label' => $label,
                'copy_text' => preg_replace("/\n{3,}/", "\n\n", $content) ?? $content,
                'section_slug' => Str::slug($label),
                'article_slug' => $article->slug,
            ];
        }

        return $snippets;
    }

    private function parseSections(string $markdown): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
        $sections = [];
        $currentHeading = null;
        $currentLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*#{2,6}\s+(.+?)\s*$/', $line, $matches)) {
                if ($currentHeading !== null) {
                    $sections[] = [
                        'heading' => $currentHeading,
                        'content' => trim(implode("\n", $currentLines)),
                    ];
                }

                $currentHeading = trim((string) $matches[1]);
                $currentLines = [];
                continue;
            }

            if ($currentHeading !== null) {
                $currentLines[] = $line;
            }
        }

        if ($currentHeading !== null) {
            $sections[] = [
                'heading' => $currentHeading,
                'content' => trim(implode("\n", $currentLines)),
            ];
        }

        return $sections;
    }
}
