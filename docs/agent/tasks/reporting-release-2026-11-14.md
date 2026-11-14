# Reporting and country-demand release — 14 November 2026

Source/build commit **`33a77116` is pushed to `origin/main`**. Production deployment remains pending.

Ian authorised shipping the CRM changes. Scope: exact-time/hourly revenue reports and country/site demand with requested profile cities. SEO preparation is outside this CRM release.

## Release construction

Integrated remote `2bdd6d5b` into the existing main checkout before preparing this change. Local pre-existing `052b4aff` only records the earlier Kenya pay-link release. Forty overlapping dirty/untracked paths were privately backed up, temporarily cleared for the merge, and restored byte for byte. Unrelated local overlays remain uncommitted. No unrelated staging existed at intake.

Built from an archive of remote main plus the reviewed task paths, with three-way integration for shared MCP files. This excludes unrelated local billing/collection/UI changes. Source and generated manifest/assets were committed from that exact release. The existing dirty local `public/build/manifest.json` is preserved; it can differ from the committed release manifest. Do not rebuild from the whole dirty checkout and treat it as equivalent.

[Verification](../../../output/reporting-release-2026-11-14/verification.json): 23 tests / 150 assertions pass on both SQLite and disposable MariaDB 10.6.28, including view creation/rollback; 13 embedded dashboard assertions pass. Scoped production frontend build passes. Existing CSS animation/large chunk warnings remain. [Source hashes](../../../output/reporting-release-2026-11-14/source-manifest.json) identify the tested runtime inputs.

The MariaDB harness substitutes only disposable loopback connection setup in copies of the same tests. It does not run production migrations/data or measure production volume. Source test files retain their safe in-memory configuration.

## cPanel deployment checklist

1. Preserve deployed overlays and take backups of the affected application files, current build manifest/assets and database. Current live pay-link/flexible-amount changes have their own deployment evidence; a pull must preserve those overlays. Inspect conflicts instead of resetting the server tree.
2. Pull `origin/main` using the existing cPanel workflow. PHP and generated frontend files are included; cPanel does not run npm.
3. Run only the new reporting-view migration using the server's PHP binary: `php artisan migrate --path=database/migrations/2026_11_13_180000_create_mcp_payment_collections_view.php --force`. Do not sweep unrelated pending migrations into this release.
4. Refresh the existing configuration cache and serving PHP/FPM through the established deployment procedure. Verify actual serving code and the new frontend asset from `public/build/manifest.json`. Existing incident evidence requires proving the serving-FPM refresh, not assuming a CLI opcode clear covers web requests.
5. Leave payment/feature flags and SQL hatch enablement unchanged. If the existing approved read-only SQL account is used, reconcile its SELECT grant and any stored view allowlist for `vw_mcp_payment_collections`; the release does not grant or enable SQL automatically.
6. Through the authorised MCP connector, verify Uganda `from=2026-10-31T15:00:00+03:00`, `to=2026-11-06`, `country=Uganda`, and overnight `from=2026-11-12T20:00:00+03:00`, `to=2026-11-13T05:09:00+03:00`, `bucket=hour`. Reconcile native amounts, categories, new cash versus wallet/service value and site totals with source records. Missing USD FX must stay unavailable. Measure query/view runtime on actual retained volume.
7. In Web Visitors → Demand, verify Ghana and both Tanzania sites, country/site permissions, attempts/sessions and requested profile cities. Verify a fresh signed event arrives from each deployed sender while preserving checkout flags. No history must remain unavailable rather than zero demand. Request Julian's own exact-window acceptance after these checks.

Production deployment and acceptance remain open. This shipping request covers source/build commit and push under the CRM ship workflow; Ian performs the cPanel pull.

## Rollback

Restore the scoped application/build backups and refresh serving PHP/configuration. If this view migration is still the latest migration, roll back only its exact path and step; do not roll back unrelated migrations. The migration's down operation drops only `vw_mcp_payment_collections`. No payment, subscription, feature or customer-data rollback is required by this read-only reporting change. Preserve other deployed overlays throughout.

Owning tasks: [hourly](crm-hourly-reporting-2026-11-13.md), [country demand](visitor-demand-country-2026-11-13.md).
