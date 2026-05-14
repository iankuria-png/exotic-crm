<?php

namespace App\Services;

class CurrencyCanonicalizer
{
    private const SIMPLE_ALIASES = [
        'KSH' => 'KES',
        'KSHS' => 'KES',
        'KES.' => 'KES',
        'TSH' => 'TZS',
        'TSHS' => 'TZS',
        'TZS.' => 'TZS',
        'NAIRA' => 'NGN',
        'CEDI' => 'GHS',
        'USD$' => 'USD',
        '$' => 'USD',
    ];

    private const XOF_COUNTRIES = [
        'BEN',
        'BENIN',
        'BENIN REPUBLIC',
        'BFA',
        'BURKINA FASO',
        'CIV',
        'COTE D IVOIRE',
        "COTE D'IVOIRE",
        'CÔTE D IVOIRE',
        "CÔTE D'IVOIRE",
        'GNB',
        'GUINEA-BISSAU',
        'GUINEA BISSAU',
        'IVORY COAST',
        'MLI',
        'MALI',
        'NER',
        'NIGER',
        'SEN',
        'SENEGAL',
        'TGO',
        'TOGO',
    ];

    private const XAF_COUNTRIES = [
        'CMR',
        'CAMEROON',
        'CAF',
        'CENTRAL AFRICAN REPUBLIC',
        'TCD',
        'CHAD',
        'CONGO',
        'GNQ',
        'EQUATORIAL GUINEA',
        'GAB',
        'GABON',
        'COG',
        'REPUBLIC OF THE CONGO',
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string|null, original: string, status: string, reason: string|null}
     */
    public function resolve(mixed $currency, array $context = []): array
    {
        $original = strtoupper(trim((string) $currency));
        $code = $this->clean($original);

        if ($code === '') {
            return [
                'code' => null,
                'original' => $original,
                'status' => 'missing',
                'reason' => 'Currency code is blank.',
            ];
        }

        if (isset(self::SIMPLE_ALIASES[$code])) {
            return [
                'code' => self::SIMPLE_ALIASES[$code],
                'original' => $original,
                'status' => 'canonicalized',
                'reason' => "{$code} is a display alias.",
            ];
        }

        if ($code === 'CFA' || $code === 'FCFA') {
            $zone = $this->resolveCfaZone($context);

            if ($zone) {
                return [
                    'code' => $zone,
                    'original' => $original,
                    'status' => 'canonicalized',
                    'reason' => "CFA resolved from platform/country context.",
                ];
            }

            return [
                'code' => null,
                'original' => $original,
                'status' => 'ambiguous',
                'reason' => 'CFA is ambiguous; use XOF or XAF.',
            ];
        }

        if (!$this->looksLikeIsoCode($code)) {
            return [
                'code' => null,
                'original' => $original,
                'status' => 'unsupported',
                'reason' => "{$code} is not a supported ISO-style currency code.",
            ];
        }

        return [
            'code' => $code,
            'original' => $original,
            'status' => 'canonical',
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveCfaZone(array $context): ?string
    {
        $hints = [
            $context['platform_country'] ?? null,
            $context['country'] ?? null,
            $context['platform_name'] ?? null,
            $context['name'] ?? null,
        ];

        foreach ($hints as $hint) {
            $normalized = $this->normalizeHint($hint);
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, self::XOF_COUNTRIES, true)) {
                return 'XOF';
            }

            if (in_array($normalized, self::XAF_COUNTRIES, true)) {
                return 'XAF';
            }
        }

        return null;
    }

    private function clean(string $currency): string
    {
        return strtoupper(trim(str_replace([' ', ','], '', $currency)));
    }

    private function normalizeHint(mixed $value): string
    {
        $hint = mb_strtoupper(trim((string) $value), 'UTF-8');
        $hint = str_replace(['’', '`', '.', ','], ["'", "'", '', ''], $hint);

        // Strip diacritics so accented country names match the ASCII list entries.
        // NFD decomposition separates base letters from combining marks; we then drop the marks.
        if (class_exists('Normalizer')) {
            $nfd = \Normalizer::normalize($hint, \Normalizer::FORM_D);
            if ($nfd !== false) {
                $hint = (string) preg_replace('/\p{Mn}/u', '', $nfd);
            }
        }

        $hint = preg_replace('/\s+/', ' ', $hint) ?: '';

        return $hint;
    }

    private function looksLikeIsoCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Z]{3}$/', $code);
    }
}
