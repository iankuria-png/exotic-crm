# Exotic CRM render worker

Small Node 20.12+ / Playwright Chromium service for JavaScript directory pages.
Run it on Ian's Mac for trials or on a separate host. The CRM cPanel host holds
payment credentials and must not run this browser. Nothing here provisions or deploys a host.

## Local run

From this directory:

```sh
cp .env.example .env
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
# Put that generated value into .env as SCRAPER_RENDER_WORKER_SECRET.
npm ci
npx playwright install chromium
npm start
```

The secret must have at least 32 characters. `.env` is ignored and loaded by the
startup script. The default address is `127.0.0.1:3001`.

```sh
curl http://127.0.0.1:3001/healthz
npm test
```

Tests include actual Chromium renders of local fixtures: listings appear after
500 ms, Cloudflare challenge stops without retries, redirect/subresource SSRF,
cookie isolation, JPEG capture, HTML truncation and timeout cleanup. The tests
resolve fixture names to a public IP and inject a local socket dialer; production
private-address restrictions have no environment bypass. Security, proxy and
HTTP-service tests can also run independently when browser launch is unavailable:

```sh
node --test test/security.test.js test/proxy.test.js test/server.test.js test/transport.test.js
```

| Worker environment | Default | Meaning |
| --- | --- | --- |
| `SCRAPER_RENDER_WORKER_SECRET` | required | Shared HMAC secret, at least 32 characters |
| `HOST` | `127.0.0.1` | Listener; Docker sets `0.0.0.0` inside the container |
| `PORT` | `3001` | Listener port |
| `MAX_CONCURRENCY` | `2` | Active renders, 1–16; overflow returns HTTP 429 |
| `BLOCK_IMAGES` | `true` | Set `false` to load images; fonts/media stay blocked |
| `PLAYWRIGHT_BROWSERS_PATH` | Playwright default | Optional Chromium installation directory |

For a local dependency-cache permission problem, use `npm ci --cache /tmp/exotic-render-npm-cache`.
On a sandboxed Mac, Chromium may be denied Mach bootstrap registration; the full
suite needs a terminal/session that permits Chromium. Do not disable or skip the
browser tests to claim acceptance.

## Docker

The Playwright image and npm dependency are pinned to the same version.
The service runs as the image's unprivileged `pwuser`.

```sh
docker build -t exotic-render-worker .
docker run --rm --init --shm-size=1g \
  -p 127.0.0.1:3001:3001 --env-file .env \
  --memory=2g --cpus=2 exotic-render-worker
```

To run tests in the image: `docker run --rm --init --shm-size=1g exotic-render-worker npm test`.
Expose a separate host through HTTPS and restrict ingress to the CRM. Signed
bodies include cookies/headers, so use HTTPS when crossing machines. A localhost
URL on the production CRM refers to the CRM host, not Ian's Mac.

## CRM configuration

Set these in the CRM `.env` only when the worker address is reachable from CRM:

```dotenv
SCRAPER_RENDER_WORKER_ENABLED=true
SCRAPER_RENDER_WORKER_URL=https://render.example.com
SCRAPER_RENDER_WORKER_SECRET=<same-generated-private-secret-as-worker>
SCRAPER_RENDER_WORKER_TIMEOUT=60
```

`config/scraper.php` already exposes `render_worker.enabled`, `url`, `secret`, and
`timeout_seconds` (60). Refresh the CRM config cache through its normal deployment
workflow after changing `.env`. Track A owns driver registration and source selection.
`RenderWorkerDriver` is available only when enabled and both URL and secret are set.
It returns explicit errors on unavailability and never silently falls back.

## Signed API

`POST /v1/render`, `Content-Type: application/json`:

```json
{"url":"https://example.com/directory","wait_until":"domcontentloaded","wait_for_selector":".card","timeout_ms":30000,"scroll_steps":0,"headers":{},"cookies":[],"user_agent":"ExoticCRMLeadBot/1.0 (+https://crm.exotic-online.com)","screenshot":false}
```

Headers are `X-Scraper-Timestamp: <Unix seconds>` and
`X-Scraper-Signature: <hex HMAC-SHA256(secret, timestamp + "\n" + exact raw JSON bytes)>`.
Skew beyond 300 seconds or a bad signature returns 401. Keep both clocks synchronized.
The signature covers exact bytes, including whitespace and Unicode encoding.

Successful responses include `ok`, `final_url`, target `status`, `title`, `html`,
UTF-8 `bytes`, `elapsed_ms`, `truncated`, `blocked`, and optional
`screenshot_jpeg_base64`. Target 403/429/5xx pages remain successful worker captures
with `blocked.type=http_error` unless a more specific challenge is detected.
Block types are `cloudflare_challenge`, `captcha`, `login_wall`, `http_error`.
Scripts and further requests stop when a blocker is detected; no solver, stealth,
proxy rotation or challenge retry is implemented. Detection is heuristic.

Errors return `{ "ok": false, "error": "..." }`: `bad_request` (400;
401 authentication, 413 body cap, 429 capacity), `ssrf_blocked` (403),
`navigation_failed` (502), `timeout` (504). The request-body limit is 256 KiB.
Navigation/selector/scroll/screenshot share a deadline of at most 45 seconds;
at most five scroll steps and five redirects are allowed. Use a selector to wait
for JS listings; `domcontentloaded` alone does not wait for delayed content.
HTML is capped at 6 MiB, preserving UTF-8 boundaries, with `truncated=true`.
Screenshots cover the fixed viewport, not an unbounded full page.

`GET /healthz` is unsigned and returns only `{ok, version, browser_version, inflight}`.
Logs contain hostname, HTTP worker status, milliseconds and blocked type only.
HTML, cookies, headers, paths and URL query strings are never logged.

## Egress and isolation

Each render creates one context and a loopback-only egress proxy, then closes both.
Only HTTP/HTTPS targets are allowed. Every intercepted request/redirect resolves
DNS and rejects non-public IPv4/IPv6 addresses (including mixed public/private
answers). At socket connection time the proxy validates DNS again and connects
to the exact public IP it validated, preventing a second DNS lookup/rebinding.
Private subresource attempts fail the render with `ssrf_blocked`.

Redirects are fetched one hop at a time with `route.fetch(maxRedirects: 0)` and
fulfilled back to Chromium so every new hop is intercepted. Custom headers are
applied only to the initial origin; cookies use Chromium's domain jar and input
cookies must match the initial hostname. Service workers, WebSockets, downloads,
popups, media and fonts are disabled; images are blocked by default.

Implementation references: [Playwright routing](https://playwright.dev/docs/api/class-route#route-fetch),
[context proxy/cookie isolation](https://playwright.dev/docs/api/class-browser#browser-new-context),
and [ipaddr.js address ranges](https://github.com/whitequark/ipaddr.js).
