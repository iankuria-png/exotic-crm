<?php

namespace App\Services\Scraper;

class TemplateLibrary
{
    public const RULE_KEYS = [
        'row_selector', 'name_selector', 'phone_selector', 'whatsapp_selector', 'email_selector',
        'link_selector', 'city_selector', 'age_selector', 'image_selector', 'city_from_url',
    ];

    public function all(): array
    {
        $out = [];
        foreach ((array) config('scraper_templates', []) as $key => $template) {
            $out[$key] = ['key' => (string) $key] + $template;
        }

        return $out;
    }

    public function get(?string $key): ?array
    {
        return $key ? ($this->all()[$key] ?? null) : null;
    }

    public function summaries(): array
    {
        return array_values(array_map(fn (array $t) => [
            'key' => $t['key'],
            'label' => $t['label'] ?? $t['key'],
            'family' => $t['family'] ?? null,
            'rules' => $t['rules'] ?? [],
            'pagination' => $t['pagination'] ?? [],
        ], $this->all()));
    }

    /**
     * Best template for the page with a 0–100 confidence and what matched.
     *
     * @param array<int, array{key: string}> $tech
     * @return array{key: string, label: string, confidence: int, matched_by: array<int, string>, rows: int}|null
     */
    public function match(HtmlDocument $doc, array $tech): ?array
    {
        $techKeys = array_column($tech, 'key');
        $best = null;

        foreach ($this->all() as $key => $template) {
            $matchedBy = [];
            $score = 0;
            foreach ((array) ($template['match']['tech'] ?? []) as $techKey) {
                if (in_array($techKey, $techKeys, true)) {
                    $matchedBy[] = 'technology: ' . $techKey;
                    $score += 40;
                }
            }
            $rows = 0;
            foreach ((array) ($template['match']['selectors'] ?? []) as $selector) {
                $count = $doc->count($selector);
                if ($count > 0) {
                    $matchedBy[] = sprintf('%s × %d', $selector, $count);
                    $score += $count >= 3 ? 45 : 20;
                    $rows = max($rows, $count);
                }
            }
            if ($rows > 0) {
                $phones = 0;
                foreach (array_slice($doc->select((string) ($template['rules']['row_selector'] ?? '')), 0, 40) as $row) {
                    if ($doc->first((string) ($template['rules']['phone_selector'] ?? 'a[href^="tel:"]'), $row) || $doc->first((string) ($template['rules']['whatsapp_selector'] ?? 'a[href*="wa.me"]'), $row)) {
                        $phones++;
                    }
                }
                if ($phones > 0) {
                    $score += 15;
                    $matchedBy[] = 'phone links inside cards';
                }
            }
            if ($rows === 0) {
                continue;
            }
            $confidence = min(99, $score);
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['key' => $key, 'label' => (string) ($template['label'] ?? $key), 'confidence' => $confidence, 'matched_by' => $matchedBy, 'rows' => $rows];
            }
        }

        return $best;
    }

    /**
     * Template rules overlaid by the site's own non-empty overrides.
     */
    public function effectiveRules(?string $templateKey, array $overrides): array
    {
        $template = $this->get($templateKey);
        $rules = (array) ($template['rules'] ?? []);
        foreach (self::RULE_KEYS as $ruleKey) {
            $value = trim((string) ($overrides[$ruleKey] ?? ''));
            if ($value !== '') {
                $rules[$ruleKey] = $value;
            }
        }

        return $rules;
    }

    public function pagination(?string $templateKey, array $crawl): array
    {
        $base = (array) ($this->get($templateKey)['pagination'] ?? []);
        foreach (['next_selector', 'pattern'] as $key) {
            $value = trim((string) ($crawl['pagination'][$key] ?? ''));
            if ($value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public function profileRules(?string $templateKey, array $crawl): array
    {
        $rules = (array) ($this->get($templateKey)['profile'] ?? []);
        foreach ((array) ($crawl['profile_rules'] ?? []) as $key => $value) {
            if (trim((string) $value) !== '') {
                $rules[$key] = trim((string) $value);
            }
        }

        return $rules;
    }
}
