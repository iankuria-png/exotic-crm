<?php

namespace App\Services\Scraper;

/**
 * Phone / WhatsApp / email parsing shared by list and profile extraction. Phone
 * normalisation follows the CRM convention: digits only, local leading 0 replaced by
 * the market prefix, 10–15 digits.
 */
class ContactParser
{
    public static function phoneFromHref(string $href): ?string
    {
        $href = trim(html_entity_decode($href));
        if (stripos($href, 'tel:') === 0) {
            return rawurldecode(substr($href, 4));
        }
        if (preg_match('#wa\.me/\+?(\d{7,15})#i', $href, $m)) {
            return $m[1];
        }
        if (preg_match('#whatsapp\.com/send/?\?(?:[^"\'\s]*&)?phone=\+?(\d{7,15})#i', $href, $m)) {
            return $m[1];
        }
        if (preg_match('#^whatsapp://send\?(?:[^"\'\s]*&)?phone=\+?(\d{7,15})#i', $href, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function phoneFromText(string $text): ?string
    {
        if (preg_match('/(?<![\d])(\+?\d[\d\s\-().]{7,17}\d)(?![\d])/', $text, $m)) {
            $digits = preg_replace('/\D/', '', $m[1]);
            if (strlen((string) $digits) >= 9 && strlen((string) $digits) <= 15) {
                return $m[1];
            }
        }

        return null;
    }

    public static function emailFromText(string $text): ?string
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m) ? $m[0] : null;
    }

    public static function normalizePhone(?string $phone, string $prefix): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = ltrim((string) preg_replace('/[^\d+]/', '', $phone), '+');
        $digits = (string) preg_replace('/\D/', '', $digits);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $digits = $prefix . substr($digits, 1);
        }

        return preg_match('/^\d{10,15}$/', $digits) ? $digits : null;
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        if (str_starts_with($email, 'mailto:')) {
            $email = substr($email, 7);
        }
        $email = explode('?', $email)[0];

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function normalizeName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5)));
        // Multibyte-safe edge trim (byte-wise trim() would split UTF-8 sequences, e.g. emoji).
        $name = (string) preg_replace('/^[\s\-–—|•·]+|[\s\-–—|•·]+$/u', '', $name);
        if ($name === '' || mb_strlen($name) < 2) {
            return null;
        }

        return mb_substr(mb_scrub($name, 'UTF-8'), 0, 120);
    }

    public static function ageFromText(?string $text): ?int
    {
        if ($text !== null && preg_match('/\b(1[89]|[2-6]\d)\b/', $text, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
