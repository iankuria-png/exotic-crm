<?php

namespace App\Services\DbScanner\Rules;

use App\Services\DbScanner\Evidence\EvidenceSanitizer;
use App\Services\DbScanner\Malware\ContentAnalysis;
use App\Services\DbScanner\Malware\HostContext;
use App\Services\DbScanner\Surfaces\SurfaceRegistry;

/**
 * The fixed registry of row matchers. Pack rules name a matcher ID; there is
 * no way to register a class or pattern from rule data in phase 1.
 *
 * Confidence vocabulary (malware rules):
 *   confirmed  — a vetted exact indicator matched
 *   strong     — behaviour, remote target and execution context align
 *   needs_review — a single generic signal; never presented as malware
 */
class RowMatchers
{
    public const IDS = [
        'webshell', 'remote_loader', 'redirect_overlay', 'obfuscated_payload', 'credential_capture', 'known_ioc',
        'banner_exchange', 'script_injection', 'unexpected_script', 'spam_lexicon', 'hidden_text', 'seo_meta_injection', 'shortener_links',
        'comment_links', 'outbound_domains', 'malformed_links', 'retired_parameters', 'ioc_option_names',
        'lookalike_options', 'encoded_payloads', 'serialized_objects', 'code_snippet_stores',
        // Tallied by SurfaceScanner from excluded secret names; no value matcher.
        'autoloaded_secret_options',
    ];

    private const PHP_EXEC = '/(?<![\w>$-])(eval|assert|system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec|create_function)\s*\(/i';

    private const PHP_INPUT = '/\$_(POST|GET|REQUEST|COOKIE|FILES)\b|\$_SERVER\s*\[\s*[\'"]HTTP_/';

    private const PHP_DECODE = '/(?<![\w>$-])(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|convert_uudecode)\s*\(/i';

    private const WEBSHELL_MARKERS = '/FilesMan|\bWSO\s?[0-9.]+\b|c99shell|r57shell|b374k|IndoXploit|AlfaShell|ALFA_DATA|\bShell\s+Uploader\b|\$auth_pass\s*=|\bwebshell\b/i';

    private const JS_REDIRECT = '/(?:(?:window|document|top|self|parent)\s*\.\s*)?location(?:\s*\.\s*href)?\s*=\s*(["\'`]?)([^"\'`;\s)]{3,})|location\s*\.\s*(?:replace|assign)\s*\(\s*(["\'`]?)([^"\'`)\s]{3,})/i';

    private const FAKE_UPDATE = '/\b(update|upgrade)\s+(your\s+)?(browser|chrome|firefox|edge|safari)\b|\b(browser|chrome)\s+update\s+(required|available|needed)\b|fake[-_]?update|download\s+the\s+latest\s+version\s+of\s+(chrome|your\s+browser)/i';

    public function __construct(private readonly EvidenceSanitizer $sanitizer = new EvidenceSanitizer) {}

    public function run(string $matcher, string $ruleKey, RuleSet $rules, RowContext $ctx, ContentAnalysis $a, Accumulator $acc): ?Hit
    {
        return match ($matcher) {
            'webshell' => $this->webshell($ruleKey, $rules, $ctx, $a),
            'remote_loader' => $this->remoteLoader($ruleKey, $rules, $ctx, $a),
            'redirect_overlay' => $this->redirectOverlay($ruleKey, $rules, $ctx, $a),
            'obfuscated_payload' => $this->obfuscatedPayload($ruleKey, $rules, $ctx, $a),
            'credential_capture' => $this->credentialCapture($ruleKey, $rules, $ctx, $a),
            'known_ioc' => $this->knownIoc($ruleKey, $rules, $ctx, $a),
            'banner_exchange' => $this->bannerExchange($ruleKey, $rules, $ctx, $a),
            'script_injection' => $this->scriptInjection($ruleKey, $rules, $ctx, $a),
            'unexpected_script' => $this->unexpectedScript($ruleKey, $rules, $ctx, $a),
            'spam_lexicon' => $this->spamLexicon($ruleKey, $rules, $ctx, $a),
            'hidden_text' => $this->hiddenText($ruleKey, $rules, $ctx, $a),
            'seo_meta_injection' => $this->seoMetaInjection($ruleKey, $rules, $ctx, $a),
            'shortener_links' => $this->shortenerLinks($ruleKey, $rules, $ctx, $a, $acc),
            'comment_links' => $this->commentLinks($ruleKey, $ctx, $a, $acc),
            'outbound_domains' => $this->outboundDomains($ruleKey, $rules, $ctx, $a, $acc),
            'malformed_links' => $this->malformedLinks($ruleKey, $rules, $ctx, $a),
            'retired_parameters' => $this->retiredParameters($ruleKey, $rules, $ctx, $a),
            'ioc_option_names' => $this->iocOptionNames($ruleKey, $rules, $ctx),
            'lookalike_options' => $this->lookalikeOptions($ruleKey, $rules, $ctx),
            'encoded_payloads' => $this->encodedPayloads($ruleKey, $rules, $ctx, $a),
            'serialized_objects' => $this->serializedObjects($ruleKey, $rules, $ctx, $a),
            'code_snippet_stores' => $this->codeSnippetStores($ruleKey, $rules, $ctx, $a),
            default => null,
        };
    }

    // ------------------------------------------------------------------
    // Malware behaviours
    // ------------------------------------------------------------------

    private function webshell(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        $codeField = $this->isCodeStore($ctx);

        foreach ($a->layers() as $layer) {
            $t = $layer['text'];
            if (! preg_match('/<\?(php|=)/i', $t) && ! $codeField && $layer['chain'] === []) {
                continue;
            }

            $exec = preg_match(self::PHP_EXEC, $t, $em, PREG_OFFSET_CAPTURE);
            $input = preg_match(self::PHP_INPUT, $t);
            $decode = preg_match(self::PHP_DECODE, $t);
            $marker = preg_match(self::WEBSHELL_MARKERS, $t, $mm, PREG_OFFSET_CAPTURE);
            $pregE = preg_match('/preg_replace\s*\(\s*([\'"])([^\w\s\\\\]).{1,200}?\2[imsxuADSUXJ]*e[imsxuADSUXJ]*\1/s', $t);
            $remoteInclude = preg_match('/\b(include|require)(_once)?\s*\(?\s*[\'"]https?:\/\//i', $t)
                || preg_match('/(eval|assert)\s*\(\s*(file_get_contents|curl_exec)\s*\(/i', $t);

            $signals = array_keys(array_filter([
                'php_exec_sink' => $exec,
                'request_input' => $input,
                'decode_chain' => $decode,
                'webshell_marker' => $marker,
                'preg_replace_e' => $pregE,
                'remote_include' => $remoteInclude,
            ]));

            $strong = ($exec && $input) || $marker || ($decode && $exec) || $pregE || $remoteInclude;
            if ($strong) {
                $offset = $marker ? $mm[0][1] : ($exec ? $em[0][1] : null);

                return $this->hit($key, $rules, $ctx, 'Executable PHP web-shell structure stored in the database', $t, $offset, $signals, $layer['chain'], 'strong', 'backdoor');
            }

            if ($exec && preg_match('/<\?(php|=)/i', $t)) {
                return $this->hit($key, $rules, $ctx, 'PHP code with an execution function stored in the database', $t, $em[0][1], $signals, $layer['chain'], 'needs_review', 'backdoor');
            }
        }

        return null;
    }

    private function remoteLoader(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        $widgets = $rules->list('lexicon.known_widget_scripts', $key);

        foreach ($a->scripts() as $script) {
            if ($script['src'] === null || $script['host'] === '') {
                continue;
            }
            if ($script['host'] === 'googletagmanager.com') {
                continue; // handled by the GTM container check below
            }
            if (! $a->hosts->isApprovedScript($script['host'])) {
                return $this->loaderHit($key, $rules, $ctx, 'External script loaded from an unapproved host', $script['text'], $script['offset'], ['external_script', 'host:'.$script['host']], $script['chain'], $script['host'], $this->isKnownWidget($script['src'], $widgets));
            }
        }

        foreach ($a->layers() as $layer) {
            $t = $layer['text'];
            $dynamic = (bool) preg_match('/createElement\s*\(\s*\\\\?["\']script\\\\?["\']\s*\)/i', $t);

            if ($dynamic && preg_match('/\.src\s*=\s*\\\\?["\'`]((?:https?:)?\/\/[^"\'`\s\\\\]+)/i', $t, $m, PREG_OFFSET_CAPTURE)) {
                $host = HostContext::hostOf($m[1][0]);
                if ($host !== '' && $host !== 'googletagmanager.com' && ! $a->hosts->isApprovedScript($host)) {
                    return $this->loaderHit($key, $rules, $ctx, 'Dynamic script loader pointing at an unapproved host', $t, $m[0][1], ['dynamic_script_element', 'host:'.$host], $layer['chain'], $host, $this->isKnownWidget($m[1][0], $widgets));
                }
            } elseif ($dynamic && preg_match('/\.src\s*=\s*[A-Za-z_$(]/', $t, $m, PREG_OFFSET_CAPTURE)) {
                // The URL is assembled at runtime (vendor snippets and malware
                // both do this). Judge it by the URL literals it is built from.
                if (preg_match_all('#["\'`]((?:https?:)?//([a-z0-9.-]+\.[a-z]{2,})[^"\'`\s]*)#i', $t, $um, PREG_SET_ORDER)) {
                    foreach ($um as $u) {
                        $host = HostContext::hostOf($u[1]);
                        if ($host === '' || $host === 'googletagmanager.com' || $a->hosts->isApprovedScript($host)) {
                            continue;
                        }
                        $widget = $this->isKnownWidget($u[1], $widgets);

                        return $this->hit($key, $rules, $ctx, $widget ? 'Known third-party widget script (runtime-built loader)' : 'Script loader builds its URL at runtime from an unapproved host', $t, $m[0][1], ['dynamic_script_element', 'computed_src', 'host:'.$host], $layer['chain'], 'needs_review', $widget ? 'third_party_widget' : 'injected_loader', 'warn', extra: ['remote_host' => $host]);
                    }
                }
            }

            if (preg_match('/\bimport\s*\(\s*["\'`]((?:https?:)?\/\/[^"\'`\s]+)/i', $t, $m, PREG_OFFSET_CAPTURE)) {
                $host = HostContext::hostOf($m[1][0]);
                if ($host !== '' && ! $a->hosts->isApprovedScript($host)) {
                    return $this->loaderHit($key, $rules, $ctx, 'Dynamic import from an unapproved host', $t, $m[0][1], ['dynamic_import', 'host:'.$host], $layer['chain'], $host, false);
                }
            }

            if (preg_match('/(eval|assert|include|require)(_once)?\s*\(\s*(file_get_contents|curl_exec|wp_remote_retrieve_body)\b/i', $t, $m, PREG_OFFSET_CAPTURE)) {
                return $this->hit($key, $rules, $ctx, 'Server-side code fetches and executes remote content', $t, $m[0][1], ['php_remote_exec'], $layer['chain'], 'strong', 'injected_loader');
            }

            // Google Tag Manager is a legitimate host, so trust depends on the
            // container ID being baselined for this market.
            if (preg_match_all('/googletagmanager\.com\/(?:gtm\.js|ns\.html)\?id=(GTM-[A-Z0-9]{4,10})|[\'"](GTM-[A-Z0-9]{4,10})[\'"]/i', $t, $gm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($gm as $g) {
                    $id = strtoupper($g[1][0] ?: ($g[2][0] ?? ''));
                    if ($id !== '' && ! $a->hosts->gtmApproved($id)) {
                        return $this->hit($key, $rules, $ctx, 'Tag Manager container not in this market\'s approved baseline', $t, $g[0][1], ['gtm_container:'.$id], $layer['chain'], 'needs_review', 'gtm_unbaselined', 'warn', extra: ['container_id' => $id]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Strong only where the loader actually runs for visitors. Known vendor
     * widgets (newsletter forms, analytics) and loaders sitting in an ordinary
     * plugin option — which execute only if that plugin prints them — are
     * review items, not malware verdicts.
     */
    private function loaderHit(string $key, RuleSet $rules, RowContext $ctx, string $title, string $text, ?int $offset, array $signals, array $chain, string $host, bool $knownWidget): Hit
    {
        if ($knownWidget) {
            return $this->hit($key, $rules, $ctx, 'Known third-party widget script from a shared host', $text, $offset, array_merge($signals, ['known_widget']), $chain, 'needs_review', 'third_party_widget', 'warn', extra: ['remote_host' => $host]);
        }
        if (in_array($ctx->label('public_exposure'), ['not_public', 'unplaced_or_unknown_widget'], true)) {
            return $this->hit($key, $rules, $ctx, $title.' (public exposure not established)', $text, $offset, array_merge($signals, ['exposure:'.$ctx->label('public_exposure')]), $chain, 'needs_review', 'stored_loader', 'warn', extra: ['remote_host' => $host]);
        }
        if ($chain === [] && $this->isDormantStore($ctx)) {
            return $this->hit($key, $rules, $ctx, $title.' (stored in a plugin option; runs only if that plugin outputs it)', $text, $offset, array_merge($signals, ['plugin_option']), $chain, 'needs_review', 'stored_loader', 'warn', extra: ['remote_host' => $host]);
        }

        return $this->hit($key, $rules, $ctx, $title, $text, $offset, $signals, $chain, 'strong', 'injected_loader', extra: ['remote_host' => $host]);
    }

    /**
     * @param  array<int, string>  $widgets  host/path fragments of reviewed vendor widget scripts
     */
    private function isKnownWidget(string $url, array $widgets): bool
    {
        $url = strtolower(preg_replace('#^(https?:)?//#i', '', html_entity_decode($url)) ?? '');
        foreach ($widgets as $fragment) {
            $fragment = strtolower(trim((string) $fragment));
            if ($fragment !== '' && str_contains($url, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** An option that is neither rendered sitewide (widgets, theme mods) nor a code store. */
    private function isDormantStore(RowContext $ctx): bool
    {
        return $ctx->surface === 'options.values' && ! $this->isPublicContent($ctx) && ! $this->isCodeStore($ctx);
    }

    private function redirectOverlay(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        $codeField = $this->isCodeStore($ctx);

        foreach ($a->scriptContexts($codeField) as $context) {
            $t = $context['text'];
            if (preg_match(self::JS_REDIRECT, $t, $m, PREG_OFFSET_CAPTURE)) {
                $target = $m[2][0] ?? '';
                if ($target === '' && isset($m[4])) {
                    $target = $m[4][0];
                }
                $quoted = ($m[1][0] ?? '') !== '' || ($m[3][0] ?? '') !== '';
                $host = HostContext::hostOf($target);
                $conditional = (bool) preg_match('/document\.referrer|navigator\.userAgent|document\.cookie|navigator\.language|screen\.width/i', $t);

                if ($host !== '' && ! $a->hosts->isApprovedOutbound($host)) {
                    return $this->hit($key, $rules, $ctx, 'Script redirects visitors to an external host', $t, $m[0][1], array_filter(['js_redirect', 'host:'.$host, $conditional ? 'conditional_routing' : null]), $context['chain'], 'strong', 'redirect', extra: ['remote_host' => $host]);
                }
                if (! $quoted && $conditional) {
                    return $this->hit($key, $rules, $ctx, 'Conditional redirect to a computed destination', $t, $m[0][1], ['js_redirect_dynamic', 'conditional_routing'], $context['chain'], 'strong', 'redirect');
                }
                if (! $quoted && $context['chain'] !== []) {
                    return $this->hit($key, $rules, $ctx, 'Encoded script changes the page location', $t, $m[0][1], ['js_redirect_dynamic'], $context['chain'], 'needs_review', 'redirect', 'warn');
                }
            }
        }

        foreach ($a->layers() as $layer) {
            $t = $layer['text'];
            if (preg_match(self::FAKE_UPDATE, $t, $m, PREG_OFFSET_CAPTURE) && preg_match('/<(script|div|iframe|a)\b|\.(exe|msi|zip|apk|js)\b/i', $t)) {
                return $this->hit($key, $rules, $ctx, 'Fake browser-update or download overlay', $t, $m[0][1], ['fake_update_lure'], $layer['chain'], 'strong', 'fake_update');
            }

            if (preg_match('/<meta[^>]+http-equiv\s*=\s*["\']?refresh[^>]+url\s*=\s*([^"\'>\s]+)/i', $t, $m, PREG_OFFSET_CAPTURE)) {
                $host = HostContext::hostOf($m[1][0]);
                if ($host !== '' && ! $a->hosts->isApprovedOutbound($host)) {
                    return $this->hit($key, $rules, $ctx, 'Meta refresh redirect to an external host', $t, $m[0][1], ['meta_refresh', 'host:'.$host], $layer['chain'], 'strong', 'redirect', extra: ['remote_host' => $host]);
                }
            }
        }

        foreach ($a->iframes() as $iframe) {
            if ($a->hosts->isApprovedIframe($iframe['host'])) {
                continue;
            }
            $tag = $iframe['tag'];
            if (preg_match('/position\s*:\s*fixed/i', $tag) && preg_match('/width\s*:\s*100(%|vw)/i', $tag) && preg_match('/height\s*:\s*100(%|vh)/i', $tag)) {
                return $this->hit($key, $rules, $ctx, 'Full-screen iframe overlay from an external host', $iframe['text'], $iframe['offset'], ['fullscreen_iframe', 'host:'.$iframe['host']], $iframe['chain'], 'strong', 'overlay', extra: ['remote_host' => $iframe['host']]);
            }
        }

        return null;
    }

    private function obfuscatedPayload(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        foreach ($a->obfuscatedLayers() as $layer) {
            $t = $layer['text'];
            $sinks = array_keys(array_filter([
                'script_tag' => preg_match('/<script\b/i', $t),
                'js_eval' => preg_match('/\beval\s*\(|new\s+Function\s*\(|document\.write\s*\(/i', $t),
                'js_redirect' => preg_match(self::JS_REDIRECT, $t),
                'php_tag' => preg_match('/<\?(php|=)/i', $t),
                'php_exec_sink' => preg_match(self::PHP_EXEC, $t),
                'iframe' => preg_match('/<iframe\b/i', $t),
            ]));

            // A decoded URL matters only inside code or markup; JSON configs
            // and tokens routinely contain remote addresses.
            $codeLike = ! preg_match('/^\s*[\[{]/', $t) && preg_match('/<[a-z!?]|\bsrc\s*=|\bhref\s*=|\bfunction\b|=>|\beval\b|\bwindow\.|\bdocument\./i', $t);
            $external = null;
            if ($codeLike && preg_match_all('#(?:https?:)?//([a-z0-9.-]+\.[a-z]{2,})#i', $t, $hm)) {
                foreach ($hm[1] as $h) {
                    $h = preg_replace('/^www\./', '', strtolower($h));
                    if (! $a->hosts->isApprovedOutbound($h)) {
                        $external = $h;
                        break;
                    }
                }
            }

            if ($sinks !== [] || $external !== null) {
                $signals = array_merge($sinks, $external ? ['host:'.$external] : []);
                $confidence = $sinks !== [] ? 'strong' : 'needs_review';

                return $this->hit(
                    $key, $rules, $ctx,
                    $sinks !== [] ? 'Encoded payload decodes to executable code' : 'Encoded payload decodes to an unapproved remote address',
                    $t, null, $signals, $layer['chain'], $confidence, 'obfuscation',
                    $confidence === 'strong' ? null : 'warn',
                    extra: array_filter(['remote_host' => $external]),
                    decodedHash: true,
                );
            }
        }

        $codeContext = $this->isCodeStore($ctx) || $a->scripts() !== [];
        if ($codeContext) {
            $marker = $a->firstMatch('/eval\s*\(\s*function\s*\(\s*p\s*,\s*a\s*,\s*c\s*,\s*k\s*,\s*e|fromCharCode\s*\(\s*\d+(\s*,\s*\d+){30,}|(\\\\x[0-9a-f]{2}){30,}|atob\s*\(\s*["\'][A-Za-z0-9+\/=]{200,}/i', false);
            if ($marker) {
                return $this->hit($key, $rules, $ctx, 'Heavily obfuscated script', $marker['text'], $marker['offset'], ['obfuscation_marker'], [], 'needs_review', 'obfuscation', 'warn');
            }
        }

        if ($a->decoded()->capped() && ($codeContext || $a->obfuscatedLayers() !== [])) {
            return $this->hit($key, $rules, $ctx, 'Encoded content exceeded safe decoding limits (incomplete analysis)', $a->text, null, array_map(fn ($c) => 'decode_cap:'.$c, $a->decoded()->caps), [], 'needs_review', 'obfuscation', 'warn');
        }

        return null;
    }

    private function credentialCapture(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        foreach ($a->scriptContexts($this->isCodeStore($ctx)) as $context) {
            $t = $context['text'];
            $fields = preg_match('/(type|name|id)\s*=\s*\\\\?["\']?(password|pass|pwd|card[-_]?number|cardnumber|cc[-_]?(number|num)|cvv|cvc|card[-_]?cvc|exp(iry)?[-_]?date)\b/i', $t)
                || preg_match('/querySelector(All)?\s*\(\s*["\'][^"\']*(password|card|cvv|cvc|cc-number)[^"\']*["\']|getElementById\s*\(\s*["\'][^"\']*(password|card|cvv|cvc)[^"\']*["\']/i', $t);
            if (! $fields) {
                continue;
            }
            if (! preg_match('/(fetch\s*\(|navigator\.sendBeacon|XMLHttpRequest|new\s+Image\s*\(|\$\.(post|ajax)\s*\(|new\s+WebSocket\s*\()/i', $t, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            if (preg_match_all('#(?:https?:|wss?:)?//([a-z0-9.-]+\.[a-z]{2,})#i', $t, $hm)) {
                foreach ($hm[1] as $h) {
                    $h = preg_replace('/^www\./', '', strtolower($h));
                    if (! $a->hosts->isApprovedScript($h)) {
                        return $this->hit($key, $rules, $ctx, 'Script reads password or card fields and sends them to an external host', $t, $m[0][1], ['sensitive_field_access', 'exfiltration_sink', 'host:'.$h], $context['chain'], 'strong', 'credential_theft', extra: ['remote_host' => $h]);
                    }
                }
            }
        }

        return null;
    }

    private function knownIoc(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        foreach ($rules->list('ioc.strings', $key) as $needle) {
            $needle = (string) $needle;
            if (mb_strlen($needle) < 6) {
                continue;
            }
            $match = $a->firstMatch('/'.preg_quote($needle, '/').'/i');
            if ($match) {
                return $this->hit($key, $rules, $ctx, 'Vetted campaign indicator matched', $match['text'], $match['offset'], ['ioc_string'], $match['chain'], 'confirmed', 'known_campaign', extra: ['indicator' => mb_substr($needle, 0, 80)]);
            }
        }

        $domains = $rules->list('ioc.domains', $key);
        if ($domains !== []) {
            foreach ($a->linkHosts() as $host) {
                if (HostContext::matches($host, $domains)) {
                    $pos = stripos($a->text, $host);

                    return $this->hit($key, $rules, $ctx, 'Vetted malicious domain referenced', $a->text, $pos === false ? null : $pos, ['ioc_domain', 'host:'.$host], [], 'confirmed', 'known_campaign', extra: ['remote_host' => $host]);
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Content integrity
    // ------------------------------------------------------------------

    private function bannerExchange(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if ($ctx->surface !== 'posts.content' || $ctx->label('post_status') !== 'publish') {
            return null;
        }
        $domains = $rules->list('lexicon.banner_exchange_domains', $key);
        foreach (array_merge($a->scripts(), $a->iframes()) as $embed) {
            foreach ($domains as $host) {
                $direct = HostContext::matches($embed['host'], [$host]);
                // document.write embeds can keep the remote URL inside an inline script.
                $inline = isset($embed['body']) && preg_match('~(?:https?:)?//(?:www\\?\.)?'.preg_quote($host, '~').'(?=[/\\\\:\s"\'<>]|$)~i', $embed['body']);
                if ($direct || $inline) {
                    return $this->hit($key, $rules, $ctx, 'Published page executes a third-party banner exchange', $embed['text'], $embed['offset'], ['banner_exchange', 'executable_embed', 'host:'.$host], $embed['chain'], 'strong', 'third_party_execution', 'critical', ['remote_host' => $host]);
                }
            }
        }
        foreach ($a->linkHosts() as $host) {
            if (in_array($host, $domains, true)) {
                $offset = stripos($a->text, $host);

                return $this->hit($key, $rules, $ctx, 'Published page links to a banner/traffic exchange', $a->text, $offset === false ? null : $offset, ['banner_exchange', 'link_only', 'host:'.$host], [], 'needs_review', 'business_review', 'info', ['remote_host' => $host]);
            }
        }

        return null;
    }

    private function scriptInjection(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if (! $this->isPublicContent($ctx)) {
            return null;
        }

        foreach ($a->scripts() as $script) {
            if ($script['chain'] !== []) {
                continue;
            }
            $external = $script['src'] !== null && $script['host'] !== '' && ! $a->hosts->isApprovedScript($script['host']);
            $inline = $script['src'] === null && trim(strip_tags($script['body'])) !== '';
            if ($external || $inline) {
                return $this->hit($key, $rules, $ctx, $external ? 'Script tag loading from an unapproved host in content' : 'Inline script in content', $script['text'], $script['offset'], [$external ? 'external_script' : 'inline_script'], [], 'needs_review', null);
            }
        }

        foreach ($a->iframes() as $iframe) {
            if ($iframe['chain'] === [] && $iframe['host'] !== '' && ! $a->hosts->isApprovedIframe($iframe['host'])) {
                return $this->hit($key, $rules, $ctx, 'Iframe from an unapproved host in content', $iframe['text'], $iframe['offset'], ['iframe', 'host:'.$iframe['host']], [], 'needs_review', null);
            }
        }

        if (preg_match('/href\s*=\s*["\']?\s*javascript:|<[a-z][^>]*\son(load|error|mouseover|focus|click)\s*=\s*["\']?[^"\'>]{3,}/i', $a->text, $m, PREG_OFFSET_CAPTURE)) {
            return $this->hit($key, $rules, $ctx, 'JavaScript URL or event handler in content', $a->text, $m[0][1], ['js_handler'], [], 'needs_review', null);
        }

        return null;
    }

    private function unexpectedScript(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if (! $this->isPublicContent($ctx) && $ctx->surface !== 'users.names') {
            return null;
        }
        $allowed = array_map('strtolower', $rules->list('allow.scripts', $key));
        $min = [
            'CJK' => $ctx->surface === 'users.names' ? 2 : (int) $rules->threshold($key, 'cjk_min', 5),
            'Cyrillic' => (int) $rules->threshold($key, 'cyrillic_min', 20),
            'Greek' => (int) $rules->threshold($key, 'greek_min', 20),
        ];
        $text = $ctx->surface === 'users.names' ? $a->text : $a->plainText();

        foreach ($a->scriptCounts($text) as $script => $count) {
            if (in_array(strtolower($script), $allowed, true) || $count < $min[$script]) {
                continue;
            }
            $class = ['CJK' => '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}', 'Cyrillic' => '\p{Cyrillic}', 'Greek' => '\p{Greek}'][$script];
            $offset = preg_match('/['.$class.']/u', $text, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;

            return $this->hit($key, $rules, $ctx, $script.' text in '.$this->where($ctx), $text, $offset, ['script:'.$script, 'chars:'.$count], [], null, null);
        }

        return null;
    }

    private function spamLexicon(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if (! $this->isPublicContent($ctx) && $ctx->surface !== 'comments.approved') {
            return null;
        }
        $text = $a->plainText();

        foreach ($rules->ruleLists($key) as $listKey) {
            foreach ($rules->list($listKey, $key) as $term) {
                $term = trim((string) $term);
                if ($term === '') {
                    continue;
                }
                $pattern = preg_match('/^[\p{Latin}\p{N}\s\-:@.]+$/u', $term)
                    ? '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu'
                    : '/'.preg_quote($term, '/').'/u';
                if (@preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                    return $this->hit($key, $rules, $ctx, 'Spam vocabulary ('.str_replace('lexicon.', '', $listKey).') in '.$this->where($ctx), $text, $m[0][1], ['list:'.$listKey, 'term:'.$term], [], null, null);
                }
            }
        }

        return null;
    }

    private function hiddenText(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if (! $this->isPublicContent($ctx)) {
            return null;
        }
        $pattern = '/<([a-z][a-z0-9]*+)\b[^>]*style\s*=\s*["\'][^"\']*(display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0(\.0+)?\s*[;"\']|text-indent\s*:\s*-\d{3,}|font-size\s*:\s*0(px)?\s*[;"\']|(?<![-\w])color\s*:\s*(#fff(?:fff)?|white|transparent|rgba\([^)]*,\s*0\))\s*[;"\']|(left|top)\s*:\s*-\d{3,}px)[^"\']*["\'][^>]*>(?:(?!<\/\1>).){0,2000}?<a\s[^>]*href/is';
        $anchor = '/<a\b(?=[^>]*\bhref\s*=)[^>]*style\s*=\s*["\'][^"\']*(display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0(?:\.0+)?\s*[;"\']|font-size\s*:\s*0(?:px)?\s*[;"\']|(?<![-\w])color\s*:\s*(?:#fff(?:fff)?|white|transparent)|(?:left|top)\s*:\s*-\d{3,}px)[^"\']*["\'][^>]*>/is';
        $candidates = [];
        foreach ([$pattern, $anchor] as $expression) {
            if (@preg_match_all($expression, $a->text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                $candidates = array_merge($candidates, array_slice($found, 0, 100));
            }
        }
        $review = null;
        foreach ($candidates as $m) {
            // Accessibility helpers (screen-reader text) hide labels, not links.
            if (preg_match('/class\s*=\s*["\'][^"\']*(screen-reader-text|sr-only|visually-hidden)/i', $m[0][0])) {
                continue;
            }

            $exposure = $ctx->label('public_exposure') ?? (($ctx->label('post_status') === 'publish') ? 'published' : 'unknown');
            $public = $exposure === 'published' || str_starts_with($exposure, 'rendered_widget:');
            $concealed = (bool) preg_match('/display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0(?:\.0+)?[;"\']|font-size\s*:\s*0(?:px)?[;"\']|(?:left|top|text-indent)\s*:\s*-\d{3,}|(?<![-\w])color\s*:\s*(?:transparent|rgba\([^)]*,\s*0\))/i', $m[0][0]);
            $sameColour = preg_match('/(?<![-\w])color\s*:\s*(#fff(?:fff)?|white)\s*[;"\']/i', $m[0][0])
                && preg_match('/background(?:-color)?\s*:\s*(#fff(?:fff)?|white)\s*[;"\']/i', $m[0][0]);
            // A white link on the network's dark themes is not proof of concealment.
            $strong = $public && ($concealed || $sameColour);

            $hit = $this->hit($key, $rules, $ctx, 'Links hidden with CSS', $a->text, $m[0][1], ['hidden_style', 'link', 'exposure:'.$exposure], [], $strong ? 'strong' : 'needs_review', null, $strong ? 'critical' : 'warn', ['exposure' => $exposure, 'details' => ['exposure' => $exposure, 'concealment_confirmed' => (bool) ($concealed || $sameColour)]]);
            if ($strong) {
                return $hit;
            }
            $review ??= $hit;
        }

        return $review;
    }

    private function seoMetaInjection(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        $metaKey = (string) $ctx->label('meta_key');
        if (! in_array($metaKey, ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', 'rank_math_title', 'rank_math_description'], true)) {
            return null;
        }
        $text = $a->text;

        foreach ($a->linkHosts() as $host) {
            if (! $a->hosts->isNetwork($host)) {
                $offset = stripos($text, $host);

                return $this->hit($key, $rules, $ctx, 'External link inside an SEO title or description', $text, $offset === false ? null : $offset, ['seo_link', 'host:'.$host], [], 'needs_review', null);
            }
        }
        foreach (['lexicon.pharma', 'lexicon.casino', 'lexicon.loans', 'lexicon.cn_escort_spam', 'lexicon.japanese_keyword'] as $listKey) {
            foreach ($rules->list($listKey, $key) as $term) {
                if ($term !== '' && mb_stripos($text, (string) $term) !== false) {
                    return $this->hit($key, $rules, $ctx, 'Spam vocabulary in an SEO title or description', $text, stripos($text, (string) $term) ?: null, ['list:'.$listKey], [], 'needs_review', null);
                }
            }
        }
        $counts = $a->scriptCounts($text);
        if (($counts['CJK'] ?? 0) >= 5 && ! in_array('cjk', array_map('strtolower', $rules->list('allow.scripts')), true)) {
            return $this->hit($key, $rules, $ctx, 'CJK text in an SEO title or description', $text, null, ['script:CJK'], [], 'needs_review', null);
        }

        return null;
    }

    private function shortenerLinks(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a, Accumulator $acc): ?Hit
    {
        $shorteners = $rules->list('lexicon.shorteners', $key);
        foreach ($a->linkHosts() as $host) {
            if (HostContext::matches($host, $shorteners)) {
                $acc->add($key, $host, $ctx->rowId);
            }
        }

        return null;
    }

    private function commentLinks(string $key, RowContext $ctx, ContentAnalysis $a, Accumulator $acc): ?Hit
    {
        if (preg_match('#https?://#i', $a->text)) {
            $acc->add($key, 'approved_comments_with_links', $ctx->rowId);
        }

        return null;
    }

    private function outboundDomains(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a, Accumulator $acc): ?Hit
    {
        if (! $this->isPublicContent($ctx)) {
            return null;
        }
        foreach ($a->linkHosts() as $host) {
            if (! $a->hosts->isApprovedOutbound($host)) {
                $acc->add($key, $host, $ctx->rowId);
            }
        }

        return null;
    }

    private function malformedLinks(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if (! $this->isPublicContent($ctx)) {
            return null;
        }
        $text = $a->text;
        if (($pos = stripos($text, 'http:/https:')) !== false) {
            return $this->hit($key, $rules, $ctx, 'Malformed link (http:/https:)', $text, $pos, ['malformed:http_https'], [], null, null);
        }
        if (preg_match('/href\s*=\s*["\']?(”|“|&#822[01];|\\\\?"")\s*https?:/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $this->hit($key, $rules, $ctx, 'Malformed link (curly or doubled quote href)', $text, $m[0][1], ['malformed:quote_href'], [], null, null);
        }

        return null;
    }

    private function retiredParameters(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if (! $this->isPublicContent($ctx)) {
            return null;
        }
        foreach ($rules->list('lexicon.retired_parameters', $key) as $param) {
            if ($param !== '' && ($pos = stripos($a->text, (string) $param)) !== false) {
                return $this->hit($key, $rules, $ctx, 'Retired URL parameter stored in content', $a->text, $pos, ['param:'.$param], [], null, null);
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Persistence stores
    // ------------------------------------------------------------------

    private function iocOptionNames(string $key, RuleSet $rules, RowContext $ctx): ?Hit
    {
        if ($ctx->surface !== 'options.values') {
            return null;
        }
        $name = strtolower($ctx->name());
        $listed = in_array($name, array_map('strtolower', $rules->list('ioc.option_names', $key)), true);
        if ($listed || str_starts_with($name, 'wds_protect')) {
            return new Hit(
                $key,
                'Option name used by known WordPress malware families',
                $ctx->subject(),
                ['excerpts' => [], 'signals' => ['ioc_option_name:'.$name], 'bytes' => $ctx->octetLength, 'autoload' => $ctx->label('autoload')],
                'needs_review',
                'persistence',
            );
        }

        return null;
    }

    private function lookalikeOptions(string $key, RuleSet $rules, RowContext $ctx): ?Hit
    {
        if ($ctx->surface !== 'options.values') {
            return null;
        }
        $autoload = strtolower((string) $ctx->label('autoload'));
        if (! in_array($autoload, ['yes', 'on', 'auto-on', 'auto'], true)) {
            return null;
        }
        if ($ctx->octetLength < (int) $rules->threshold($key, 'min_bytes', 1024)) {
            return null;
        }
        $name = $ctx->name();
        if (! preg_match('/^(_core_|_site_(?!transient)|_wp_(?!session)|wp_[0-9a-f]{8,}_)/i', $name)) {
            return null;
        }
        if (in_array(strtolower($name), array_map('strtolower', $rules->list('allow.known_options', $key)), true)) {
            return null;
        }

        return new Hit(
            $key,
            'Large autoloaded option named like a WordPress core internal',
            $ctx->subject(),
            ['excerpts' => [], 'signals' => ['lookalike_name'], 'bytes' => $ctx->octetLength],
            'needs_review',
            'persistence',
        );
    }

    private function encodedPayloads(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if (preg_match('/(?<![\w>$-])(eval|base64_decode|gzinflate|gzuncompress|str_rot13|create_function|shell_exec|passthru)\s*\(|(?<![\w>$-])assert\s*\(\s*\$/i', $a->text, $m, PREG_OFFSET_CAPTURE)) {
            return $this->hit($key, $rules, $ctx, 'PHP execution or decoding function stored in '.$this->where($ctx), $a->text, $m[0][1], ['php_function:'.strtolower($m[1][0] ?: 'assert')], [], 'needs_review', 'persistence');
        }

        return null;
    }

    private function serializedObjects(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if (! preg_match_all('/O:\d+:"([^"]{1,120})"/', $a->text, $m)) {
            return null;
        }
        $allow = $rules->list('allow.serialized_classes', $key);
        $unknown = [];
        foreach (array_unique($m[1]) as $class) {
            $ok = false;
            foreach ($allow as $pattern) {
                $pattern = (string) $pattern;
                if ($pattern !== '' && (str_ends_with($pattern, '*') ? str_starts_with($class, rtrim($pattern, '*')) : $class === $pattern)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                $unknown[] = $class;
            }
        }
        if ($unknown === []) {
            return null;
        }

        return new Hit(
            $key,
            'Serialized PHP object of an unexpected class',
            $ctx->subject(),
            ['excerpts' => [], 'signals' => array_map(fn ($c) => 'class:'.mb_substr($c, 0, 80), array_slice($unknown, 0, 5))],
            'needs_review',
            'persistence',
        );
    }

    private function codeSnippetStores(string $key, RuleSet $rules, RowContext $ctx, ContentAnalysis $a): ?Hit
    {
        if (! $this->isCodeStore($ctx)) {
            return null;
        }
        if ($ctx->surface === 'snippets.code' && (string) $ctx->label('active') === '0') {
            return null;
        }
        $patterns = [
            'remote_script' => '/<script\b[^>]*\bsrc\s*=\s*["\']?(https?:)?\/\//i',
            'eval' => '/(?<![\w>$-])eval\s*\(/i',
            'redirect' => self::JS_REDIRECT,
            'iframe' => '/<iframe\b/i',
            'php_remote' => '/(file_get_contents|wp_remote_get|curl_exec)\s*\(/i',
        ];
        foreach ($patterns as $signal => $pattern) {
            if (preg_match($pattern, $a->text, $m, PREG_OFFSET_CAPTURE)) {
                return $this->hit($key, $rules, $ctx, 'Active stored code with a remote script, eval or redirect', $a->text, $m[0][1], ['stored_code', $signal], [], 'needs_review', 'stored_code');
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Snippet tables, snippet post types and header/footer code options.
     */
    public function isCodeStore(RowContext $ctx): bool
    {
        if ($ctx->surface === 'snippets.code') {
            return true;
        }
        if ($ctx->surface === 'posts.content' && in_array((string) $ctx->label('post_type'), SurfaceRegistry::SNIPPET_POST_TYPES, true)) {
            return true;
        }
        if ($ctx->surface === 'options.values') {
            return (bool) preg_match('/^(ihaf_|wpcode_|wpcode|elementor_custom_code|insert_headers|header_footer|hefo|wp_head_footer|custom_code|custom_js|theme_mods_.*(header|footer|script))/i', $ctx->name());
        }
        if ($ctx->surface === 'postmeta.code') {
            return (bool) preg_match('/(code|script|custom_css|_ihaf_)/i', (string) $ctx->label('meta_key'));
        }

        return false;
    }

    /**
     * Visitor-facing content: posts (non-snippet), widgets/theme mods, terms,
     * SEO meta and builder data.
     */
    public function isPublicContent(RowContext $ctx): bool
    {
        return match ($ctx->surface) {
            'posts.content' => ! in_array((string) $ctx->label('post_type'), SurfaceRegistry::SNIPPET_POST_TYPES, true),
            'options.values' => (bool) preg_match('/^(widget_|theme_mods_|sidebars_widgets$)/', $ctx->name()),
            'terms.descriptions', 'postmeta.code' => true,
            default => false,
        };
    }

    private function where(RowContext $ctx): string
    {
        return match ($ctx->surface) {
            'posts.content' => 'published content',
            'options.values' => 'options',
            'postmeta.code', 'postmeta.values' => 'post metadata',
            'usermeta.values' => 'user metadata',
            'terms.descriptions' => 'term descriptions',
            'users.names' => 'user names',
            'comments.approved' => 'approved comments',
            'snippets.code' => 'code snippets',
            default => 'stored values',
        };
    }

    private function hit(
        string $key,
        RuleSet $rules,
        RowContext $ctx,
        string $title,
        string $sourceText,
        ?int $offset,
        array $signals,
        array $chain,
        ?string $confidence,
        ?string $behavior,
        ?string $severity = null,
        array $extra = [],
        bool $decodedHash = false,
    ): Hit {
        $evidence = [
            'excerpts' => [$this->sanitizer->excerpt($sourceText, $offset)],
            'signals' => array_values(array_map(fn ($s) => mb_substr((string) $s, 0, 120), $signals)),
            'transformations' => array_values($chain),
            'bytes' => $ctx->octetLength,
            'fully_read' => $ctx->fullyRead,
        ] + array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 190) : $v, $extra);

        if ($decodedHash) {
            $evidence['decoded_sha256'] = hash('sha256', $sourceText);
        }

        // Full hashes only for complete values; partial reads get a labelled
        // fragment hash so nobody mistakes it for the payload identity.
        $payloadHash = $ctx->valueHash;
        $hashType = $payloadHash === null ? null : ($ctx->fullyRead ? 'full' : 'fragment');

        return new Hit($key, $title, $ctx->subject(), $evidence, $confidence, $behavior, $severity, $payloadHash, $hashType);
    }
}
