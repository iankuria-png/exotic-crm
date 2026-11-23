# Lead scraper intelligence (A) — 23 Nov 2026

Owner: Claude (this checkout, `main`). Companion: render worker (B) handed to Codex —
[lead-scraper-render-worker-2026-11-23.md](lead-scraper-render-worker-2026-11-23.md).

## Intent
Ian wants the CRM lead scraper to work on most competitor directory sites, starting with
template-based directories, with a world-class experience: pick or add a site, see at a
glance what technology it runs and what blocks scraping, choose an override, configure
it, scrape multiple pages and import leads. Today 3 of 5 built-in presets are broken and
the CRM cannot tell.

## Evidence (23 Nov)
- Engine: `app/Services/ScraperSourceService.php` — single page HTTP GET via
  `App\Support\DomParserTrait`, CSS selectors, two parser profiles, 5 hard-coded Nairobi
  presets, robots/ToS gates, dry run → commit, dedupe. No pagination, profile follow-up,
  JS, health or tech insight. UI: modal in `resources/js/pages/Leads.jsx` + Settings
  `ScraperConfigPanel.jsx`. Profile-field auto-detect exists in
  `app/Services/PushCampaign/SelectorDetectionService.php`.
- CRM host (mkt read `crm-scraper-capability-read`, run
  `20261123T094822Z-d4792-d9410-read-crm-scraper-capability-read-4c9d`): PHP 8.2.36, exec
  usable, node/npm present, **no Chromium**, 16 CPU/32 GB shared, database queue.
  The check is new and uncommitted in exotic-ops.
- Preset recon from Ian's Mac with the CRM user agent: BedEscorts = WordPress `escortwp`
  theme + WP Rocket + Cloudflare, 111 tel cards, `/page/N/` pagination (works);
  KenyaRaha = Laravel (XSRF + `*_session`), jQuery/Bootstrap, 76 `escort-item-one` cards
  (works); MombasaHot same Laravel template but 1 card (stale preset);
  MassageRepublic and NairobiHot = Cloudflare managed challenge 403 (`cf-mitigated:
  challenge`) — MassageRepublic is wrongly marked supported.

## Goals
1. **Site probe / "at a glance"**: health verdict, technology stack (CDN/WAF, CMS/theme,
   framework, caching, JS libs), blockers with severity and plain-English cause, robots
   and sitemap status, pagination detected, matched template + confidence, sample fields.
2. **Template library** for directory families (EscortWP, Laravel `escort-item-one`,
   generic tel/WhatsApp card heuristic) with automatic selector detection when no template
   matches. Every selector stays overridable.
3. **Multi-page queued runs**: pagination, optional profile follow-up for phone, politeness
   delay, page/profile budgets, live progress, run history, stop when no new results.
4. **Overrides for blockers**: browser capture (Ian/staff open the page in their own
   browser, pass any check as a person, one click sends the rendered page to the CRM),
   saved-page upload/paste, sitemap / WordPress REST routes, slower pace, custom
   headers/cookies (e.g. an age-gate consent cookie), render worker when B is configured.
5. **Configurability**: per-site template, selectors (with live match counts), pagination
   pattern, page/profile limits, delay, follow-profiles, dedupe mode, fetch driver,
   headers/cookies, phone country prefix from the market.
6. **UI**: dedicated Lead Scraper page — site list with health/tech, add-site-by-URL,
   site workspace (Overview, Configure, Runs, Results/import).

## Non-goals / anti-goals
- No automatic Cloudflare/CAPTCHA/Turnstile solving, stealth fingerprints, proxy rotation
  or ban evasion. Challenge-protected sites go through human browser capture or are
  reported as blocked.
- No Chromium on the CRM host (payment credentials live there); rendering is B's worker.
- Not removing robots/ToS acknowledgement gates; not changing lead ownership/assignment.
- No scheduled automatic scraping in this slice (existing `fetch_schedule` stays manual).

## Approaches considered
- **A. In-CRM intelligence (picked)** — PHP probe/templates/pagination/overrides, queued
  jobs, no new infra; pluggable fetch drivers.
- B. A + separate Node/Playwright render worker (picked as parallel Codex track).
- C. A + paid scraping API renderer — rejected for now: per-page cost, third-party data
  path, and vendor auto-unblocking crosses the anti-goal.

## Design
### Fetch drivers (contract shared with B)
`App\Services\Scraper\Fetch\FetchDriver::fetch(FetchRequest): FetchResult`.
- `HttpFetchDriver` (A): Laravel Http, declared CRM user agent, SSRF guard (reject
  non-http(s), private/loopback/link-local/reserved IPs after DNS), size cap 6 MB,
  redirects ≤ 5, custom headers/cookies from source config.
- `CaptureFetchDriver` (A): returns the latest received browser capture/upload for a URL.
- `RenderWorkerDriver` (B, Codex): signed call to the worker; registered only when
  `config('scraper.render_worker.url')` and secret are set.
`FetchResult`: requested_url, final_url, status, headers (lower-cased), html, bytes,
elapsed_ms, driver, error.

### Probe
`SiteProbeService::probe(url, ScraperSource|null)` → `site_profile` JSON:
`{verdict: ready|needs_config|blocked|error, http, tech[], blockers[], robots, sitemap,
pagination, template:{key,confidence,matched_by}, detected_rules, sample[], counts,
probed_at, driver}`. Pieces: `TechFingerprinter` (rule table: headers, cookies, meta
generator, asset paths, inline markers), `BlockerDetector` (cloudflare/sucuri/akamai
challenge, captcha markers, 403/429/5xx, login wall, age gate, JS-required/empty shell,
robots disallow, redirect-to-home/geo), each blocker lists override keys. Results stored
on the source and cached by URL for transient probes.

### Templates
`config/scraper_templates.php`: key, label, match rules, list rules (row, name, phone,
whatsapp, email, link, city, age, image), pagination rule, profile rules. Plus
`AutoSelectorDetector` (repeated parent of tel/wa anchors). Existing `PARSER_PROFILES`
keep working; legacy presets map to template keys.

### Runs
`RunScraperSourceJob` (heavy lane trait, timeout below retry_after) crawls pages with
the source's crawl settings, extracts, optional profile follow-up, records progress in
`scraper_runs.progress` and events in `scraper_runs.result.log`, honours a cancel flag.
Dry run (preview) vs import keeps today's semantics and dedupe; import reuses
`createLeadFromCandidate`. Candidates gain whatsapp/city/age/image when present.

### Data
Migration: `scraper_sources` + `template_key`, `fetch_driver`, `crawl_settings` json,
`site_profile` json, `health_status`, `last_probed_at`. `scraper_runs` + `progress` json,
`pages_fetched`, `cancel_requested_at`. New `scraper_captures` (source/user/platform,
token hash, url, storage path, bytes, status, expires_at, received_at).

### API (`/api/crm/scraper/*`)
Sites list/show/create/update (admin, sub_admin; sales read within market scope as
today's preview), probe (transient URL or source), test selectors, start run (dry/import),
run status/cancel, import preview candidates, capture token create + capture receive
(form POST with single-use token, no session), capture upload/paste.

### UI
New page `/leads/scraper` (`resources/js/pages/LeadScraper.jsx` + components under
`resources/js/components/scraper/`). Leads page "Scrape" button navigates there (one
surgical edit). Settings panel untouched.

```
┌ Lead Scraper ─────────────────────────────── [Market ▾] [+ Add site] ┐
│ Sites (search)          │ BedEscorts · Nairobi       ● Ready  [Run ▾] │
│ ● BedEscorts   WP·CF    │ Overview | Configure | Runs | Results       │
│ ● KenyaRaha    Laravel  │ ┌Verdict──────────┐ ┌Tech───────────────┐  │
│ ◐ MombasaHot   stale    │ │Ready · 111 cards│ │Cloudflare  WordPress│ │
│ ✕ NairobiHot   CF chall │ │10 pages · tel ✓ │ │escortwp  WP Rocket  │ │
│ ✕ MassageRep.  CF chall │ └─────────────────┘ └────────────────────┘ │
│                         │ Blockers: none   Robots: allowed  Sitemap ✓ │
│                         │ Template: EscortWP (96%)  Sample: 5 rows    │
└─────────────────────────┴──────────────────────────────────────────────┘
```

## Acceptance
- Probe of each preset reports tech + blocker correctly (fixture-based tests for
  EscortWP, Laravel family, Cloudflare challenge, captcha, empty JS shell, 429, robots).
- Multi-page dry run on fixtures follows pagination and stops at budget/no-new-results.
- Browser capture round trip creates candidates from captured HTML.
- SSRF guard rejects private targets. Existing scraper tests still pass.
- Browser checks of the new page: add site, probe, configure/test selectors, run, import,
  empty/loading/error states, mobile width.

## Status
- 23 Nov: A implemented in main checkout, LOCAL ONLY (not committed/pushed/deployed, no
  build artifact). Unrelated dirty paths preserved; `routes/api.php` also carries ~9 lines
  of someone else's uncommitted routes, so stage only the scraper hunks.
- Files: `app/Services/Scraper/**` (except Codex's `Fetch/RenderWorkerDriver.php`),
  `app/Http/Controllers/CRM/ScraperController.php`, `app/Jobs/RunScraperSourceJob.php`,
  `app/Models/ScraperCapture.php` + fields on `ScraperSource`/`ScraperRun`,
  `config/scraper.php`, `config/scraper_templates.php`, migration
  `2026_11_23_000001_add_scraper_intelligence.php`, routes (16 auth + 1 public receive),
  `resources/js/pages/LeadScraper.jsx`, `resources/js/components/scraper/*`, router entry,
  Leads "Lead Scraper" button, `tests/Feature/LeadScraperWorkspaceTest.php`,
  synthetic fixtures `tests/Fixtures/scraper/*` (fake numbers only).
- Evidence: `LeadScraperWorkspaceTest` 11/11 + 3 legacy scraper tests = 14 tests / 130
  assertions PASS (SQLite); Pint clean on owned files. Live probes from Ian's Mac:
  BedEscorts ready 116 cards/111 phones/~10 pages (escortwp 99%); KenyaRaha ready 77/77
  (laravel 99%); MombasaHot blocked by robots.txt disallow (+ 1 card); MassageRepublic
  Cloudflare challenge, sitemap found, robots crawl-delay 5 s; NairobiHot path now 404
  (WordPress "lovestory" theme). Browser checks on an isolated SQLite preview (port
  8010): add-site probe, save, live preview run (5 pages, 226 found/223 phones), selective
  import (2 created), blocked-site overrides, capture panel + bookmarklet href, paste
  capture → driver switch → Ready, Configure tab, 375 px mobile (no horizontal overflow).
  Bug found and fixed in browser: byte-wise trim broke UTF-8 names (emoji) → 500.
- Not done / not verified: real bookmarklet capture through a Cloudflare page (needs a
  person), production heavy-queue worker behaviour, production `post_max_size` for
  large captures, image hotlinking (some thumbnails blank), build artifact, migration on
  prod. Legacy Settings scraper panel and Leads scrape modal left in place (modal now
  unreachable from the button).

## Next action
Ian reviews locally; on approval: scoped `public/build` from clean HEAD + task-owned
files, commit, push, cPanel pull + `migrate` (one new migration) + confirm the `heavy`
queue worker runs. Then retire the old Leads scrape modal and Settings panel.
