<?php

namespace App\Services\DbScanner\Evidence;

/**
 * Turns matched market data into safe, inert evidence.
 *
 * Output is plain text with credentials, tokens, query values, emails and
 * phone numbers redacted, capped at 200 characters. HTML escaping happens
 * once, at the output boundary (React text nodes / CSV neutralisation); this
 * class never produces markup and never stores a full payload.
 */
class EvidenceSanitizer
{
    public function __construct(private readonly int $maxChars = 200) {}

    public function excerpt(string $text, ?int $offset = null, int $radius = 80): string
    {
        $text = $this->toUtf8($text);
        if ($offset !== null) {
            $charOffset = mb_strlen(substr($text, 0, max(0, $offset)));
            $start = max(0, $charOffset - $radius);
            $slice = mb_substr($text, $start, $radius * 2 + 40);
            $text = ($start > 0 ? '…' : '').$slice;
        }

        return $this->clean($text);
    }

    public function clean(string $text): string
    {
        $text = $this->toUtf8($text);
        // Strip control characters (keeps tabs/newlines, which become spaces).
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = $this->redact($text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $this->maxChars ? mb_substr($text, 0, $this->maxChars).'…' : $text;
    }

    public function redact(string $text): string
    {
        // URL credentials: scheme://user:pass@host
        $text = preg_replace('#([a-z][a-z0-9+.-]*://)[^/\s:@"\'<>]+:[^/\s@"\'<>]+@#i', '$1[redacted]@', $text) ?? $text;

        // Query strings keep their keys but lose their values.
        $text = preg_replace_callback('#(https?://[^\s"\'<>?]+)\?([^\s"\'<>]*)#i', function ($m) {
            $pairs = [];
            foreach (explode('&', html_entity_decode($m[2])) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $key = explode('=', $pair, 2)[0];
                $pairs[] = mb_substr($key, 0, 40).'=[redacted]';
            }

            return $m[1].($pairs ? '?'.implode('&', array_slice($pairs, 0, 6)) : '');
        }, $text) ?? $text;

        // JWTs and long opaque tokens.
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', '[token]', $text) ?? $text;
        $text = preg_replace('/\b(sk|pk|rk|ghp|gho|xox[abp]|AKIA|AIza)[A-Za-z0-9_\-]{12,}/', '[token]', $text) ?? $text;
        $text = preg_replace('/\b[a-f0-9]{32,}\b/i', '[hex]', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9+\/])[A-Za-z0-9+\/]{60,}={0,2}/', '[base64]', $text) ?? $text;

        // Personal data.
        $text = preg_replace_callback('/([A-Za-z0-9._%+-]{1,64})@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', fn ($m) => self::maskEmail($m[0]), $text) ?? $text;
        $text = preg_replace('/(?<!\d)\+?\d[\d\s().-]{7,}\d(?!\d)/', '[phone]', $text) ?? $text;

        return $text;
    }

    public static function maskEmail(?string $email): string
    {
        $email = (string) $email;
        if (! str_contains($email, '@')) {
            return $email === '' ? '' : '***';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.strtolower($domain);
    }

    /**
     * Neutralise spreadsheet formula prefixes for CSV export.
     */
    public static function csvSafe(mixed $value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function toUtf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }
}
