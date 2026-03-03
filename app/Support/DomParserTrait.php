<?php

namespace App\Support;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Symfony\Component\CssSelector\CssSelectorConverter;

trait DomParserTrait
{
    private ?CssSelectorConverter $domCssSelectorConverter = null;

    protected function fetchHtml(string $url): array
    {
        if (method_exists($this, 'evaluateRobotsAccess')) {
            $robots = $this->evaluateRobotsAccess($url);
            if (is_array($robots) && array_key_exists('allowed', $robots) && !$robots['allowed']) {
                throw new \RuntimeException((string) ($robots['message'] ?? 'URL blocked by robots policy.'));
            }
        }

        $userAgent = defined('static::SCRAPER_USER_AGENT')
            ? (string) constant('static::SCRAPER_USER_AGENT')
            : 'Mozilla/5.0 (compatible; ExoticCRM/1.0; +https://example.com/bot)';

        $response = Http::withHeaders([
            'User-Agent' => $userAgent,
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
        ])
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(2, 300)
            ->get($url);

        if (!$response->successful()) {
            throw new \RuntimeException(sprintf('Source fetch failed with HTTP %s.', $response->status()));
        }

        $contentType = strtolower((string) $response->header('content-type', ''));
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            throw new \RuntimeException(sprintf('Source fetch returned unsupported content type: %s.', $contentType));
        }

        return [
            'status' => $response->status(),
            'content_type' => $contentType,
            'html' => (string) $response->body(),
        ];
    }

    protected function parseDom(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        return [$dom, new DOMXPath($dom)];
    }

    protected function queryCss(DOMXPath $xpath, string $selector, ?DOMNode $context = null): array
    {
        $selector = trim($selector);
        if ($selector === '') {
            return [];
        }

        try {
            $expr = $this->domCssSelectorConverter()->toXPath($selector);
            $nodeList = $xpath->query($expr, $context);
            if (!$nodeList) {
                return [];
            }

            $nodes = [];
            foreach ($nodeList as $node) {
                if ($node instanceof DOMNode) {
                    $nodes[] = $node;
                }
            }

            return $nodes;
        } catch (\Throwable) {
            return [];
        }
    }

    protected function nodeText(?DOMNode $node): string
    {
        if (!$node) {
            return '';
        }

        $text = trim((string) $node->textContent);
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return trim($text);
    }

    protected function extractPhoneFromText(string $text): ?string
    {
        if (preg_match('/(?:\+?\d[\d\-\s()]{7,}\d)/', $text, $match)) {
            return $match[0];
        }

        return null;
    }

    protected function normalizePhone(?string $phone, string $prefix = '254'): ?string
    {
        if (!$phone) {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', $phone);
        $normalized = ltrim((string) $normalized, '+');
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, '0')) {
            $normalized = $prefix . substr($normalized, 1);
        }

        return preg_match('/^\d{10,15}$/', $normalized) ? $normalized : null;
    }

    protected function domCssSelectorConverter(): CssSelectorConverter
    {
        if ($this->domCssSelectorConverter === null) {
            $this->domCssSelectorConverter = new CssSelectorConverter();
        }

        return $this->domCssSelectorConverter;
    }
}
