# Project Context: WordPress

Date: 2026-04-03
Project: Exotic WordPress site
Repo root: `/Users/ian/Local Sites/exotic/app/public`

## 1. What this codebase is

This is a full local WordPress site and core checkout, not just a single plugin repository.

Detected shape:

- WordPress core checkout
- site-level WordPress install
- `wp-content/plugins` and `wp-content/themes` present
- no repo-native Composer or Node toolchain at site root
- local environment with `WP_DEBUG` enabled and `WP_ENVIRONMENT_TYPE=local`

Source of truth for that classification came from the WordPress triage skill run against the site root.

## 2. Core environment

Observed signals:

- WordPress core version `6.9`
- `WP_HOME` and `WP_SITEURL` set to `http://exotic.local`
- `WP_DEBUG=true`
- `WP_DEBUG_LOG=true`
- `WP_DEBUG_DISPLAY=false`
- `WP_ENVIRONMENT_TYPE=local`
- no `mu-plugins` directory detected
- no root `composer.json`
- no root `package.json`
- no root PHPUnit/Playwright/Jest harness detected

Key file:

- [wp-config.php](/Users/ian/Local%20Sites/exotic/app/public/wp-config.php)

## 3. CRM-relevant custom WordPress surfaces

Custom and integration-relevant plugins/themes currently present:

- `exotic-crm-sync`
- `wp-laravel-sso`
- `exotic-age-gate`
- `exotic-campaigns`
- `escortwp`
- `escortwp-child`

Billing and CRM bridge relevance is highest in:

- `/wp-content/plugins/exotic-crm-sync`
- `/wp-content/plugins/wp-laravel-sso`

Representative files:

- [exotic-crm-sync.php](/Users/ian/Local%20Sites/exotic/app/public/wp-content/plugins/exotic-crm-sync/exotic-crm-sync.php)
- [class-wallet-sync-endpoint.php](/Users/ian/Local%20Sites/exotic/app/public/wp-content/plugins/exotic-crm-sync/includes/class-wallet-sync-endpoint.php)
- [class-wallet-settings-page.php](/Users/ian/Local%20Sites/exotic/app/public/wp-content/plugins/exotic-crm-sync/includes/class-wallet-settings-page.php)
- [class-activation-endpoint.php](/Users/ian/Local%20Sites/exotic/app/public/wp-content/plugins/exotic-crm-sync/includes/class-activation-endpoint.php)
- [class-expiry-endpoint.php](/Users/ian/Local%20Sites/exotic/app/public/wp-content/plugins/exotic-crm-sync/includes/class-expiry-endpoint.php)
- [wp-laravel-sso.php](/Users/ian/Local%20Sites/exotic/app/public/wp-content/plugins/wp-laravel-sso/wp-laravel-sso.php)

## 4. What the CRM program should assume about WP

Assume this site is a consumer of CRM-owned billing contracts, not the place where billing rules are authored.

That means:

- CRM owns billing logic, routing, provider selection, diagnostics backbone, and compatibility decisions
- WordPress consumes versioned payloads and endpoints from CRM
- WordPress plugin behavior must remain parity-safe until the CRM contract says otherwise
- WordPress cutover should lag CRM model hardening, not lead it

## 5. Constraints that matter for the billing refactor

- there is no strong site-root test harness to lean on for automated safety at the WordPress root
- CRM-to-WP integration safety therefore depends heavily on contract tests, payload fixtures, sync evidence, and targeted plugin-level verification
- current CRM sync behavior includes anchor-client delivery semantics, active-environment credential push, and specific wallet payload fields that must not change casually
- the plugin layer is likely to be more fragile than the CRM service layer because it sits inside a full site stack with many other plugins installed

## 6. WordPress-side guardrails for agents

- do not treat WordPress as ready for breaking contract changes
- do not change CRM payload meaning before the WP parity suite is green
- do not land CRM method/routing changes that alter self-service behavior unless WP is known to consume the new contract
- do not touch live credential or wallet-auth behavior without explicit rollback notes
- prefer additive versioned payload handling over in-place semantic replacement

## 7. WP coordination expectations by phase

- `Phase 0A`
  - WP contract validation only
- `Phase 0B`
  - WP fixture and automation preparation
- `Phase 1`
  - CRM-only
- `Phase 2`
  - compatibility projection and parity validation
- `Phase 4`
  - CRM and WP coordination required if route/method visibility changes
- `Phase 6`
  - CRM and WP coordination required because wallet state, renewal methods, and sync transport are shared behavior
- `Phase 8`
  - no live cutover without WP parity evidence

## 8. What “good” looks like on the WP side

Good WP-facing changes:

- are versioned
- preserve parity until deliberately cut over
- come with fixture-based verification
- do not assume missing tooling means missing risk
- keep authentication, wallet, and activation paths stable while the CRM evolves underneath
