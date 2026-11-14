# Visitor Demand workspace redesign — 14 November 2026

Owner: Ian Kuria. Scope authorised: identify the Demand UX problems, implement the redesign and ship CRM source/assets. Established CRM ship workflow applies: push origin/main; Ian performs cPanel deployment.

Status: implementation and scoped release build complete, shipping source and matching assets. **Production deployment remains pending.**

## What changed and why

The previous alphabetical table led with unavailable countries, repeated country/website rows and pushed requested profile cities far down the page. Country and website controls occupied separate wide rows, and purchase analytics competed with the attempt-demand task.

The Demand tab now has a compact period strip, adjacent country/website controls and one summary. Markets rank by recorded attempts, with proportional comparison bars and native keyboard-accessible disclosures for every website. Profile cities appear alongside markets on desktop. Phones switch directly between Markets and Profile cities. Each list supports search and eight-row pagination. With history includes historical sites with zero attempts during the selected period; Coverage gaps includes mixed-coverage countries and exposes missing website history. All retains every authorised country.

CSV exports every website in the server-filtered country/site/date selection, including rows hidden by local search, coverage filters or pagination. Copy all cities includes every returned city. The service's returned/total/truncated metadata is visible. Missing tracking remains unavailable; sessions remain per website, and cities remain current cached profile cities. Payment outcomes and original metric drawers remain under Purchases & checkout activity. Counting, collection, authorisation and payment settings are unchanged.

Demand's misleading All time preset was removed: it previously cleared dates and requested today. The period is explicit, and partial/reversed dates show a prompt and suppress overview, pulse and analytics requests while retaining market metadata. Other tabs retain their controls. Phone editable inputs use at least 16px text; narrow dates stack at 320px. Reduced motion disables scoped transitions.

## Review and verification

[Design plan](../../../plans/visitor-demand-ux-2026-11-14/plan.mdx) and generated HTML describe the direction. Plan audit: round 1 NOT READY; date/coverage/export/rollback issues addressed; fresh round 2 READY. Implementation review additionally caught invalid overview requests and phone input zoom sizing; both addressed.

- Scoped release base: `8b64f471b4b731599c152d0dfa4f7c58e70319a3`. Built from an archive of HEAD plus **four owned UI paths**, excluding unrelated dirty billing/collection/UI overlays. [Source hashes](../../../output/visitor-demand-ux-2026-11-14/source-manifest.json).
- `npm run build` in the isolated release archive passes. Existing forecast CSS selector and large bundle warnings retained. Generated entry: `assets/app-DJgra4KM.js`, stylesheet `assets/app-B1WaJdBZ.css`. [Build evidence](../../../output/visitor-demand-ux-2026-11-14/build.txt).
- PHP 8.2 `artisan test --filter=ContactUnlockCountryDemandTest`: **9 tests, 46 assertions pass** on the test's disposable SQLite fixtures. [Result](../../../output/visitor-demand-ux-2026-11-14/backend-tests.txt).
- **50 browser checks pass** against the real WebVisitors component imported from the exact scoped release source and compiled release CSS. [Results](../../../output/visitor-demand-ux-2026-11-14/browser-results.json). Includes ranking, search, pagination, mixed coverage, zero/unavailable, country/site scope, date request parameters, invalid dates, error/retry, loading, truncation, export/copy scope, metric drawer, Overview navigation, keyboard disclosure and reduced motion.
- Widths **320, 390, 768, 1440**: no whole-page overflow; phone view switching and 16px editable input text checked. This is Chromium coverage, not physical iOS/device or full accessibility certification.
- Downloaded CSV independently parsed: all 18 synthetic websites retained while local search showed Ghana; eight unavailable counts preserved. Browser data is entirely synthetic and establishes no live demand counts or sender coverage.
- [Desktop](../../../output/visitor-demand-ux-2026-11-14/desktop.png), [phone cities](../../../output/visitor-demand-ux-2026-11-14/mobile-cities-390.png), [standalone functional preview](../../../output/visitor-demand-ux-2026-11-14/preview.html). The preview uses synthetic responses and an illustrative existing CRM shell. Runtime navigation/header code is unchanged.

## Deployment checklist

1. Preserve deployed overlays, especially the existing flexible-payment/pay-link assets, and back up the active manifest/assets and four owned UI source paths. Inspect the deployed manifest and any local changes before pulling; do not reset the production tree.
2. Pull origin/main through the existing cPanel procedure. Source and compiled assets are included; cPanel does not run npm.
3. **No migration, seeder, configuration change, feature flag or payment enablement is required.**
4. Verify the served entry matches `assets/app-DJgra4KM.js` and stylesheet `assets/app-B1WaJdBZ.css` from the release manifest. Where deployed overlays require a combined build, retain them through the established release procedure rather than overwriting them blindly.
5. Open `/visitors?tab=demand`; check actual country/site/date data, mixed-coverage Tanzania, city ranking, CSV, keyboard disclosure and phone view switching. Confirm the other visitor tabs and existing checkout/payment flows remain available. This presentation release does not establish fresh Ghana/TZ sender acceptance or continuous tracking.

Rollback: restore only the prior four UI source files and manifest/build assets, preserving unrelated overlays. Previous hashed assets are retained in Git. No database/config/payment rollback is needed.

Unrelated local source, pre-existing staging and dirty build manifest are preserved. Generated release assets are added alongside the existing local assets; the committed manifest is the scoped release manifest, while the dirty local manifest remains untouched.
