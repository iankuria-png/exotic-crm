<?php

namespace App\Support;

/**
 * Finds text in a profile bio that visitors and Google would see as broken:
 * garbled accents ("dÃ©tour" for "détour"), lost characters, escaped HTML,
 * Markdown and AI leftovers, invisible characters and emoji. Repairs what can
 * be repaired without guessing.
 *
 * resources/js/utils/bioTextIntegrity.js is a line-for-line mirror used by the
 * editors; tests/Fixtures/bio-text-integrity-cases.json keeps the two in step.
 * Change both together.
 */
final class BioTextIntegrity
{
    public const FORMAT_HTML = 'html';

    public const FORMAT_TEXT = 'text';

    public const BROKEN_ACCENTS = 'broken_accents';

    public const LOST_CHARACTERS = 'lost_characters';

    public const ESCAPED_HTML = 'escaped_html';

    public const MARKDOWN = 'markdown';

    public const AI_TEXT = 'ai_text';

    public const INVISIBLE_CHARACTERS = 'invisible_characters';

    public const EMOJI = 'emoji';

    /** Checked and fixed in this order: later checks see earlier repairs. */
    public const KINDS = [
        self::BROKEN_ACCENTS => ['severity' => 'error', 'fixable' => true],
        self::LOST_CHARACTERS => ['severity' => 'error', 'fixable' => false],
        self::ESCAPED_HTML => ['severity' => 'error', 'fixable' => true],
        self::MARKDOWN => ['severity' => 'warning', 'fixable' => true],
        self::AI_TEXT => ['severity' => 'error', 'fixable' => false],
        self::INVISIBLE_CHARACTERS => ['severity' => 'warning', 'fixable' => true],
        self::EMOJI => ['severity' => 'warning', 'fixable' => true],
    ];

    /** Fixes that only restore what the writer meant: safe without review. */
    public const SAFE_FIXES = [
        self::BROKEN_ACCENTS,
        self::ESCAPED_HTML,
        self::INVISIBLE_CHARACTERS,
    ];

    /**
     * What a market scan looks for. Emoji and **asterisk** emphasis are often
     * the advertiser's own style, so they are flagged only while editing.
     */
    public const SCAN_KINDS = [
        self::BROKEN_ACCENTS,
        self::LOST_CHARACTERS,
        self::ESCAPED_HTML,
        self::AI_TEXT,
        self::INVISIBLE_CHARACTERS,
    ];

    private const MAX_SAMPLES = 4;

    /** Windows-1252 characters for bytes 0x80–0x9F (unassigned bytes omitted). */
    private const CP1252 = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85,
        0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A,
        0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92,
        0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
        0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C,
        0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    /** Named entities for U+00A0–U+00FF, in code point order. */
    private const LATIN1_ENTITIES = 'nbsp iexcl cent pound curren yen brvbar sect uml copy ordf laquo not shy reg macr '
        .'deg plusmn sup2 sup3 acute micro para middot cedil sup1 ordm raquo frac14 frac12 frac34 iquest '
        .'Agrave Aacute Acirc Atilde Auml Aring AElig Ccedil Egrave Eacute Ecirc Euml Igrave Iacute Icirc Iuml '
        .'ETH Ntilde Ograve Oacute Ocirc Otilde Ouml times Oslash Ugrave Uacute Ucirc Uuml Yacute THORN szlig '
        .'agrave aacute acirc atilde auml aring aelig ccedil egrave eacute ecirc euml igrave iacute icirc iuml '
        .'eth ntilde ograve oacute ocirc otilde ouml divide oslash ugrave uacute ucirc uuml yacute thorn yuml';

    private const CP1252_ENTITIES = [
        'euro' => 0x20AC, 'sbquo' => 0x201A, 'fnof' => 0x0192, 'bdquo' => 0x201E, 'hellip' => 0x2026,
        'dagger' => 0x2020, 'Dagger' => 0x2021, 'circ' => 0x02C6, 'permil' => 0x2030, 'Scaron' => 0x0160,
        'lsaquo' => 0x2039, 'OElig' => 0x0152, 'lsquo' => 0x2018, 'rsquo' => 0x2019, 'ldquo' => 0x201C,
        'rdquo' => 0x201D, 'bull' => 0x2022, 'ndash' => 0x2013, 'mdash' => 0x2014, 'tilde' => 0x02DC,
        'trade' => 0x2122, 'scaron' => 0x0161, 'rsaquo' => 0x203A, 'oelig' => 0x0153, 'Yuml' => 0x0178,
    ];

    private const MOJIBAKE_CONTINUATION = '\x{80}-\x{BF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
        .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2013}\x{2014}\x{02DC}'
        .'\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}';

    // Joiners and direction marks are left alone: they hold emoji sequences
    // and right-to-left text together.
    private const INVISIBLE = '/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}\x{AD}\x{200B}\x{2060}-\x{2064}\x{FEFF}]/u';

    private const INVISIBLE_ENTITY = '/&(?:shy|zwsp|#173|#8203|#65279|#x(?:ad|200b|feff));/i';

    private const MARKDOWN_RULES = [
        '/```[a-z]*/iu' => '',
        '/\*\*([^*\n<>]+?)\*\*/u' => '$1',
        '/(?<![\p{L}\p{N}_])__([^_\n<>]+?)__(?![\p{L}\p{N}_])/u' => '$1',
        '/(^|\n)[ \t]*#{1,6}[ \t]+(?=[^ \t\n\r\f\v])/u' => '$1',
        '/\[([^\]\n<>]+)\]\((?:https?:\/\/|\/)[^) \t\n\r\f\v<>]*\)/u' => '$1',
    ];

    /** MARKDOWN_RULES as whole matches, so samples show the heading text too. */
    private const MARKDOWN_SAMPLES = [
        '/```[a-z]*/iu',
        '/\*\*[^*\n<>]+?\*\*/u',
        '/(?<![\p{L}\p{N}_])__[^_\n<>]+?__(?![\p{L}\p{N}_])/u',
        '/(?:^|\n)[ \t]*#{1,6}[ \t]+[^ \t\n\r\f\v][^\n]*/u',
        '/\[[^\]\n<>]+\]\((?:https?:\/\/|\/)[^) \t\n\r\f\v<>]*\)/u',
    ];

    private const EMOJI_PATTERN = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0E}\x{FE0F}\x{20E3}]/u';

    private const AI_PATTERNS = [
        '/(?<![\p{L}\p{N}_])(?:I will not|I won[\'’]t|I cannot|I can[\'’]t|I am unable to|I[\'’]m unable to|I am not able to|I[\'’]m not able to)[ \t\n\r\f\v]+(?:write|create|produce|generate|provide|help with|assist with)(?![\p{L}\p{N}_])(?:[ \t]+[\p{L}\'’-]+){0,3}?[ \t]+(?:content|profile|profiles|bio|bios|biography|description|that|this|such|explicit|sexual|promotional|material|request|text|advert|advertisement)(?![\p{L}\p{N}_])[^.!?\n<]*/iu',
        '/(?<![\p{L}\p{N}_])as an AI(?![\p{L}\p{N}_])[^.!?\n<]*|(?<![\p{L}\p{N}_])(?:an|a) (?:AI|large) language model(?![\p{L}\p{N}_])/iu',
        '/(?<![\p{L}\p{N}_])promotional content for sexual services(?![\p{L}\p{N}_])|(?<![\p{L}\p{N}_])If you have other writing projects(?![\p{L}\p{N}_])/iu',
        '/(?:^|>|\n)[ \t\n\r\f\v]*(?:(?:Sure|Certainly|Of course)[!,.]?[ \t\n\r\f\v]+)?Here(?:[\'’]s| is) (?:a|an|the|your) (?:[a-z-]+ ){0,3}(?:bio|biography|profile|description|version|rewrite)(?![\p{L}\p{N}_])[^:\n<]*:?/iu',
        '/(?:^|>|\n)[ \t\n\r\f\v]*Voici (?:une|la|votre|ta) (?:[a-zé-]+ ){0,3}(?:bio|biographie|description|présentation)(?![\p{L}\p{N}_])[^:\n<]*:?/iu',
        '/(?:^|>|\n)[ \t\n\r\f\v]*Aqui está (?:a|uma|sua|tua) (?:[a-zçã-]+ ){0,3}(?:bio|biografia|descrição|apresentação)(?![\p{L}\p{N}_])[^:\n<]*:?/iu',
        '/\[(?:name|nom|nome|city|ville|cidade|area|location|phone|number|age|insert[^\]]*|your [a-z ]+)\]|\{\{?[ \t\n\r\f\v]*(?:name|city|phone|location)[ \t\n\r\f\v]*\}?\}|(?<![\p{L}\p{N}_])lorem ipsum(?![\p{L}\p{N}_])/iu',
    ];

    /**
     * @return array{clean: bool, errors: int, warnings: int, issues: list<array{kind: string, severity: string, fixable: bool, count: int, samples: list<array{found: string, fixed: ?string}>}>}
     */
    public static function inspect(string $content, string $format = self::FORMAT_HTML, ?array $kinds = null): array
    {
        $issues = [];
        $current = $content;

        foreach (array_keys(self::KINDS) as $kind) {
            if ($kinds !== null && ! in_array($kind, $kinds, true)) {
                continue;
            }

            $found = self::detect($kind, $current, $format);
            if ($found['count'] > 0) {
                $issues[] = [
                    'kind' => $kind,
                    'severity' => self::KINDS[$kind]['severity'],
                    'fixable' => self::KINDS[$kind]['fixable'],
                    'count' => $found['count'],
                    'samples' => $found['samples'],
                ];
            }

            if (self::KINDS[$kind]['fixable']) {
                $current = self::fixKind($kind, $current, $format);
            }
        }

        $errors = count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'error'));

        return [
            'clean' => $issues === [],
            'errors' => $errors,
            'warnings' => count($issues) - $errors,
            'issues' => $issues,
        ];
    }

    /** Apply the given fixes (all fixable kinds by default), in catalogue order. */
    public static function fix(string $content, string $format = self::FORMAT_HTML, ?array $kinds = null): string
    {
        // Garbled accents hide control characters inside them ("’" read as
        // Latin-1 is "â" plus two); stripping those first would lose the accent.
        if ($kinds !== null && in_array(self::INVISIBLE_CHARACTERS, $kinds, true)) {
            $kinds[] = self::BROKEN_ACCENTS;
        }

        // One repair can reveal another (a decoded entity may itself be
        // garbled), so repeat until the text settles.
        for ($round = 0; $round < 3; $round++) {
            $before = $content;
            foreach (array_keys(self::KINDS) as $kind) {
                if (! self::KINDS[$kind]['fixable'] || ($kinds !== null && ! in_array($kind, $kinds, true))) {
                    continue;
                }
                $content = self::fixKind($kind, $content, $format);
            }
            if ($content === $before) {
                break;
            }
        }

        return $content;
    }

    /** Whether any of the given kinds (safe fixes by default) would change this content. */
    public static function needsFix(string $content, string $format = self::FORMAT_HTML, array $kinds = self::SAFE_FIXES): bool
    {
        return self::fix($content, $format, $kinds) !== $content;
    }

    // ─── Detection ───────────────────────────────────────────────────────────

    /** @return array{count: int, samples: list<array{found: string, fixed: ?string}>} */
    private static function detect(string $kind, string $content, string $format): array
    {
        return match ($kind) {
            self::BROKEN_ACCENTS => self::detectByWords($content, $format, fn (string $text): array => self::repairMojibake($text)),
            self::LOST_CHARACTERS => self::detectMatches($content, $format, '/[^ \t\n\r\f\v]*\x{FFFD}[^ \t\n\r\f\v]*/u', countPattern: '/\x{FFFD}/u'),
            self::ESCAPED_HTML => self::detectEscapedHtml($content, $format),
            self::MARKDOWN => self::detectMarkdown($content, $format),
            self::AI_TEXT => self::detectAiText($content),
            self::INVISIBLE_CHARACTERS => self::detectInvisible($content, $format),
            self::EMOJI => self::detectMatches($content, $format, self::EMOJI_PATTERN),
            default => ['count' => 0, 'samples' => []],
        };
    }

    /**
     * Run a text repair and report the words it changes as before/after pairs.
     *
     * @param  callable(string): array{0: string, 1: int}  $repair
     */
    private static function detectByWords(string $content, string $format, callable $repair): array
    {
        $count = 0;
        $samples = [];

        foreach (self::textSegments($content, $format) as $segment) {
            $text = $format === self::FORMAT_HTML ? self::decodeLatinEntities($segment) : $segment;
            [, $changes] = $repair($text);
            if ($changes === 0) {
                continue;
            }
            $count += $changes;

            // Pair up the words that differ; the repair keeps word boundaries.
            $before = preg_split('/[ \t\r\n]+/u', trim($text)) ?: [];
            foreach ($before as $word) {
                [$fixedWord] = $repair($word.' ');
                $fixedWord = rtrim($fixedWord, ' ');
                if ($fixedWord !== $word) {
                    self::addSample($samples, $word, $fixedWord);
                }
            }
        }

        return ['count' => $count, 'samples' => $samples];
    }

    private static function detectMatches(string $content, string $format, string $pattern, ?string $countPattern = null): array
    {
        $count = 0;
        $samples = [];

        foreach (self::textSegments($content, $format) as $segment) {
            $count += preg_match_all($countPattern ?? $pattern, $segment);
            if (preg_match_all($pattern, $segment, $matches)) {
                foreach ($matches[0] as $match) {
                    self::addSample($samples, self::excerpt($match), null);
                }
            }
        }

        return ['count' => $count, 'samples' => $samples];
    }

    private static function detectEscapedHtml(string $content, string $format): array
    {
        $count = 0;
        $samples = [];

        foreach (self::textSegments($content, $format) as $segment) {
            $pattern = $format === self::FORMAT_HTML ? self::escapedHtmlPattern() : self::literalHtmlPattern();
            if (preg_match_all($pattern, $segment, $matches)) {
                foreach ($matches[0] as $match) {
                    $fixed = self::fixEscapedHtml($match, $format);
                    if ($fixed === $match) {
                        continue;
                    }
                    $count++;
                    self::addSample($samples, $match, trim($fixed) === '' ? null : $fixed);
                }
            }
        }

        return ['count' => $count, 'samples' => $samples];
    }

    private static function detectMarkdown(string $content, string $format): array
    {
        $count = 0;
        $samples = [];

        foreach (self::textSegments($content, $format) as $segment) {
            foreach (self::MARKDOWN_SAMPLES as $pattern) {
                if (preg_match_all($pattern, $segment, $matches)) {
                    foreach ($matches[0] as $match) {
                        $count++;
                        $fixed = trim(self::stripMarkdown($match)[0]);
                        self::addSample($samples, self::excerpt($match), $fixed === '' ? null : self::excerpt($fixed));
                    }
                }
            }
        }

        return ['count' => $count, 'samples' => $samples];
    }

    private static function detectAiText(string $content): array
    {
        $count = 0;
        $samples = [];

        foreach (self::AI_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[0] as $match) {
                    $match = trim(ltrim($match, ">\n"));
                    if ($match === '') {
                        continue;
                    }
                    $count++;
                    self::addSample($samples, self::excerpt($match), null);
                }
            }
        }

        return ['count' => $count, 'samples' => $samples];
    }

    private static function detectInvisible(string $content, string $format): array
    {
        $count = 0;
        $names = [];

        foreach (self::textSegments($content, $format) as $segment) {
            if (preg_match_all(self::INVISIBLE, $segment, $matches)) {
                foreach ($matches[0] as $char) {
                    $count++;
                    $names[self::invisibleName($char)] = true;
                }
            }
            if ($format === self::FORMAT_HTML && preg_match_all(self::INVISIBLE_ENTITY, $segment, $matches)) {
                foreach ($matches[0] as $entity) {
                    $count++;
                    $names[self::invisibleName(self::decodeEntity($entity) ?? "\u{200B}")] = true;
                }
            }
        }

        $samples = [];
        foreach (array_keys($names) as $name) {
            self::addSample($samples, $name, null);
        }

        return ['count' => $count, 'samples' => $samples];
    }

    // ─── Fixes ───────────────────────────────────────────────────────────────

    private static function fixKind(string $kind, string $content, string $format): string
    {
        return match ($kind) {
            self::BROKEN_ACCENTS => self::mapSegments($content, $format, function (string $segment) use ($format): string {
                $text = $format === self::FORMAT_HTML ? self::decodeLatinEntities($segment) : $segment;
                [$fixed, $changes] = self::repairMojibake($text);

                // Untouched segments keep their original entity spelling.
                return $changes > 0 ? $fixed : $segment;
            }),
            self::ESCAPED_HTML => self::mapSegments($content, $format, function (string $segment) use ($format): string {
                $pattern = $format === self::FORMAT_HTML ? self::escapedHtmlPattern() : self::literalHtmlPattern();

                return preg_replace_callback($pattern, fn (array $m): string => self::fixEscapedHtml($m[0], $format), $segment) ?? $segment;
            }),
            self::MARKDOWN => self::mapSegments($content, $format, fn (string $segment): string => self::stripMarkdown($segment)[0]),
            self::INVISIBLE_CHARACTERS => self::mapSegments($content, $format, function (string $segment) use ($format): string {
                $segment = preg_replace(self::INVISIBLE, '', $segment) ?? $segment;

                return $format === self::FORMAT_HTML ? (preg_replace(self::INVISIBLE_ENTITY, '', $segment) ?? $segment) : $segment;
            }),
            self::EMOJI => self::mapSegments($content, $format, function (string $segment): string {
                $stripped = preg_replace(self::EMOJI_PATTERN, '', $segment) ?? $segment;
                if ($stripped === $segment) {
                    return $segment;
                }

                // Joiners that held the removed emoji together have nothing left to join.
                $stripped = str_replace("\u{200D}", '', $stripped);

                // Close the gap an emoji leaves between words, and before punctuation.
                $stripped = preg_replace('/[ \t]{2,}/u', ' ', $stripped) ?? $stripped;

                return preg_replace('/[ \t]+([.,!?;:])/u', '$1', $stripped) ?? $stripped;
            }),
            default => $content,
        };
    }

    /**
     * Undo UTF-8 text that was decoded as Latin-1 or Windows-1252, up to three
     * layers deep. Only byte runs that form valid UTF-8 for a character a bio
     * could plausibly contain are replaced, so real accents are never touched.
     *
     * @return array{0: string, 1: int} the repaired text and how many repairs were made
     */
    private static function repairMojibake(string $text): array
    {
        $total = 0;
        $c = self::MOJIBAKE_CONTINUATION;
        $sequence = "/[\x{C2}-\x{DF}][{$c}]|[\x{E0}-\x{EF}][{$c}]{2}|[\x{F0}-\x{F4}][{$c}]{3}/u";

        for ($pass = 0; $pass < 3; $pass++) {
            $changes = 0;
            $text = preg_replace_callback($sequence, function (array $m) use (&$changes): string {
                $decoded = self::decodeSequence($m[0]);
                if ($decoded === null) {
                    return $m[0];
                }
                $changes++;

                return $decoded;
            }, $text) ?? $text;

            if ($changes === 0) {
                break;
            }
            $total += $changes;
        }

        // Forms left once a non-breaking space or an unassigned byte was lost
        // on the way: "Ã " was "à", a stray "Â " was a space, a lone "â€" was ”.
        $text = preg_replace_callback('/(?<!\p{Lu})Ã(?=[ \t])/u', function () use (&$total): string {
            $total++;

            return 'à';
        }, $text) ?? $text;
        $text = preg_replace_callback('/(?<!\p{Lu})Â(?=[ \t])/u', function () use (&$total): string {
            $total++;

            return '';
        }, $text) ?? $text;
        $text = preg_replace_callback("/â€(?![{$c}])/u", function () use (&$total): string {
            $total++;

            return "\u{201D}";
        }, $text) ?? $text;

        // A lone C1 control is a single Windows-1252 byte read as Latin-1:
        // U+0092 was "’", U+0080 was "€". Unassigned bytes are left for the
        // invisible-character check.
        $text = preg_replace_callback('/[\x{80}-\x{9F}]/u', function (array $m) use (&$total): string {
            $char = array_search(mb_ord($m[0], 'UTF-8'), self::CP1252, true);
            if ($char === false) {
                return $m[0];
            }
            $total++;

            return mb_chr($char, 'UTF-8');
        }, $text) ?? $text;

        return [$text, $total];
    }

    private static function decodeSequence(string $chars): ?string
    {
        $bytes = '';
        foreach (mb_str_split($chars) as $char) {
            $cp = mb_ord($char, 'UTF-8');
            if ($cp <= 0xFF) {
                $bytes .= chr($cp);
            } elseif (isset(self::CP1252[$cp])) {
                $bytes .= chr(self::CP1252[$cp]);
            } else {
                return null;
            }
        }

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return null;
        }

        $cp = mb_ord($bytes, 'UTF-8');

        return self::isPlausible($cp) ? $bytes : null;
    }

    /** Characters a bio could have meant: accents, punctuation, symbols, emoji. */
    private static function isPlausible(int $cp): bool
    {
        return ($cp >= 0xA0 && $cp <= 0x17F)
            || ($cp >= 0x600 && $cp <= 0x6FF)
            || ($cp >= 0x2000 && $cp <= 0x206F)
            || ($cp >= 0x20A0 && $cp <= 0x20CF)
            || ($cp >= 0x2100 && $cp <= 0x215F)
            || ($cp >= 0x2190 && $cp <= 0x21FF)
            || ($cp >= 0x2600 && $cp <= 0x27BF)
            || ($cp >= 0xFB00 && $cp <= 0xFB06)
            || $cp === 0xFE0F || $cp === 0xFEFF || $cp === 0xFFFD
            || ($cp >= 0x1F000 && $cp <= 0x1FAFF);
    }

    /** @return array{0: string, 1: int} */
    private static function stripMarkdown(string $text): array
    {
        $count = 0;

        foreach (self::MARKDOWN_RULES as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text, -1, $changes) ?? $text;
            $count += $changes;
        }

        return [$text, $count];
    }

    private static function escapedHtmlPattern(): string
    {
        return '/&amp;(?:#\d{2,6}|#x[0-9a-f]{2,5}|[a-z][a-z0-9]{1,7});|&lt;\/?(?:p|br|a|strong|em|b|i|u|ul|ol|li|h[1-6]|span|div)(?![\p{L}\p{N}_])(?:(?!&gt;)[^<>\n]){0,200}&gt;/iu';
    }

    private static function literalHtmlPattern(): string
    {
        return '/&(?:#\d{2,6}|#x[0-9a-f]{2,5}|[a-z][a-z0-9]{1,7});|<\/?(?:p|br|a|strong|em|b|i|u|ul|ol|li|h[1-6]|span|div)(?![\p{L}\p{N}_])[^<>\n]{0,200}>/iu';
    }

    private static function fixEscapedHtml(string $match, string $format): string
    {
        if ($format === self::FORMAT_HTML) {
            // One level of escaping too many: show the entity or tag as intended.
            return str_starts_with($match, '&amp;')
                ? '&'.substr($match, 5)
                : strtr($match, ['&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&#39;' => "'", '&apos;' => "'", '&amp;' => '&']);
        }

        if ($match[0] === '&') {
            return self::decodeEntity($match) ?? $match;
        }

        // Plain text shows tags literally; keep the line breaks they meant.
        return preg_match('/^<br(?![\p{L}\p{N}_])/iu', $match) ? "\n" : (preg_match('/^<\/(?:p|div|li|h[1-6])(?![\p{L}\p{N}_])/iu', $match) ? "\n\n" : '');
    }

    // ─── Text helpers ────────────────────────────────────────────────────────

    /** @return list<string> text between tags (HTML) or the whole text */
    private static function textSegments(string $content, string $format): array
    {
        if ($format !== self::FORMAT_HTML) {
            return [$content];
        }

        $parts = preg_split('/(<[^>]*>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$content];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== '' && $part[0] !== '<'));
    }

    /** @param callable(string): string $callback */
    private static function mapSegments(string $content, string $format, callable $callback): string
    {
        if ($format !== self::FORMAT_HTML) {
            return $callback($content);
        }

        $parts = preg_split('/(<[^>]*>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$content];

        return implode('', array_map(
            static fn (string $part): string => ($part === '' || $part[0] === '<') ? $part : $callback($part),
            $parts
        ));
    }

    /** Turn Latin-1/Windows-1252 entities into characters so garbled runs can be read. */
    private static function decodeLatinEntities(string $text): string
    {
        if (! str_contains($text, '&')) {
            return $text;
        }

        return preg_replace_callback('/&(?:#(\d{2,6})|#x([0-9a-f]{2,5})|([a-z][a-z0-9]{1,7}));/i', function (array $m): string {
            $decoded = self::decodeEntity($m[0]);
            if ($decoded === null) {
                return $m[0];
            }
            $cp = mb_ord($decoded, 'UTF-8');

            return ($cp >= 0x80 && $cp <= 0xFF) || isset(self::CP1252[$cp]) ? $decoded : $m[0];
        }, $text) ?? $text;
    }

    private static function decodeEntity(string $entity): ?string
    {
        if (preg_match('/^&#(\d+);$/', $entity, $m)) {
            $cp = (int) $m[1];
        } elseif (preg_match('/^&#x([0-9a-f]+);$/i', $entity, $m)) {
            $cp = (int) hexdec($m[1]);
        } elseif (preg_match('/^&([a-z][a-z0-9]*);$/i', $entity, $m)) {
            $name = $m[1];
            $latin = array_flip(explode(' ', self::LATIN1_ENTITIES));
            $basic = ['amp' => 0x26, 'lt' => 0x3C, 'gt' => 0x3E, 'quot' => 0x22, 'apos' => 0x27, 'zwsp' => 0x200B, 'zwj' => 0x200D, 'zwnj' => 0x200C, 'lrm' => 0x200E, 'rlm' => 0x200F];
            $cp = $latin[$name] ?? null;
            $cp = $cp !== null ? 0xA0 + $cp : (self::CP1252_ENTITIES[$name] ?? $basic[$name] ?? null);
            if ($cp === null) {
                return null;
            }
        } else {
            return null;
        }

        return ($cp > 0 && $cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF)) ? mb_chr($cp, 'UTF-8') : null;
    }

    private static function invisibleName(string $char): string
    {
        $cp = mb_ord($char, 'UTF-8');

        return match (true) {
            $cp === 0xAD => 'Soft hyphen',
            $cp === 0x200B => 'Zero-width space',
            $cp === 0x200C, $cp === 0x200D => 'Zero-width joiner',
            $cp === 0x200E, $cp === 0x200F, ($cp >= 0x202A && $cp <= 0x202E), ($cp >= 0x2066 && $cp <= 0x2069) => 'Text direction mark',
            $cp === 0xFEFF => 'Byte-order mark',
            ($cp >= 0x2060 && $cp <= 0x2064) => 'Word joiner',
            default => 'Control character',
        };
    }

    private static function excerpt(string $text, int $length = 80): string
    {
        $text = trim(preg_replace('/[ \t\n\r\f\v]+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
    }

    /** @param list<array{found: string, fixed: ?string}> $samples */
    private static function addSample(array &$samples, string $found, ?string $fixed): void
    {
        if (count($samples) >= self::MAX_SAMPLES) {
            return;
        }
        foreach ($samples as $sample) {
            if ($sample['found'] === $found) {
                return;
            }
        }
        $samples[] = ['found' => $found, 'fixed' => $fixed];
    }
}
