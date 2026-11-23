<?php

namespace App\Services\Scraper;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Parsed page with CSS querying. Selector errors return no matches instead of throwing,
 * and are reported through lastSelectorError() so the UI can say what was wrong.
 */
class HtmlDocument
{
    private static ?CssSelectorConverter $converter = null;

    public readonly DOMXPath $xpath;

    private ?string $lastSelectorError = null;

    private ?string $text = null;

    public function __construct(public readonly string $html, public readonly string $url)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $source = $html !== '' ? $html : '<html><body></body></html>';
        if (!preg_match('/<meta[^>]+charset/i', substr($source, 0, 4096))) {
            $source = '<?xml encoding="UTF-8">' . $source;
        }
        $dom->loadHTML($source, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        $this->xpath = new DOMXPath($dom);
    }

    /** @return array<int, DOMElement> */
    public function select(string $selector, ?DOMNode $context = null): array
    {
        $selector = trim($selector);
        $this->lastSelectorError = null;
        if ($selector === '') {
            return [];
        }

        try {
            self::$converter ??= new CssSelectorConverter();
            $expr = self::$converter->toXPath($selector);
            $list = $this->xpath->query($expr, $context);
        } catch (\Throwable $exception) {
            $this->lastSelectorError = 'Invalid selector "' . $selector . '": ' . mb_substr($exception->getMessage(), 0, 120);

            return [];
        }

        $nodes = [];
        foreach ($list ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    public function first(string $selector, ?DOMNode $context = null): ?DOMElement
    {
        return $this->select($selector, $context)[0] ?? null;
    }

    public function count(string $selector): int
    {
        return count($this->select($selector));
    }

    public function lastSelectorError(): ?string
    {
        return $this->lastSelectorError;
    }

    public static function textOf(?DOMNode $node): string
    {
        if (!$node) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
    }

    public function bodyText(): string
    {
        if ($this->text === null) {
            $clone = $this->xpath->query('//body')->item(0);
            $text = '';
            if ($clone) {
                foreach ($this->xpath->query('.//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]', $clone) ?: [] as $t) {
                    $text .= ' ' . $t->nodeValue;
                }
            }
            $this->text = trim((string) preg_replace('/\s+/u', ' ', $text));
        }

        return $this->text;
    }

    public function title(): string
    {
        return self::textOf($this->xpath->query('//title')->item(0));
    }

    public function metaContent(string $name): array
    {
        $values = [];
        foreach ($this->xpath->query(sprintf('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="%s"]/@content', strtolower($name))) ?: [] as $attr) {
            $values[] = trim((string) $attr->nodeValue);
        }

        return $values;
    }

    /**
     * Short CSS path for an element, preferring stable classes, for showing/learning selectors.
     */
    public static function describe(DOMElement $element): string
    {
        $tag = strtolower($element->tagName);
        $classes = array_values(array_filter(preg_split('/\s+/', trim((string) $element->getAttribute('class'))) ?: [], static function (string $class): bool {
            return $class !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{1,40}$/', $class) && !preg_match('/\d{3,}|^(active|current|odd|even|first|last|clearfix|col-|row$)/i', $class);
        }));

        return $classes ? $tag . '.' . implode('.', array_slice($classes, 0, 2)) : $tag;
    }
}
