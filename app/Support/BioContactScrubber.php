<?php

namespace App\Support;

/**
 * Redacts contact details from profile bio HTML.
 *
 * Contact CTAs (phone/WhatsApp/Telegram buttons) are hidden structurally by the
 * website theme when a profile is lifecycle-restricted, but an advertiser can
 * paste a number straight into the bio text — which would leave a working advert
 * running on a lapsed subscription. This class removes those details from the
 * text itself.
 *
 * The caller is responsible for preserving the original bio (see
 * ProfileBioScrubService) so a renewal restores it verbatim.
 *
 * HTML tags are never modified — only text nodes and whole contact links — so
 * SEO bio links, images and formatting survive intact. The placeholder is plain
 * text so it stays readable after tag-stripping (excerpts, meta descriptions).
 */
class BioContactScrubber
{
    public const PLACEHOLDER = '[contact hidden]';

    /** A digit run must reach this many digits to be treated as a phone number. */
    private const MIN_PHONE_DIGITS = 7;

    /** Whole anchors whose href points at a contact channel. */
    private const CONTACT_LINK_PATTERN = '#<a\b[^>]*href\s*=\s*["\'](?:tel:|sms:|mailto:|viber:|skype:|callto:|https?://(?:api\.)?(?:wa\.me|whatsapp\.com|t\.me|telegram\.me))[^"\']*["\'][^>]*>.*?</a>#is';

    /** Messenger links typed as plain text. */
    private const SERVICE_URL_PATTERN = '#\b(?:https?://)?(?:api\.)?(?:wa\.me|whatsapp\.com|t\.me|telegram\.me)/\S*#i';

    private const EMAIL_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    /** Social handles (@name), applied after emails so addresses are already gone. */
    private const HANDLE_PATTERN = '/(?<![\w@])@[A-Za-z0-9._]{3,}/';

    /**
     * Loose digit-run match (covers spaced, dotted, dashed and bracketed
     * obfuscation). Confirmed by digit count in the callback so heights, ages,
     * prices and "24/7" are left alone.
     */
    private const PHONE_PATTERN = '/\+?\d[\d\s().\-\/]{5,}\d/';

    /**
     * Redact contact details.
     *
     * @return array{clean: string, redactions: int, kinds: array<string, int>}
     */
    public static function scrub(?string $html): array
    {
        return self::apply((string) $html);
    }

    /**
     * Report what would be redacted without changing anything. Powers dry-runs
     * and "this bio contains contact details" moderation flags.
     *
     * @return array{redactions: int, kinds: array<string, int>}
     */
    public static function detect(?string $html): array
    {
        $result = self::apply((string) $html);
        unset($result['clean']);

        return $result;
    }

    public static function hasContactDetails(?string $html): bool
    {
        return self::detect($html)['redactions'] > 0;
    }

    /**
     * @return array{clean: string, redactions: int, kinds: array<string, int>}
     */
    private static function apply(string $html): array
    {
        if (trim($html) === '') {
            return ['clean' => $html, 'redactions' => 0, 'kinds' => []];
        }

        $kinds = [];
        $count = 0;

        $redact = static function (string $kind) use (&$kinds, &$count): string {
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            $count++;

            return self::PLACEHOLDER;
        };

        // 1. Contact links, anchor text included — otherwise a tel: href stays
        //    clickable even once the visible number has been redacted.
        $html = preg_replace_callback(
            self::CONTACT_LINK_PATTERN,
            static fn (): string => $redact('link'),
            $html
        ) ?? $html;

        // 2. Text nodes only. Splitting on tags keeps hrefs, classes and image
        //    URLs (which contain long digit runs) safe from the phone pattern.
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return ['clean' => $html, 'redactions' => $count, 'kinds' => $kinds];
        }

        foreach ($parts as $index => $part) {
            if ($part === '' || $part[0] === '<') {
                continue;
            }

            $part = preg_replace_callback(self::SERVICE_URL_PATTERN, static fn (): string => $redact('messenger'), $part) ?? $part;
            $part = preg_replace_callback(self::EMAIL_PATTERN, static fn (): string => $redact('email'), $part) ?? $part;
            $part = preg_replace_callback(self::HANDLE_PATTERN, static fn (): string => $redact('handle'), $part) ?? $part;
            $part = preg_replace_callback(
                self::PHONE_PATTERN,
                static function (array $match) use ($redact): string {
                    $digits = preg_replace('/\D/', '', $match[0]);

                    return strlen((string) $digits) >= self::MIN_PHONE_DIGITS
                        ? $redact('phone')
                        : $match[0];
                },
                $part
            ) ?? $part;

            $parts[$index] = $part;
        }

        return ['clean' => implode('', $parts), 'redactions' => $count, 'kinds' => $kinds];
    }
}
