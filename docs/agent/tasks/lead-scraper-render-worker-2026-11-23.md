# Lead scraper render worker (B) — handover to Codex, 23 Nov 2026

Parent design: [lead-scraper-intelligence-2026-11-23.md](lead-scraper-intelligence-2026-11-23.md)
(read its Intent, Anti-goals and "Fetch drivers" first). Claude is building A in the main
checkout at the same time; do not edit A's files.

## Goal
A small headless-rendering service so the CRM scraper can read directory sites whose
listings only appear after JavaScript runs. It plugs into A's fetch-driver contract as
`RenderWorkerDriver`. It must NOT run on the CRM cPanel host (no Chromium there; that
host holds payment credentials) — target a separate small VPS, or Ian's Mac for trials.

## Authorized scope
- New directory `scraper-worker/` in this repo (Node 20+, Playwright Chromium, no other
  browser engines), with README, `.env.example`, Dockerfile, tests.
- New CRM file `app/Services/Scraper/Fetch/RenderWorkerDriver.php` implementing A's
  `FetchDriver` interface, plus its tests `tests/Unit/Scraper/RenderWorkerDriverTest.php`.
- Config keys appended to `config/scraper.php` (A creates the file; add only the
  `render_worker` block): `url`, `secret`, `timeout_seconds` (default 60), `enabled`.
- Not authorized: deploying/provisioning a VPS, buying services, editing A's files beyond
  the `render_worker` config block, commits touching unrelated dirty paths, push.
  Commit only if Ian asks; follow AGENTS.md staging rules (406 unrelated dirty paths).

## Contract
`POST {url}/v1/render`
Headers: `Content-Type: application/json`, `X-Scraper-Timestamp: <unix seconds>`,
`X-Scraper-Signature: hex(hmac_sha256(secret, timestamp + "\n" + rawBody))`.
Reject when timestamp skew > 300 s or signature mismatch (401).
Body:
```json
{"url":"https://…","wait_until":"domcontentloaded|load|networkidle",
 "wait_for_selector":null,"timeout_ms":30000,"scroll_steps":0,
 "headers":{},"cookies":[{"name":"","value":"","domain":""}],
 "user_agent":"ExoticCRMLeadBot/1.0 (+https://crm.exotic-online.com)",
 "screenshot":false}
```
Limits: timeout_ms ≤ 45000, scroll_steps ≤ 5, html ≤ 6 MB (truncate + flag).
Response 200:
```json
{"ok":true,"final_url":"…","status":200,"title":"…","html":"…","bytes":123,
 "elapsed_ms":4100,"truncated":false,
 "blocked":null,
 "screenshot_jpeg_base64":null}
```
`blocked` is `{"type":"cloudflare_challenge|captcha|login_wall|http_error","detail":"…"}`
when detected; the worker returns what it saw and stops — it never tries to pass it.
Errors: `{"ok":false,"error":"timeout|navigation_failed|ssrf_blocked|bad_request"}` with 4xx/5xx.
`GET /healthz` → `{ok, version, browser_version, inflight}` (unsigned, no secrets).

## Hard rules (anti-goals from the design)
- Honest declared user agent from the request; no stealth/fingerprint plugins, no
  CAPTCHA/Turnstile solving services, no proxy rotation, no retry loops against a
  challenge. Report `blocked` instead.
- SSRF guard: resolve DNS and reject loopback, private, link-local, CGNAT, multicast,
  reserved and metadata IPs, before navigation and on every redirect/request
  (Playwright route interception). Only http/https.
- Concurrency cap (default 2 pages), one browser context per request, closed after.
- Block heavy resource types (media/fonts) to save bandwidth; images optional.
- No logging of HTML bodies or cookies; log url host, status, ms, blocked type.

## RenderWorkerDriver (CRM side)
- Signs the request exactly as above, maps the response into A's `FetchResult`
  (`driver = 'render_worker'`), surfaces `blocked` into `FetchResult::$blocked` hint.
- Treats worker unavailability as a fetch error with a clear message
  ("Render worker unreachable"), never falls back silently.
- `isAvailable()` true only when `scraper.render_worker.enabled` and url+secret set.
A's interface (will exist at `app/Services/Scraper/Fetch/FetchDriver.php`):
```php
interface FetchDriver {
    public function key(): string;           // 'http' | 'capture' | 'render_worker'
    public function isAvailable(): bool;
    public function fetch(FetchRequest $request): FetchResult;
}
```
`FetchRequest`: url, headers[], cookies[], timeoutSeconds, waitForSelector?, scrollSteps,
userAgent. `FetchResult`: requestedUrl, finalUrl, status, headers[], html, bytes,
elapsedMs, driver, error?, blocked? (array). If A's files are not there yet when you
start, build the worker first and stub against this contract.

## Acceptance
- Worker unit/integration tests: signature accept/reject, skew, SSRF rejects
  (127.0.0.1, 10/8, 169.254.169.254, IPv6 ::1, redirect to private), renders a local
  JS-only fixture page whose cards appear after 500 ms, detects a Cloudflare-style
  challenge fixture as `blocked` without retrying, html cap.
- CRM `RenderWorkerDriverTest` with `Http::fake`: signing, mapping, unavailable error.
- README: run locally (`npm ci && npx playwright install chromium && npm start`),
  env vars, Docker run, how Ian sets `SCRAPER_RENDER_WORKER_URL/SECRET` in CRM `.env`.

## Status
- 23 Nov 2026, local source implemented on `main`, HEAD
  `19c67e2ec6d58b61df2113301be76d45cc4ac333`; task files remain untracked/unstaged.
  Added `scraper-worker/` (signed Node/Chromium service, isolated contexts,
  request/redirect SSRF guards plus DNS-pinned egress proxy, blocker detection,
  ceilings, fixtures/tests, lockfile, Dockerfile, README and `.env.example`),
  `app/Services/Scraper/Fetch/RenderWorkerDriver.php`, and
  `tests/Unit/Scraper/RenderWorkerDriverTest.php`. A's real FetchRequest/FetchResult
  types appeared during implementation and are used directly; no stubs remain.
  A also supplied the exact `render_worker` config block (including default 60s);
  verified it without editing other config or A's source files.
- Verification on Node **24.21.0**, PHP **8.2.30**, Playwright **1.58.2**:
  - From `scraper-worker/`, `node --test test/security.test.js test/proxy.test.js test/server.test.js test/transport.test.js`
    → **42 passed, 0 failed, 0 skipped**, exit 0, **1.022s**. Covers HMAC/skew,
    reserved/private IPv4/IPv6/metadata, DNS rebinding, exact socket-IP pinning,
    redirect-to-private, HTTP and CONNECT proxy paths, real Playwright HTTP
    transport/cookies, body cap, HTML cap, blockers, capacity/error cleanup and
    redacted logging. This command does not launch Chromium.
  - `/usr/local/opt/php@8.2/bin/php artisan test --filter=RenderWorkerDriverTest`
    → **8 passed / 41 assertions**, exit 0, **1.14s**. Exact wire-byte signing,
    real contract mapping, blocked/truncated hints, unavailable/unreachable errors,
    malformed responses and local HTML cap; `Http::fake`, no DB access required.
  - PHP `-l` on driver, driver test and `config/scraper.php` → PASS;
    `/usr/local/opt/php@8.2/bin/php vendor/bin/pint --test app/Services/Scraper/Fetch/RenderWorkerDriver.php tests/Unit/Scraper/RenderWorkerDriverTest.php`
    → **2 files PASS**, exit 0.
  - `npm ci --cache /private/tmp/exotic-render-npm-cache` → exit 0,
    four packages installed, zero audit vulnerabilities. Node `--check` on all
    five worker source modules → PASS; index remains empty. Docker image/runtime
    has not been built or exercised in this session.
  - `PLAYWRIGHT_BROWSERS_PATH=/private/tmp/exotic-render-browsers npm exec --cache /private/tmp/exotic-render-npm-cache -- playwright install chromium`
    → exit 0; Chromium/headless shell **145.0.7632.6 / v1208** installed in `/private/tmp`.
  - Initial full-suite attempt:
    `PLAYWRIGHT_BROWSERS_PATH=/private/tmp/exotic-render-browsers npm test`
    → exit 1, **35 passed / 8 failed** at that earlier source revision. All eight
    browser cases failed in the launch hook: macOS sandbox denied
    `bootstrap_check_in org.chromium.Chromium.MachPortRendezvousServer … Permission denied (1100)`.
    No browser fixture actually ran. Final browser cases remain **unverified**;
    subsequent non-browser coverage grew to the 42 passing tests above.
    Browser tests remain mandatory in `npm test`; none were skipped or replaced.
- **Acceptance incomplete:** actual Chromium rendering (500ms JS cards, challenge
  stop, browser redirects/subresources, screenshots and context cleanup) still
  needs a browser-permitted session. Docker image/run instructions are supplied,
  but the image was not built or run. No commit, push, deploy, provisioning,
  production access, staging, or outgoing message. Unrelated dirty paths preserved;
  state index left untouched to honor B's explicit file scope.

- 23 Nov (Claude review, outside Codex's sandbox): full `npm test` with
  `PLAYWRIGHT_BROWSERS_PATH=/private/tmp/exotic-render-browsers` → **50/50 PASS, 0 skipped**
  (~11s), including all **8 real-Chromium renderer tests** (500ms JS cards, challenge
  returned as blocked without retries, private literal/redirect/subresource rejection,
  HTML cap, timeout/loop cleanup, cookie isolation, JPEG screenshot). PHP
  `RenderWorkerDriverTest` + A's suite → 19 tests / 140 assertions PASS. Dependencies are
  only `playwright` and `ipaddr.js` (no stealth/CAPTCHA packages).
- End-to-end CRM → local worker (port 3011, throwaway secret, config set in-process only):
  driver registered as available; KenyaRaha via `render_worker` → ready, 78/78 phones,
  `laravel_escort_items`; MassageRepublic → HTTP 403 `cloudflare_challenge`, reported and
  not bypassed. Worker stopped afterwards. Docker build/run still unverified; no hosting.

## Next action
Browser acceptance is now verified locally. Remaining: build/run the Docker image,
choose and provision a host (Ian's decision; not the CRM cPanel box), then set
`SCRAPER_RENDER_WORKER_ENABLED/URL/SECRET` in the CRM `.env`. A owns driver registration and end-to-end CRM source/run
selection. Worker hosting and CRM `.env` activation remain a separate authorized
deployment step; README documents the exact keys.
