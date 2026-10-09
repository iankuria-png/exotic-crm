<?php

namespace App\Services\Monetization;

use Illuminate\Validation\ValidationException;

/** Only registered origins and their exact www alias; pin public DNS for every request. */
class WordPressDestination
{
    public function origin(string $url): string
    {
        $p = parse_url($url);
        $local = app()->environment('local', 'testing') && isset($p['host']) && (str_ends_with($p['host'], '.local') || str_ends_with($p['host'], '.test'));
        if (! $p || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || ! in_array($p['scheme'] ?? '', $local ? ['http', 'https'] : ['https'], true) || (isset($p['port']) && ! in_array($p['port'], [80, 443], true)) || ! filter_var($p['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw ValidationException::withMessages(['connection' => 'Use this market’s public HTTPS WordPress address in Settings → Markets. Internal addresses, credentials and custom ports are not allowed.']);
        }

        $port = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);

        return strtolower($p['scheme'].'://'.$p['host']).($port === ($p['scheme'] === 'https' ? 443 : 80) ? '' : ':'.$port);
    }

    public function canonical(string $registered, string $reported): string
    {
        $origin = $this->origin($reported);
        $base = $this->origin($registered);
        $a = parse_url($base);
        $b = parse_url($origin);
        if ($a['scheme'] !== $b['scheme'] || ($a['port'] ?? null) !== ($b['port'] ?? null) || preg_replace('/^www\./', '', $a['host']) !== preg_replace('/^www\./', '', $b['host']) || isset(parse_url($reported)['query'])) {
            throw ValidationException::withMessages(['connection' => 'WordPress reported an address outside this market’s registered origin. Review Settings → Markets; automatic repair only permits the same site’s www address.']);
        }
        $path = rtrim((string) parse_url($reported, PHP_URL_PATH), '/');
        if (! in_array($path, ['/wp-json', '/wp-json/exotic-crm-sync/v1'], true)) {
            throw ValidationException::withMessages(['connection' => 'WordPress reported an unsupported REST path. Review this market’s WordPress API address in Settings → Markets.']);
        }
        $this->options($origin);

        return $origin.'/wp-json/exotic-crm-sync/v1';
    }

    public function base(string $url): string
    {
        return $this->canonical($url, $this->origin($url).'/wp-json/exotic-crm-sync/v1');
    }

    public function options(string $url): array
    {
        $origin = $this->origin($url);
        $host = parse_url($origin, PHP_URL_HOST);
        if (app()->environment('local', 'testing') && (str_ends_with($host, '.local') || str_ends_with($host, '.test'))) {
            return ['allow_redirects' => false];
        }
        $addresses = $this->addresses($host);
        if (! $addresses || array_filter($addresses, fn ($ip) => ! $this->publicIp($ip))) {
            throw ValidationException::withMessages(['connection' => 'This WordPress address does not resolve exclusively to public IPs. Ask the site administrator to correct its public DNS, then re-check.']);
        }
        if (! defined('CURLOPT_RESOLVE')) {
            throw ValidationException::withMessages(['connection' => 'CRM needs PHP cURL to verify public WordPress destinations safely. Enable cURL on the CRM host.']);
        }
        $port = parse_url($origin, PHP_URL_PORT) ?: 443;
        $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return ['allow_redirects' => false, 'curl' => [CURLOPT_PROXY => '', CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]];
    }

    private function publicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            // Public global unicast only; excludes mapped IPv4, translation and tunnel space.
            $bytes = inet_pton($ip);

            return $bytes && (ord($bytes[0]) & 0xE0) === 0x20 && ! preg_match('/^(2001:(db8|0):|2002:)/i', $ip);
        }
        $number = ip2long($ip);
        foreach (['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4'] as $range) {
            [$network, $bits] = explode('/', $range);
            $mask = -1 << (32 - (int) $bits);
            if (($number & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }

        return true;
    }

    protected function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records))));
    }
}
