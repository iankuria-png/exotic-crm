<?php

namespace App\Services\Ai;

/**
 * GSM-7 aware single-segment SMS helper.
 *
 * A single SMS segment holds 160 GSM-7 code units. Characters in the GSM-7
 * "extension" table (^ { } \ [ ] ~ | €) consume TWO units each, so a naive
 * strlen()/mb_strlen() <= 160 check under-counts and can silently produce a
 * multi-part message. This limiter counts true GSM-7 units (including the deep
 * link) and trims the digest deterministically so the assembled message always
 * encodes to exactly one segment.
 */
class GsmSmsLimiter
{
    public const SEGMENT_UNITS = 160;

    /** GSM 03.38 basic character set (each = 1 unit). */
    private const GSM_BASIC =
        "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** GSM 03.38 extension table (each = 2 units, prefixed by ESC). */
    private const GSM_EXTENSION = ['^', '{', '}', '\\', '[', ']', '~', '|', '€'];

    /** Common non-GSM characters transliterated to GSM-safe equivalents. */
    private const TRANSLITERATIONS = [
        "\u{2018}" => "'", "\u{2019}" => "'",   // curly single quotes
        "\u{201C}" => '"', "\u{201D}" => '"',   // curly double quotes
        "\u{2013}" => '-', "\u{2014}" => '-',   // en/em dash
        "\u{2026}" => '...',                       // ellipsis
        "\u{00A0}" => ' ',                          // non-breaking space
        "\u{2022}" => '-',                          // bullet
    ];

    /**
     * Replace common non-GSM characters with GSM-safe equivalents and strip any
     * remaining characters that are not representable in GSM-7.
     */
    public function sanitize(string $text): string
    {
        $text = strtr($text, self::TRANSLITERATIONS);

        $out = '';
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            if ($this->charUnits($char) !== null) {
                $out .= $char;
            }
            // Non-GSM characters are dropped to guarantee GSM-7 encodability.
        }

        return $out;
    }

    /**
     * Count GSM-7 code units. Assumes GSM-safe input (call sanitize() first);
     * any stray non-GSM char is counted defensively as 2 units.
     */
    public function units(string $text): int
    {
        $units = 0;
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $units += $this->charUnits(mb_substr($text, $i, 1)) ?? 2;
        }

        return $units;
    }

    public function fitsSingleSegment(string $text): bool
    {
        return $this->units($text) <= self::SEGMENT_UNITS;
    }

    /**
     * Assemble "{summary} {link}" so the whole message is exactly one GSM-7
     * segment, trimming the summary deterministically (from the end) if needed.
     *
     * @return array{text: string, char_count: int, segments: int, link_units: int}
     */
    public function fitWithLink(string $summary, string $link): array
    {
        $link = trim($link);
        $summary = $this->sanitize(trim($summary));

        $linkUnits = $this->units($link);
        $separatorUnits = $link === '' ? 0 : 1; // single space between summary and link
        $budget = self::SEGMENT_UNITS - $linkUnits - $separatorUnits;

        if ($budget < 0) {
            // Link alone overflows a segment; nothing we can do but send the link.
            $text = $link;

            return [
                'text' => $text,
                'char_count' => $this->units($text),
                'segments' => 1,
                'link_units' => $linkUnits,
            ];
        }

        $summary = $this->trimToUnits($summary, $budget);

        $text = $link === ''
            ? $summary
            : trim($summary) . ' ' . $link;

        return [
            'text' => $text,
            'char_count' => $this->units($text),
            'segments' => max(1, (int) ceil($this->units($text) / self::SEGMENT_UNITS)),
            'link_units' => $linkUnits,
        ];
    }

    /** Trim text from the end so it fits within $maxUnits GSM-7 units. */
    private function trimToUnits(string $text, int $maxUnits): string
    {
        if ($this->units($text) <= $maxUnits) {
            return $text;
        }

        $length = mb_strlen($text);
        while ($length > 0 && $this->units(mb_substr($text, 0, $length)) > $maxUnits) {
            $length--;
        }

        return rtrim(mb_substr($text, 0, $length));
    }

    /** @return int|null 1 or 2 for GSM chars, null for non-GSM. */
    private function charUnits(string $char): ?int
    {
        if (in_array($char, self::GSM_EXTENSION, true)) {
            return 2;
        }

        return mb_strpos(self::GSM_BASIC, $char) !== false ? 1 : null;
    }
}
