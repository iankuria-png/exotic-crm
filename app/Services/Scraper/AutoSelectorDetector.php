<?php

namespace App\Services\Scraper;

use App\Services\Scraper\Fetch\UrlTools;
use DOMElement;

/**
 * Learns list rules on pages no template covers: finds the repeated container that
 * holds one contact link (or one profile link + image) each, then the name and link
 * inside it. Returns rules in the same shape as templates plus a confidence.
 */
class AutoSelectorDetector
{
    public function detect(HtmlDocument $doc, string $pageUrl): ?array
    {
        $anchors = $doc->select('a[href^="tel:"], a[href*="wa.me"], a[href*="whatsapp.com/send"]');
        $basis = 'phone links';
        if (count($anchors) < 3) {
            $anchors = array_values(array_filter($doc->select('a[href]'), function (DOMElement $a) use ($doc, $pageUrl) {
                $url = UrlTools::resolve($pageUrl, (string) $a->getAttribute('href'));

                return $url && UrlTools::sameSite($url, $pageUrl) && $doc->first('img', $a) !== null;
            }));
            $basis = 'image links';
        }
        if (count($anchors) < 3) {
            return null;
        }

        // Count how many anchors share each ancestor signature at each depth.
        $signatures = [];
        foreach ($anchors as $anchor) {
            $node = $anchor->parentNode;
            $seen = [];
            for ($depth = 1; $depth <= 8 && $node instanceof DOMElement; $depth++, $node = $node->parentNode) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, ['body', 'html', 'main'], true)) {
                    break;
                }
                $signature = HtmlDocument::describe($node);
                if ($signature === $tag && !in_array($tag, ['li', 'article'], true)) {
                    continue; // bare div/span is too vague to be a card selector
                }
                if (isset($seen[$signature])) {
                    continue;
                }
                $seen[$signature] = true;
                $signatures[$signature] ??= ['depth' => 0, 'anchors' => 0];
                $signatures[$signature]['anchors']++;
                $signatures[$signature]['depth'] += $depth;
            }
        }

        $best = null;
        foreach ($signatures as $selector => $info) {
            $containers = $doc->count($selector);
            if ($containers < 3 || $info['anchors'] < 3) {
                continue;
            }
            // Ideal card: about one anchor per container, appearing many times.
            $ratio = $info['anchors'] / max(1, $containers);
            if ($ratio < 0.5 || $ratio > 2.5) {
                continue;
            }
            $score = min($containers, 60) * (1 - abs(1 - $ratio)) - ($info['depth'] / $info['anchors']) * 0.5;
            if ($best === null || $score > $best['score']) {
                $best = ['selector' => $selector, 'score' => $score, 'containers' => $containers, 'ratio' => $ratio];
            }
        }
        if ($best === null) {
            return null;
        }

        $cards = array_slice($doc->select($best['selector']), 0, 25);
        $rules = [
            'row_selector' => $best['selector'],
            'phone_selector' => 'a[href^="tel:"]',
            'whatsapp_selector' => 'a[href*="wa.me"], a[href*="whatsapp.com"]',
            'email_selector' => 'a[href^="mailto:"]',
            'name_selector' => $this->commonChild($doc, $cards, ['[itemprop="name"]', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', '.name', '.title', 'strong']),
            'link_selector' => $this->commonLink($doc, $cards, $pageUrl),
            'image_selector' => 'img',
        ];

        return [
            'rules' => array_filter($rules, fn ($v) => $v !== null && $v !== ''),
            'confidence' => (int) max(30, min(85, 40 + $best['containers'])),
            'matched_by' => [sprintf('%d repeated "%s" cards around %s', $best['containers'], $best['selector'], $basis)],
            'rows' => $best['containers'],
        ];
    }

    private function commonChild(HtmlDocument $doc, array $cards, array $candidates): ?string
    {
        foreach ($candidates as $selector) {
            $hits = 0;
            foreach ($cards as $card) {
                $node = $doc->first($selector, $card);
                if ($node && mb_strlen(HtmlDocument::textOf($node)) >= 2 && mb_strlen(HtmlDocument::textOf($node)) <= 80) {
                    $hits++;
                }
            }
            if ($cards !== [] && $hits / count($cards) >= 0.6) {
                return $selector;
            }
        }

        return null;
    }

    private function commonLink(HtmlDocument $doc, array $cards, string $pageUrl): ?string
    {
        $counts = [];
        foreach ($cards as $card) {
            foreach ($doc->select('a[href]', $card) as $anchor) {
                $url = UrlTools::resolve($pageUrl, (string) $anchor->getAttribute('href'));
                if (!$url || !UrlTools::sameSite($url, $pageUrl)) {
                    continue;
                }
                $sig = HtmlDocument::describe($anchor);
                $parent = $anchor->parentNode instanceof DOMElement ? HtmlDocument::describe($anchor->parentNode) : '';
                $selector = $parent !== '' && $parent !== 'div' ? $parent . ' > ' . $sig : $sig;
                $counts[$selector] = ($counts[$selector] ?? 0) + 1;
                break;
            }
        }
        arsort($counts);

        return array_key_first($counts) ?? 'a[href]';
    }
}
