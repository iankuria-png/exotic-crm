# WP Plugin P1+P2 + CRM Bulk Switch (Path A) Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship the two `exotic-crm-sync` WordPress plugin additions the Auto Optimize engine depends on — **P1** (media `width`/`height`/`filesize`) and **P2** (`/analytics/bulk` with a flat, cached contract) — and switch the CRM's `AutoOptimizeMarketStats` onto bulk. This fixes a **production-breaking correctness bug** (the optimizer currently selects nobody against the real plugin) and the **N× analytics recompute** at the same time.

**Architecture:** Both plugin changes are purely additive (new keys on the media response; a brand-new analytics route). The bulk endpoint emits exactly the flat shape the CRM already reads, and caches the computed metric map across pages with a short-lived transient so paging doesn't rebuild it. The CRM swaps one Wp call and consumes the server's authoritative market averages.

**Tech Stack:** PHP 7.4+/WordPress REST API, PHPUnit 9.6 (`composer test`) on the plugin side; Laravel 10 / PHP 8.2, PHPUnit (SQLite) on the CRM side.

---

## Context — why this is needed (verified against source)

The exploration + audit established three load-bearing facts:

1. **`/analytics/rankings` nests its per-profile metrics** (`class-analytics-endpoint.php:313-338`): views live at `totals.profile_view.total`, contact at `contact_rate_percent`, engagement at `engagement_score`, keyed by `post_id`.
2. **The CRM `AutoOptimizeMarketStats` reads flat keys** (`AutoOptimizeMarketStats.php:70-79`): `views`, `contact_rate`, `engagement`, `wp_post_id`. None of these exist in the rankings payload → every profile resolves to **0** → market averages 0 → `AutoOptimizeSelectionService`'s `$averages['views'] > 0` guards short-circuit → **nobody is selected.** All 47 CRM tests pass only because they mock the flat shape the plugin never returns.
3. **`get_rankings` rebuilds the full metric map on every page request** (`:293-326`, no transient) — paging a 4,000-profile market recomputes ~40×.

Path A fixes #2 by making bulk's contract flat (CRM reads it directly, unchanged), and fixes #3 with a cross-page cache.

### Verified internals the plan depends on
- `resolve_period($from_param, $to_param)` — `:1774`
- `load_posts_by_statuses($statuses)` returns `[post_id => row]` — `:1828`
- `build_metrics_map($post_ids, $from, $to, $post_rows = null)` — **post IDs first** — `:1018`, called as `build_metrics_map(array_keys($posts), $from, $to, $posts)` at `:294`
- Each metric row has `post_id`, `name`, `totals.<event>.{total,unique}`, `contact_rate_percent`, `engagement_score` — set at `:1167-1173`, read at `:313-314`
- `compute_market_averages($metrics)` returns `profile_view => ['total'=>…,'unique'=>…]`, `contact_rate_percent` (flat float), `engagement_score` (flat int) — **no `avg_*` keys** — `:1403-1414`
- Auth: `check_permissions` → `Exotic_CRM_Sync_Auth::check_admin_or_sync_token` (same as rankings)
- CRM `WpSyncService::get(string $path, array $params = [])` private — `:373`

---

## Plugin path
`/Users/ian/Local Sites/exotic/app/public/wp-content/plugins/exotic-crm-sync/`

---

## Task 1 — P1: media dimensions (additive)

**File:** `includes/class-media-endpoint.php`, method `format_attachment()` (`:299-314`).

**Step 1 — append three nullable keys** (existing six untouched):
```php
$meta = wp_get_attachment_metadata((int) $attachment->ID);
$meta = is_array($meta) ? $meta : [];
$file = get_attached_file((int) $attachment->ID);

return [
    'id'          => (int) $attachment->ID,
    'url'         => wp_get_attachment_url((int) $attachment->ID),
    'filename'    => wp_basename($file),
    'mime_type'   => get_post_mime_type((int) $attachment->ID),
    'uploaded_at' => (string) $attachment->post_date_gmt,
    'is_main'     => (int) $attachment->ID === (int) $mainImageId,
    // P1 additions — null-safe for videos / cloud-hosted / missing files
    'width'    => isset($meta['width'])  ? (int) $meta['width']  : null,
    'height'   => isset($meta['height']) ? (int) $meta['height'] : null,
    'filesize' => isset($meta['filesize'])
        ? (int) $meta['filesize']                                  // WP ≥6.0 stores this — no disk stat
        : (($file && file_exists($file)) ? (int) filesize($file) : null),
];
```

**Step 2 — new test** `tests/MediaEndpointTest.php`:
- existing six keys still present (backward-compat contract)
- a real image attachment returns numeric `width`/`height`/`filesize`
- a video / no-metadata attachment returns `width=null`, `height=null`
- a missing file returns `filesize=null`
- wrapper keys `client_post_id`, `main_image_id`, `total`, `data` still present (`:93-98`)

**Step 3 — run** `composer test`; expect green.

---

## Task 2 — P2: `/analytics/bulk` endpoint (new route, cached, flat)

**File:** `includes/class-analytics-endpoint.php`.

**Step 1 — register the route** inside `register_routes()` (alongside `:66-109`):
```php
register_rest_route($namespace, '/analytics/bulk', [
    'methods'             => 'GET',
    'callback'            => [$this, 'get_bulk'],
    'permission_callback' => [$this, 'check_permissions'],
    'args'                => [
        'from'     => ['type' => 'string'],
        'to'       => ['type' => 'string'],
        'status'   => ['type' => 'string', 'default' => 'publish'],
        'page'     => ['type' => 'integer', 'default' => 1],
        'per_page' => ['type' => 'integer', 'default' => 200],
    ],
]);
```

**Step 2 — `get_bulk()` handler** (new method). Mirrors `get_rankings` for loading, but: lean projection, **flat** contract, **deterministic `post_id ASC` ordering** (stable pagination independent of metric values), and a **cross-page transient cache**.
```php
public function get_bulk($request)
{
    $status = sanitize_key((string) ($request->get_param('status') ?: 'publish'));
    $allowed = ['publish', 'private', 'draft', 'pending'];
    $statuses = in_array($status, $allowed, true) ? [$status] : ['publish'];

    [$from, $to] = $this->resolve_period($request->get_param('from'), $request->get_param('to'));

    $page     = max(1, absint($request->get_param('page') ?: 1));
    $per_page = min(500, max(1, absint($request->get_param('per_page') ?: 200)));

    // Cross-page cache: build the lean set ONCE per (status, from, to); reuse for all pages.
    $cache_key = 'exotic_crm_bulk_' . md5($status . '|' . $from . '|' . $to);
    $cached = get_transient($cache_key);

    if (is_array($cached) && isset($cached['profiles'], $cached['market_averages'])) {
        $lean      = $cached['profiles'];
        $averages  = $cached['market_averages'];
    } else {
        $posts   = $this->load_posts_by_statuses($statuses);
        $metrics = $this->build_metrics_map(array_keys($posts), $from, $to, $posts);

        // Lean, FLAT per-profile projection (matches CRM's expected keys exactly)
        $lean = [];
        foreach ($metrics as $m) {
            $lean[] = [
                'wp_post_id'   => (int)   $m['post_id'],
                'views'        => (int)   ($m['totals']['profile_view']['total'] ?? 0),
                'contact_rate' => (float) ($m['contact_rate_percent'] ?? 0),
                'engagement'   => (int)   ($m['engagement_score'] ?? 0),
            ];
        }
        // Deterministic ordering → stable pagination even if cache rebuilds mid-scan
        usort($lean, fn ($a, $b) => $a['wp_post_id'] <=> $b['wp_post_id']);

        // FLAT market averages from the (nested) compute_market_averages()
        $raw = $this->compute_market_averages($metrics);
        $averages = [
            'views'        => (float) ($raw['profile_view']['total'] ?? 0),
            'contact_rate' => (float) ($raw['contact_rate_percent'] ?? 0),
            'engagement'   => (float) ($raw['engagement_score'] ?? 0),
        ];

        set_transient($cache_key, ['profiles' => $lean, 'market_averages' => $averages], 120);
    }

    $total       = count($lean);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $offset      = ($page - 1) * $per_page;

    return rest_ensure_response([
        'period'          => ['from' => $from, 'to' => $to],
        'total_profiles'  => $total,
        'page'            => $page,
        'per_page'        => $per_page,
        'total_pages'     => $total_pages,
        'market_averages' => $averages,                  // FLAT, on every page
        'profiles'        => array_values(array_slice($lean, $offset, $per_page)),
    ]);
}
```

**Notes / decisions baked in:**
- **Cache value is the lean set only** (~4 ints/profile), not the full nested metric map — small even at 20k profiles. TTL 120s comfortably covers a back-to-back CRM page loop; 2-min staleness is fine for approximate analytics.
- **`post_id ASC` ordering** guarantees a profile never jumps pages if the transient expires mid-scan (membership only shifts on publish/unpublish, negligible in 2 min).
- Cache key is isolated per `(status, from, to)` — different markets/windows never collide.
- No event-write invalidation hook needed (time-based only).

**Step 3 — new test** `tests/AnalyticsBulkEndpointTest.php` (see Test Infra below):
- response shape: `period, total_profiles, page, per_page, total_pages, market_averages, profiles`
- each profile has **exactly** `{wp_post_id, views, contact_rate, engagement}` (no extra/nested keys)
- `market_averages` is flat `{views, contact_rate, engagement}` and present on **page 2** as well as page 1
- pagination: 3 seeded profiles, `per_page=2` → `total_pages=2`; page 2 has 1 profile; ordering stable by `wp_post_id`
- seeded events produce **non-zero** views/engagement (proves the projection reads the right source keys)
- **regression:** `get_rankings()` and `get_profile_analytics()` responses unchanged (call both, assert original top-level keys still present)

**Step 4 — run** `composer test`; expect green.

---

## Task 3 — CRM: add `getAnalyticsBulk` + switch `AutoOptimizeMarketStats` to it

**File:** `app/Services/WpSyncService.php` — add wrapper mirroring `getAnalyticsRankings` (`:155`):
```php
/** Fetch lean, cached bulk analytics for one platform (flat per-profile contract). */
public function getAnalyticsBulk(array $params = []): array
{
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
    return $this->get('/analytics/bulk', $params);
}
```

**File:** `app/Services/AutoOptimize/AutoOptimizeMarketStats.php` — in `fetchAndAggregate()`:
1. Call `getAnalyticsBulk` instead of `getAnalyticsRankings`.
2. The existing flat per-profile reader (`:70-79`) now **matches the contract** — keep it (it was the right shape all along; the source was wrong).
3. **Consume the server's `market_averages`** when present (authoritative, and correct even when the last page is a partial slice) instead of recomputing from `perProfile`; fall back to the local recompute only when the server omits it:
```php
// after the pagination loop
$serverAverages = $lastResponse['market_averages'] ?? null;
if (is_array($serverAverages)) {
    $averages = [
        'views'        => (float) ($serverAverages['views'] ?? 0),
        'contact_rate' => (float) ($serverAverages['contact_rate'] ?? 0),
        'engagement'   => (float) ($serverAverages['engagement'] ?? 0),
    ];
} else {
    // existing local recompute from $perProfile (kept as fallback)
}
```
   (Capture `$lastResponse` from the final loop iteration.)
4. Keep the `count($rows) < $perPage` stop; set `$perPage = 200` to match the bulk default.

**File:** `tests/Unit/AutoOptimize/AutoOptimizeMarketStatsTest.php` — update the 4 existing tests to mock **`getAnalyticsBulk`** (not `getAnalyticsRankings`) returning the flat bulk shape incl. `market_averages`; add one assertion that the **server `market_averages` is used** verbatim (distinct from the per-profile recompute).

> `AutoOptimizeSelectionServiceTest` mocks `AutoOptimizeMarketStats` directly, so it is unaffected.

---

## Task 4 — CRM: relax the small-market guard (scale now addressed)

**File:** `app/Services/AutoOptimize/AutoOptimizeSelectionService.php` — `max_unoptimized_market_size` was a P2-pending safety cap. With bulk's cross-page cache, raise the default and make it explicit.

**Step 1** — create `config/auto_optimize.php`:
```php
<?php
return [
    // With /analytics/bulk caching the metric map across pages, large markets are safe.
    'max_unoptimized_market_size' => (int) env('AUTO_OPTIMIZE_MAX_MARKET_SIZE', 5000),
];
```
**Step 2** — leave the `config('auto_optimize.max_unoptimized_market_size', 500)` call as-is (now resolves to 5000); the slice guard stays as a backstop.

**Step 3** — update the Settings "large-market support pending P2" notice copy (if present in `AutoOptimizePanel.jsx`) to reflect that bulk is live. *(Skip if the notice was never rendered.)*

---

## Verification

### Plugin (PHPUnit — `composer test` in the plugin dir)
1. `MediaEndpointTest` green — existing six keys intact + three nullable additions behave.
2. `AnalyticsBulkEndpointTest` green — flat contract, per-page `market_averages`, stable pagination, non-zero seeded metrics, rankings/per-profile **unchanged**.
3. All 6 existing Engagement tests still green.

### Plugin test infra (the real P2 effort — do this in Task 2 Step 3)
- `tests/AnalyticsBulkEndpointTest.php` must install the analytics schema in `setUp()` (call the schema creator used by activation, e.g. `Exotic_CRM_Analytics_Schema::create_tables()` / the migrate hook) so `wp_profile_analytics_events`/`_daily` exist on the test DB.
- Seed a couple of `escort` CPT posts (pattern from `EngagementCreateTest::makeProfile()`) and insert rows into `wp_profile_analytics_events` (or `_daily`) so `build_metrics_map` returns non-zero — this is what proves the projection source keys are correct.

### CRM (PHPUnit — SQLite)
4. `php artisan test --filter='AutoOptimize|Seo'` — full suite green after the MarketStats mock update (expect 68 → still green).
5. New/updated `AutoOptimizeMarketStatsTest`: feeding a realistic **flat bulk** payload yields **non-zero** `perProfile` + averages — the assertion that would have caught BLOCKER 1.

### End-to-end smoke (Local by Flywheel running)
6. `GET /wp-json/exotic-crm-sync/v1/clients/{id}/media` → existing keys + `width/height/filesize`.
7. `GET …/analytics/bulk?per_page=5&from=2026-05-01&to=2026-06-06` → flat shape; fetch page 1 then page 2 → consistent ordered slices, `market_averages` on both.
8. `GET …/analytics/rankings?per_page=5` and `…/analytics/{id}` → shapes **unchanged** (regression).
9. **The proof BLOCKER 1 is fixed:** `/usr/local/opt/php@8.2/bin/php artisan crm:run-auto-optimize --platform=<id> --force` against local WP → the run selects a **non-zero** candidate set and queues items (previously zero).

---

## Files changed — complete list

| File | Change | Type |
|---|---|---|
| `…/includes/class-media-endpoint.php` | +3 keys in `format_attachment()` | Additive |
| `…/includes/class-analytics-endpoint.php` | +`get_bulk()` method, +1 route, +transient cache | Additive |
| `…/tests/MediaEndpointTest.php` | **new** | New |
| `…/tests/AnalyticsBulkEndpointTest.php` | **new** (incl. schema bootstrap) | New |
| `app/Services/WpSyncService.php` | +`getAnalyticsBulk()` | Additive |
| `app/Services/AutoOptimize/AutoOptimizeMarketStats.php` | switch to bulk; consume server averages | Modify |
| `tests/Unit/AutoOptimize/AutoOptimizeMarketStatsTest.php` | mock `getAnalyticsBulk`; assert server averages | Modify |
| `config/auto_optimize.php` | **new** — market-size cap | New |

## Regression guarantees
- Plugin: bulk is a **new** route; rankings/per-profile/media wrappers keep every original key. Media adds only nullable keys. Existing consumers that haven't migrated keep working.
- CRM: the MarketStats swap is contract-aligned; the only test churn is re-pointing 4 mocks from `getAnalyticsRankings` → `getAnalyticsBulk`. Selection/Apply/Engine tests untouched.
- The new CRM test asserting non-zero `perProfile` from a realistic payload is the permanent guard against the flat-vs-nested class of bug recurring.

## Sequencing
P1 → P2 (plugin, independently testable) → CRM `getAnalyticsBulk` + MarketStats switch + test update → config cap → end-to-end smoke (step 9 is the acceptance gate).
