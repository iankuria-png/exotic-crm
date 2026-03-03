<?php

namespace App\Services\PushCampaign;

use App\Models\ScraperProfilePreset;
use App\Support\DomParserTrait;
use DOMNode;
use DOMXPath;

class SelectorDetectionService
{
    use DomParserTrait;

    public function detectSelectors(string $url): array
    {
        $payload = $this->fetchHtml($url);
        [, $xpath] = $this->parseDom((string) ($payload['html'] ?? ''));

        $name = $this->detectTextField($xpath, [
            ['selector' => '[itemprop="name"]', 'confidence' => 'high'],
            ['selector' => 'h1', 'confidence' => 'high'],
            ['selector' => '.profile-name', 'confidence' => 'medium'],
            ['selector' => '.name', 'confidence' => 'medium'],
            ['selector' => '#name', 'confidence' => 'medium'],
            ['selector' => 'h2', 'confidence' => 'low'],
        ], 50);

        $age = $this->detectAgeField($xpath);
        $city = $this->detectTextField($xpath, [
            ['selector' => '[itemprop="addressLocality"]', 'confidence' => 'high'],
            ['selector' => '.profile-city', 'confidence' => 'medium'],
            ['selector' => '.city', 'confidence' => 'medium'],
            ['selector' => '[class*="location"]', 'confidence' => 'medium'],
            ['selector' => '[id*="city"]', 'confidence' => 'medium'],
        ], 80);

        $phone = $this->detectPhoneField($xpath);
        $image = $this->detectImageField($xpath);

        return [
            'url' => $url,
            'selectors' => [
                'name' => $name['selector'],
                'age' => $age['selector'],
                'city' => $city['selector'],
                'phone' => $phone['selector'],
                'image' => $image['selector'],
            ],
            'extracted' => [
                'name' => $name['value'],
                'age' => $age['value'],
                'city' => $city['value'],
                'phone' => $phone['value'],
                'image' => $image['value'],
            ],
            'confidence' => [
                'name' => $name['confidence'],
                'age' => $age['confidence'],
                'city' => $city['confidence'],
                'phone' => $phone['confidence'],
                'image' => $image['confidence'],
            ],
        ];
    }

    public function testPreset(string $url, ScraperProfilePreset $preset): array
    {
        $payload = $this->fetchHtml($url);
        [, $xpath] = $this->parseDom((string) ($payload['html'] ?? ''));

        $extracted = [
            'name' => $this->extractTextBySelector($xpath, (string) $preset->name_selector),
            'age' => $this->extractTextBySelector($xpath, (string) $preset->age_selector),
            'city' => $this->extractTextBySelector($xpath, (string) $preset->city_selector),
            'phone' => $this->extractTextBySelector($xpath, (string) $preset->phone_selector),
            'image' => $this->extractImageBySelector($xpath, (string) $preset->image_selector),
        ];

        if (!empty($preset->name_regex) && !empty($extracted['name'])) {
            if (preg_match('/' . $preset->name_regex . '/i', $extracted['name'], $match)) {
                $extracted['name'] = trim((string) ($match[1] ?? $match[0] ?? $extracted['name']));
            }
        }

        if (!empty($preset->age_regex) && !empty($extracted['age'])) {
            if (preg_match('/' . $preset->age_regex . '/i', $extracted['age'], $match)) {
                $extracted['age'] = trim((string) ($match[1] ?? $match[0] ?? $extracted['age']));
            }
        }

        return [
            'url' => $url,
            'selectors' => [
                'name' => $preset->name_selector,
                'age' => $preset->age_selector,
                'city' => $preset->city_selector,
                'phone' => $preset->phone_selector,
                'image' => $preset->image_selector,
            ],
            'extracted' => $extracted,
            'success' => [
                'name' => !empty($extracted['name']),
                'age' => !empty($extracted['age']),
                'city' => !empty($extracted['city']),
                'phone' => !empty($extracted['phone']),
                'image' => !empty($extracted['image']),
            ],
        ];
    }

    private function detectTextField(DOMXPath $xpath, array $selectorCandidates, int $maxLength = 120): array
    {
        foreach ($selectorCandidates as $candidate) {
            $selector = (string) ($candidate['selector'] ?? '');
            $confidence = (string) ($candidate['confidence'] ?? 'low');
            if ($selector === '') {
                continue;
            }

            $value = $this->extractTextBySelector($xpath, $selector);
            if (!empty($value) && mb_strlen($value) <= $maxLength) {
                return [
                    'selector' => $selector,
                    'value' => $value,
                    'confidence' => $confidence,
                ];
            }
        }

        return [
            'selector' => null,
            'value' => null,
            'confidence' => 'low',
        ];
    }

    private function detectAgeField(DOMXPath $xpath): array
    {
        $candidates = [
            ['selector' => '[itemprop="age"]', 'confidence' => 'high'],
            ['selector' => '.profile-age', 'confidence' => 'medium'],
            ['selector' => '.age', 'confidence' => 'medium'],
            ['selector' => '[class*="age"]', 'confidence' => 'medium'],
            ['selector' => '[id*="age"]', 'confidence' => 'medium'],
        ];

        foreach ($candidates as $candidate) {
            $value = $this->extractTextBySelector($xpath, (string) $candidate['selector']);
            $age = $this->extractAgeFromText((string) $value);

            if ($age !== null) {
                return [
                    'selector' => $candidate['selector'],
                    'value' => $age,
                    'confidence' => $candidate['confidence'],
                ];
            }
        }

        $bodyText = $this->nodeText($xpath->query('//body')->item(0));
        $age = $this->extractAgeFromText($bodyText);

        return [
            'selector' => null,
            'value' => $age,
            'confidence' => $age !== null ? 'low' : 'low',
        ];
    }

    private function detectPhoneField(DOMXPath $xpath): array
    {
        $selectorCandidates = [
            ['selector' => 'a[href^="tel:"]', 'confidence' => 'high'],
            ['selector' => 'a[href^="whatsapp:"]', 'confidence' => 'high'],
            ['selector' => '.phone', 'confidence' => 'medium'],
            ['selector' => '.contact-phone', 'confidence' => 'medium'],
            ['selector' => '[class*="phone"]', 'confidence' => 'medium'],
            ['selector' => '[id*="phone"]', 'confidence' => 'medium'],
        ];

        foreach ($selectorCandidates as $candidate) {
            $nodes = $this->queryCss($xpath, (string) $candidate['selector']);

            foreach ($nodes as $node) {
                $href = trim((string) ($node->attributes?->getNamedItem('href')?->nodeValue ?? ''));
                if (str_starts_with(strtolower($href), 'tel:')) {
                    $phone = $this->normalizePhone(substr($href, 4));
                    if ($phone) {
                        return [
                            'selector' => $candidate['selector'],
                            'value' => $phone,
                            'confidence' => $candidate['confidence'],
                        ];
                    }
                }

                $textPhone = $this->normalizePhone($this->extractPhoneFromText($this->nodeText($node)));
                if ($textPhone) {
                    return [
                        'selector' => $candidate['selector'],
                        'value' => $textPhone,
                        'confidence' => $candidate['confidence'],
                    ];
                }
            }
        }

        $bodyText = $this->nodeText($xpath->query('//body')->item(0));
        $fallback = $this->normalizePhone($this->extractPhoneFromText($bodyText));

        return [
            'selector' => null,
            'value' => $fallback,
            'confidence' => $fallback ? 'low' : 'low',
        ];
    }

    private function detectImageField(DOMXPath $xpath): array
    {
        $ogNode = $xpath->query('//meta[@property="og:image" or @name="og:image"]')->item(0);
        if ($ogNode instanceof DOMNode) {
            $content = trim((string) ($ogNode->attributes?->getNamedItem('content')?->nodeValue ?? ''));
            if ($content !== '') {
                return [
                    'selector' => 'meta[property="og:image"]',
                    'value' => $content,
                    'confidence' => 'high',
                ];
            }
        }

        $selectorCandidates = [
            ['selector' => '.profile-image img', 'confidence' => 'medium'],
            ['selector' => '.main-image img', 'confidence' => 'medium'],
            ['selector' => 'img.profile-image', 'confidence' => 'medium'],
            ['selector' => 'img', 'confidence' => 'low'],
        ];

        foreach ($selectorCandidates as $candidate) {
            $imageUrl = $this->extractImageBySelector($xpath, (string) $candidate['selector']);
            if (!empty($imageUrl)) {
                return [
                    'selector' => $candidate['selector'],
                    'value' => $imageUrl,
                    'confidence' => $candidate['confidence'],
                ];
            }
        }

        return [
            'selector' => null,
            'value' => null,
            'confidence' => 'low',
        ];
    }

    private function extractTextBySelector(DOMXPath $xpath, string $selector): ?string
    {
        if (trim($selector) === '') {
            return null;
        }

        $node = $this->queryCss($xpath, $selector)[0] ?? null;
        if (!$node) {
            return null;
        }

        $text = $this->nodeText($node);

        return $text !== '' ? $text : null;
    }

    private function extractImageBySelector(DOMXPath $xpath, string $selector): ?string
    {
        if (trim($selector) === '') {
            return null;
        }

        $node = $this->queryCss($xpath, $selector)[0] ?? null;
        if (!$node) {
            return null;
        }

        $src = trim((string) ($node->attributes?->getNamedItem('src')?->nodeValue ?? ''));
        if ($src !== '') {
            return $src;
        }

        $content = trim((string) ($node->attributes?->getNamedItem('content')?->nodeValue ?? ''));

        return $content !== '' ? $content : null;
    }

    private function extractAgeFromText(string $text): ?string
    {
        $value = trim($text);
        if ($value === '') {
            return null;
        }

        if (preg_match('/\b(1[89]|[2-5][0-9]|6[0-5])\b/', $value, $match)) {
            return (string) $match[1];
        }

        return null;
    }
}
