<?php

namespace App\Services\Scraper\Fetch;

use InvalidArgumentException;

/**
 * Stops staff-entered URLs from reaching internal addresses (SSRF).
 * Unresolvable public-looking hosts are allowed; the fetch will fail on its own.
 */
class UrlSafety
{
    /** @var callable(string): array<int, string> */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = [];
            foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                $ips[] = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            }
            if ($ips === []) {
                $v4 = @gethostbynamel($host);
                $ips = is_array($v4) ? $v4 : [];
            }

            return array_values(array_filter($ips));
        };
    }

    public function assertSafe(string $url): void
    {
        $reason = $this->unsafeReason($url);
        if ($reason !== null) {
            throw new InvalidArgumentException($reason);
        }
    }

    public function unsafeReason(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Only http and https addresses can be scraped.';
        }
        if ($host === '') {
            return 'The address has no host name.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Addresses with embedded credentials are not allowed.';
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443, 8080, 8443], true)) {
            return 'Only standard web ports can be scraped.';
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return 'Internal host names cannot be scraped.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return 'The address resolves to a private or reserved network.';
            }
        }

        return null;
    }

    public function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // CGNAT 100.64/10 and IPv4-mapped/ULA ranges that filter_var lets through.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            return !($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
        }
        $lower = strtolower($ip);

        return !str_starts_with($lower, 'fc') && !str_starts_with($lower, 'fd') && !str_starts_with($lower, 'fe80') && !str_starts_with($lower, '::ffff:');
    }
}
