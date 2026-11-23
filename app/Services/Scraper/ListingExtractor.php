<?php

namespace App\Services\Scraper;

use App\Services\Scraper\Fetch\UrlTools;
use DOMElement;

/**
 * Turns a listing page into candidate rows using effective rules. Every field falls back
 * to a sensible heuristic inside the card, so partial rules still produce data.
 */
class ListingExtractor
{
    /**
     * @return array{rows: array<int, array>, stats: array{rows: int, phones: int, whatsapp: int, links: int, names: int}, errors: array<int, string>}
     */
    public function extract(HtmlDocument $doc, array $rules, string $pageUrl, string $phonePrefix): array
    {
        $errors = [];
        $rowSelector = trim((string) ($rules['row_selector'] ?? ''));
        $cards = $rowSelector !== '' ? $doc->select($rowSelector) : [];
        if ($rowSelector !== '' && $doc->lastSelectorError()) {
            $errors[] = $doc->lastSelectorError();
        }

        $rows = [];
        foreach ($cards as $card) {
            $rows[] = $this->extractCard($doc, $card, $rules, $pageUrl, $phonePrefix);
        }

        if ($rows === []) {
            // No card container: fall back to contact anchors on the page.
            foreach ($doc->select('a[href^="tel:"], a[href*="wa.me"], a[href*="whatsapp.com/send"]') as $anchor) {
                $card = $anchor->parentNode instanceof DOMElement ? $anchor->parentNode : $anchor;
                $rows[] = $this->extractCard($doc, $card, $rules, $pageUrl, $phonePrefix);
            }
        }

        $rows = array_values(array_filter($rows, fn (array $r) => $r['name'] || $r['phone'] || $r['profile_url']));

        return [
            'rows' => $rows,
            'stats' => [
                'rows' => count($rows),
                'phones' => count(array_filter($rows, fn ($r) => $r['phone'] !== null)),
                'whatsapp' => count(array_filter($rows, fn ($r) => $r['whatsapp'] !== null)),
                'links' => count(array_filter($rows, fn ($r) => $r['profile_url'] !== null)),
                'names' => count(array_filter($rows, fn ($r) => $r['name'] !== null)),
            ],
            'errors' => $errors,
        ];
    }

    public function extractCard(HtmlDocument $doc, DOMElement $card, array $rules, string $pageUrl, string $phonePrefix): array
    {
        $text = HtmlDocument::textOf($card);

        $phoneRaw = null;
        $phoneNode = $this->pick($doc, $card, $rules['phone_selector'] ?? 'a[href^="tel:"]');
        if ($phoneNode) {
            $phoneRaw = ContactParser::phoneFromHref((string) $phoneNode->getAttribute('href')) ?? ContactParser::phoneFromText(HtmlDocument::textOf($phoneNode)) ?? (string) $phoneNode->getAttribute('content');
        }
        $whatsappRaw = null;
        $waNode = $this->pick($doc, $card, $rules['whatsapp_selector'] ?? 'a[href*="wa.me"], a[href*="whatsapp.com"]');
        if ($waNode) {
            $whatsappRaw = ContactParser::phoneFromHref((string) $waNode->getAttribute('href'));
        }
        if (!$phoneRaw && !$whatsappRaw) {
            $phoneRaw = ContactParser::phoneFromText($text);
        }

        $emailNode = $this->pick($doc, $card, $rules['email_selector'] ?? 'a[href^="mailto:"]');
        $email = ContactParser::normalizeEmail($emailNode ? ($emailNode->getAttribute('href') ?: HtmlDocument::textOf($emailNode)) : ContactParser::emailFromText($text));

        $link = null;
        $linkNode = $this->pick($doc, $card, $rules['link_selector'] ?? '');
        if (!$linkNode) {
            foreach ($doc->select('a[href]', $card) as $anchor) {
                $href = (string) $anchor->getAttribute('href');
                if (UrlTools::resolve($pageUrl, $href)) {
                    $linkNode = $anchor;
                    break;
                }
            }
            if (!$linkNode && strtolower($card->tagName) === 'a') {
                $linkNode = $card;
            }
        }
        if ($linkNode) {
            $resolved = UrlTools::resolve($pageUrl, (string) $linkNode->getAttribute('href'));
            $link = $resolved && UrlTools::sameSite($resolved, $pageUrl) ? $resolved : null;
        }

        $nameNode = $this->pick($doc, $card, $rules['name_selector'] ?? '');
        $name = $nameNode ? (HtmlDocument::textOf($nameNode) ?: $nameNode->getAttribute('title')) : null;
        if (!$name) {
            $heading = $doc->first('h1, h2, h3, h4, h5, h6, [itemprop="name"]', $card);
            $name = $heading ? HtmlDocument::textOf($heading) : null;
        }
        if (!$name && $linkNode) {
            $name = $linkNode->getAttribute('title') ?: HtmlDocument::textOf($linkNode);
        }
        if (!$name) {
            $img = $doc->first('img[alt]', $card);
            $name = $img ? $img->getAttribute('alt') : null;
        }

        $cityNode = $this->pick($doc, $card, $rules['city_selector'] ?? '');
        $city = $cityNode ? HtmlDocument::textOf($cityNode) : null;
        if (!$city && !empty($rules['city_from_url']) && $link && @preg_match((string) $rules['city_from_url'], $link, $m)) {
            $city = ucwords(str_replace('-', ' ', (string) ($m[1] ?? '')));
        }

        $ageNode = $this->pick($doc, $card, $rules['age_selector'] ?? '');
        $age = ContactParser::ageFromText($ageNode ? HtmlDocument::textOf($ageNode) : null);

        $image = null;
        $imgNode = $this->pick($doc, $card, $rules['image_selector'] ?? 'img');
        if ($imgNode) {
            foreach (['data-original-url', 'data-lazy-src', 'data-src', 'src'] as $attr) {
                $value = trim((string) $imgNode->getAttribute($attr));
                if ($value !== '' && !str_starts_with($value, 'data:')) {
                    $image = UrlTools::resolve($pageUrl, $value);
                    break;
                }
            }
        }

        $city = $city !== null ? mb_scrub($city, 'UTF-8') : null;
        $phone = ContactParser::normalizePhone($phoneRaw, $phonePrefix);
        $whatsapp = ContactParser::normalizePhone($whatsappRaw, $phonePrefix);

        return [
            'name' => ContactParser::normalizeName($name),
            'phone' => $phone ?? $whatsapp,
            'whatsapp' => $whatsapp,
            'email' => $email,
            'profile_url' => $link,
            'city' => $city ? mb_substr($city, 0, 120) : null,
            'age' => $age,
            'image_url' => $image,
        ];
    }

    /**
     * Profile page: fills phone/whatsapp/name/city/age gaps for a listing row.
     */
    public function extractProfile(HtmlDocument $doc, array $profileRules, string $profileUrl, string $phonePrefix): array
    {
        $root = $doc->first('main, article, #content, .content, body') ?? $doc->first('body');
        if (!$root) {
            return [];
        }
        $rules = $profileRules + ['phone_selector' => 'a[href^="tel:"]', 'whatsapp_selector' => 'a[href*="wa.me"], a[href*="whatsapp.com"]', 'name_selector' => 'h1'];
        $row = $this->extractCard($doc, $root, $rules + ['link_selector' => ''], $profileUrl, $phonePrefix);
        $row['profile_url'] = $profileUrl;

        return $row;
    }

    private function pick(HtmlDocument $doc, DOMElement $card, ?string $selector): ?DOMElement
    {
        $selector = trim((string) $selector);

        return $selector === '' ? null : $doc->first($selector, $card);
    }
}
