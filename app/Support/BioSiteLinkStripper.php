<?php

namespace App\Support;

/**
 * Unwraps a bio's internal site links while leaving contact links alone.
 *
 * The SEO engine injects internal links as ROOT-RELATIVE hrefs — "/bdsm-escorts/",
 * "/incall/", "/escorts-from/kampala/" — because they are written for the site
 * that will render them. Copy that bio to a PBN and every one of those resolves
 * against the new host, where the page usually does not exist: the reader hits a
 * 404 and the destination site accumulates dead internal links.
 *
 * Absolute links back to the source market are worse for a PBN than a 404: they
 * hand a crawler an explicit edge between the two sites, which is exactly the
 * relationship a private network exists to avoid. Those are unwrapped too.
 *
 * Contact links survive. tel:, mailto: and WhatsApp or Telegram deep links are
 * how the advertiser gets the enquiry, and they work from any host.
 */
class BioSiteLinkStripper
{
    /** Schemes that reach the advertiser rather than a page on some website. */
    private const CONTACT_SCHEME_PATTERN = '#^\s*(?:tel:|sms:|mailto:|viber:|skype:|callto:|whatsapp:)#i';

    private const CONTACT_HOST_PATTERN = '#^\s*https?://(?:api\.)?(?:wa\.me|whatsapp\.com|t\.me|telegram\.me|m\.me)#i';

    /**
     * @param  array<int, string>  $sourceHosts  Hosts whose absolute links are also internal,
     *                                           e.g. the source market's own domain.
     */
    public static function strip(string $html, array $sourceHosts = []): string
    {
        if (trim($html) === '' || stripos($html, '<a') === false) {
            return $html;
        }

        $hosts = array_values(array_filter(array_map(
            static fn ($host): string => strtolower(ltrim(trim((string) $host), '.')),
            $sourceHosts
        )));

        return preg_replace_callback(
            '#<a\b([^>]*)>(.*?)</a>#is',
            static function (array $match) use ($hosts): string {
                $href = self::hrefOf($match[1]);

                // An anchor with no href is not a link anywhere; leave it be.
                if ($href === null) {
                    return $match[0];
                }

                return self::isInternal($href, $hosts) ? $match[2] : $match[0];
            },
            $html
        ) ?? $html;
    }

    private static function hrefOf(string $attributes): ?string
    {
        if (preg_match('#href\s*=\s*(["\'])(.*?)\1#is', $attributes, $found) === 1) {
            return html_entity_decode($found[2], ENT_QUOTES, 'UTF-8');
        }

        return null;
    }

    /**
     * @param  array<int, string>  $hosts
     */
    private static function isInternal(string $href, array $hosts): bool
    {
        $href = trim($href);
        if ($href === '' || $href === '#') {
            return true;
        }

        if (preg_match(self::CONTACT_SCHEME_PATTERN, $href) === 1
            || preg_match(self::CONTACT_HOST_PATTERN, $href) === 1) {
            return false;
        }

        // Root-relative and relative links: written for whichever site renders
        // them, which is the whole problem.
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) && !str_starts_with($href, '//')) {
            return true;
        }

        $host = strtolower((string) parse_url(str_starts_with($href, '//') ? 'https:' . $href : $href, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ($hosts as $sourceHost) {
            if ($host === $sourceHost || str_ends_with($host, '.' . $sourceHost)) {
                return true;
            }
        }

        return false;
    }
}
