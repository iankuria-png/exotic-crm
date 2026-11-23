<?php

namespace App\Services\Scraper;

use App\Services\Scraper\Fetch\FetchResult;

/**
 * Explains why a page cannot be scraped (or only partly), in plain words, with the
 * overrides that apply. Severity: blocking | warning | info.
 */
class BlockerDetector
{
    /**
     * @param  array{allowed?: bool, policy?: string, message?: string}|null  $robots
     * @param  array{rows?: int, phones?: int, links?: int}  $extraction
     * @param  array<int, array{key: string}>  $tech
     * @return array<int, array{key: string, severity: string, title: string, detail: string, overrides: array<int, string>}>
     */
    public function detect(FetchResult $result, ?HtmlDocument $doc, ?array $robots, array $extraction, array $tech, bool $hasSitemap = false): array
    {
        $blockers = [];
        $add = function (string $key, string $severity, string $title, string $detail, array $overrides = []) use (&$blockers): void {
            $blockers[$key] = compact('key', 'severity', 'title', 'detail', 'overrides');
        };
        $techKeys = array_column($tech, 'key');
        $html = $result->html;
        $lower = strtolower(substr($html, 0, 200000));
        $status = $result->status;
        $captureRoutes = $hasSitemap ? ['browser_capture', 'upload_html', 'sitemap_route'] : ['browser_capture', 'upload_html'];

        if ($robots !== null && ($robots['allowed'] ?? true) === false) {
            $add('robots_disallow', 'blocking', 'robots.txt disallows this page', (string) ($robots['message'] ?? 'The site asks automated tools not to read this path.'), ['browser_capture', 'upload_html']);
        }

        if ($result->error !== null && $status === 0) {
            $add('network_error', 'blocking', 'The page could not be reached', $result->error, ['check_url', 'browser_capture']);

            return array_values($blockers);
        }

        $challenge = str_contains(strtolower($result->header('cf-mitigated')), 'challenge')
            || (in_array($status, [403, 503], true) && (str_contains($lower, 'cf-chl') || str_contains($lower, 'challenge-platform') || str_contains($lower, 'just a moment') || str_contains($lower, 'checking your browser')));
        if ($challenge) {
            $add('cloudflare_challenge', 'blocking', 'Cloudflare is challenging automated visitors', 'The site answers with a "checking your browser" page (HTTP '.$status.'). The CRM will not try to solve it; a person can pass it in their own browser.', $captureRoutes);
        } elseif (in_array($status, [403, 503], true) && (str_contains($lower, 'sucuri') || str_contains($lower, 'ddos-guard') || str_contains($lower, 'access denied') || in_array('akamai', $techKeys, true))) {
            $add('waf_block', 'blocking', 'A firewall blocked the request', 'The site\'s firewall refused the CRM (HTTP '.$status.').', $captureRoutes);
        } elseif ($status === 429) {
            $add('rate_limited', 'warning', 'The site is rate limiting', 'Too many requests (HTTP 429). Slow down before trying again.', ['slow_down', 'browser_capture']);
        } elseif ($status === 401 || $status === 403) {
            $add('access_denied', 'blocking', 'Access denied', 'The site refused the request (HTTP '.$status.').', ['custom_headers', 'browser_capture', 'upload_html']);
        } elseif ($status === 404 || $status === 410) {
            $add('not_found', 'blocking', 'Page not found', 'The address returns HTTP '.$status.'. The listing may have moved.', ['check_url']);
        } elseif ($status >= 500) {
            $add('server_error', 'warning', 'The site is having problems', 'HTTP '.$status.' from the site. Try again later.', ['slow_down']);
        } elseif ($result->error !== null) {
            $add('unsupported_content', 'blocking', 'Not a web page', $result->error, ['check_url']);
        }

        if (isset($blockers['cloudflare_challenge']) || isset($blockers['waf_block']) || isset($blockers['not_found'])) {
            return array_values($blockers);
        }

        $rows = (int) ($extraction['rows'] ?? 0);
        $phones = (int) ($extraction['phones'] ?? 0);
        $links = (int) ($extraction['links'] ?? 0);
        $text = $doc?->bodyText() ?? '';
        $textLength = mb_strlen($text);

        $captcha = array_intersect($techKeys, ['recaptcha', 'hcaptcha', 'turnstile']);
        if ($captcha && $rows === 0 && $textLength < 1500) {
            $add('captcha', 'blocking', 'A CAPTCHA guards the page', 'The page shows a CAPTCHA before content. The CRM will not solve it.', $captureRoutes);
        }

        if ($doc && $rows === 0 && $doc->count('input[type="password"]') > 0 && preg_match('/\b(log ?in|sign ?in)\b/i', $text)) {
            $add('login_wall', 'blocking', 'Login required', 'Listings appear only after signing in.', ['browser_capture', 'upload_html']);
        }

        $gatePath = (bool) preg_match('#/(verification|verify|age[-_]?(gate|check|verification)?|disclaimer|confirm(-age)?|enter)/?$#i', (string) parse_url($result->finalUrl, PHP_URL_PATH));
        $dobForm = $doc && $doc->count('select[name*="dob"], select[name*="birth"], input[name*="birth"], input[name*="dob"], button[name*="adult"], input[name*="adult"]') > 0;
        if ($rows === 0 && ($gatePath || $dobForm)) {
            $add('age_gate', 'blocking', 'An age check stands in front of the listings', 'The site sends visitors to an age-verification form first ('.((string) parse_url($result->finalUrl, PHP_URL_PATH) ?: '/').'). A person can pass it in their own browser; a saved consent cookie may also work.', ['browser_capture', 'upload_html', 'custom_headers']);
        } elseif ($rows < 3 && preg_match('/\b(18\+|over 18|18 years|adults? only|age verification|i am (at least )?18|enter the site)\b/i', mb_substr($text, 0, 4000))) {
            $add('age_gate', 'warning', 'Age notice in front of the listings', 'The site shows an age confirmation. Adding its consent cookie usually reveals the listings.', ['custom_headers', 'browser_capture']);
        }

        $spaShell = $doc && ($doc->count('#__next:empty, #root:empty, #app:empty') > 0 || ($textLength < 400 && $doc->count('script') >= 5));
        if ($rows === 0 && ($spaShell || array_intersect($techKeys, ['nextjs', 'nuxt', 'angular']) && $textLength < 1500)) {
            $add('js_required', 'blocking', 'Listings are built by JavaScript', 'The raw page has almost no content; listings load in the browser.', ['render_worker', 'browser_capture', 'upload_html']);
        }

        $requestedPath = rtrim((string) parse_url($result->requestedUrl, PHP_URL_PATH), '/');
        $finalPath = rtrim((string) parse_url($result->finalUrl, PHP_URL_PATH), '/');
        if ($requestedPath !== '' && $finalPath === '' && $result->finalUrl !== $result->requestedUrl) {
            $add('redirected_home', 'warning', 'Redirected to the home page', 'The listing address now redirects to the home page (it may depend on location).', ['check_url', 'custom_headers']);
        }

        if (! isset($blockers['js_required']) && ! isset($blockers['login_wall']) && ! isset($blockers['captcha']) && ! isset($blockers['age_gate']) && $status >= 200 && $status < 300) {
            if ($rows === 0 && $phones === 0 && $links === 0) {
                $add('no_listings', 'warning', 'No listings recognised', 'The page loaded but no profile cards, phone or profile links were found.', ['manual_selectors', 'check_url', 'browser_capture']);
            } elseif ($rows > 0 && $phones === 0) {
                $add('phones_hidden', 'warning', 'Phone numbers are not on the listing page', 'Cards were found without phone numbers; they are probably on each profile page.', ['follow_profiles', 'render_worker']);
            } elseif ($rows > 0 && $rows < 3) {
                $add('few_listings', 'warning', 'Only a few listings found', 'Only '.$rows.' card(s) matched. The template may be stale or the page shows another location.', ['manual_selectors', 'check_url']);
            }
        }

        if ($result->truncated) {
            $add('truncated', 'info', 'Page was very large', 'Only the first part of the page was read.', []);
        }

        return array_values($blockers);
    }

    public static function verdict(array $blockers, int $rows): string
    {
        $severities = array_column($blockers, 'severity');
        if (in_array('blocking', $severities, true)) {
            return 'blocked';
        }
        if (in_array('warning', $severities, true) || $rows === 0) {
            return 'needs_config';
        }

        return 'ready';
    }
}
