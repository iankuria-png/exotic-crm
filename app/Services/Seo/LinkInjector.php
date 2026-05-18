<?php

namespace App\Services\Seo;

use DOMDocument;
use DOMText;
use DOMNode;

/**
 * DOM-aware internal link injector.
 *
 * Rules:
 * - Never wraps text inside an existing <a>, <code>, or <pre>.
 * - Caps total injected links at 6.
 * - Allows at most 2 links per catalog category.
 * - Never repeats the same URL.
 * - Prefers longer keywords before shorter ones (prevents partial over-wrapping).
 */
class LinkInjector
{
    private const MAX_TOTAL_LINKS     = 6;
    private const MAX_LINKS_PER_CAT   = 2;

    /**
     * @param  string  $html     Bio HTML input.
     * @param  array   $catalog  Array of {keyword, url, category, priority}.
     * @return string  Modified HTML with links injected.
     */
    public function inject(string $html, array $catalog): string
    {
        if (empty($catalog) || trim($html) === '') {
            return $html;
        }

        // Sort: priority DESC, then keyword length DESC (longer first to avoid partial wraps).
        usort($catalog, static function (array $a, array $b): int {
            $priCmp = (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
            if ($priCmp !== 0) {
                return $priCmp;
            }

            return strlen((string) ($b['keyword'] ?? '')) <=> strlen((string) ($a['keyword'] ?? ''));
        });

        $dom = $this->parseHtml($html);
        if ($dom === null) {
            return $html;
        }

        $usedUrls       = [];
        $usedCategories = [];
        $totalLinks     = 0;

        foreach ($catalog as $entry) {
            if ($totalLinks >= self::MAX_TOTAL_LINKS) {
                break;
            }

            $keyword  = (string) ($entry['keyword'] ?? '');
            $url      = (string) ($entry['url'] ?? '');
            $category = (string) ($entry['category'] ?? 'default');

            if ($keyword === '' || $url === '') {
                continue;
            }

            if (isset($usedUrls[$url])) {
                continue;
            }

            if (($usedCategories[$category] ?? 0) >= self::MAX_LINKS_PER_CAT) {
                continue;
            }

            $injected = $this->injectOne($dom, $keyword, $url);
            if ($injected) {
                $usedUrls[$url]       = true;
                $usedCategories[$category] = ($usedCategories[$category] ?? 0) + 1;
                $totalLinks++;
            }
        }

        return $this->extractBody($dom);
    }

    // -------------------------------------------------------------------------

    private function injectOne(DOMDocument $dom, string $keyword, string $url): bool
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return false;
        }

        $textNodes = $this->collectTextNodes($body);

        foreach ($textNodes as $textNode) {
            $text = $textNode->nodeValue;
            if ($text === null || $text === '') {
                continue;
            }

            $pos = stripos($text, $keyword);
            if ($pos === false) {
                continue;
            }

            // Whole-word boundary check
            if (!$this->isWholeWord($text, $pos, strlen($keyword))) {
                continue;
            }

            $this->splitAndWrap($dom, $textNode, $text, $pos, strlen($keyword), $url);
            return true;
        }

        return false;
    }

    /**
     * Collect text nodes that are NOT inside <a>, <code>, or <pre>.
     *
     * @return DOMText[]
     */
    private function collectTextNodes(DOMNode $node): array
    {
        $results = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $results[] = $child;
                continue;
            }

            if (!($child instanceof \DOMElement)) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, ['a', 'code', 'pre'], true)) {
                continue;
            }

            $results = array_merge($results, $this->collectTextNodes($child));
        }

        return $results;
    }

    private function isWholeWord(string $text, int $pos, int $len): bool
    {
        $before = $pos > 0 ? $text[$pos - 1] : ' ';
        $after  = ($pos + $len) < strlen($text) ? $text[$pos + $len] : ' ';

        return !ctype_alpha($before) && !ctype_alpha($after);
    }

    private function splitAndWrap(
        DOMDocument $dom,
        DOMText     $textNode,
        string      $text,
        int         $pos,
        int         $len,
        string      $url,
    ): void {
        $parent = $textNode->parentNode;
        if (!$parent) {
            return;
        }

        $before  = substr($text, 0, $pos);
        $match   = substr($text, $pos, $len);
        $after   = substr($text, $pos + $len);

        $fragment = $dom->createDocumentFragment();

        if ($before !== '') {
            $fragment->appendChild($dom->createTextNode($before));
        }

        $anchor = $dom->createElement('a');
        $anchor->setAttribute('href', $url);
        $anchor->appendChild($dom->createTextNode($match));
        $fragment->appendChild($anchor);

        if ($after !== '') {
            $fragment->appendChild($dom->createTextNode($after));
        }

        $parent->replaceChild($fragment, $textNode);
    }

    private function parseHtml(string $html): ?DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        $wrapped = '<!DOCTYPE html><html><body>' . $html . '</body></html>';

        $prevErrors = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        return $ok ? $dom : null;
    }

    private function extractBody(DOMDocument $dom): string
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }

        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }
}
