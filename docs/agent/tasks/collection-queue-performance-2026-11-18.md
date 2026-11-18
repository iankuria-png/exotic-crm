# Incoming payments workspace load time (18 Nov 2026)

Status: **COMMITTED locally, tested and production-verified read-only; not pushed/deployed.**

## Symptom
`/payments?workspace=collections` took ~25–35 s to show rows. Live browser timing (Ian's session):
`GET /api/crm/collections/receipts` TTFB 24.3 s on load; every other call < 3.1 s. The
**Needs action** and **Already recorded** tabs returned HTTP 504 after ~92–100 s.

## Root cause
`CollectionReceiptStatusService::filter()` built correlated `EXISTS`/`COUNT(*)` subqueries against
`payments` per receipt, matching `reference_root OR transaction_reference OR reference_number`
(plus an alias `EXISTS` per payment row). The OR/alias shape prevents index use, so MariaDB scanned
~44k payments for each candidate receipt. The default list always ran this for the needs-action
badge count, and the open workspace re-polls it, holding a PHP worker each time.

## Fix
`filter()` now selects the cheap-predicate candidate receipts once, resolves code/alias collisions
with batched `IN` lookups on payments/bundles (codes kept as strings; collation-equivalent
upper/rtrim keys), applies the exact already-recorded rules in PHP, and constrains the query by id.
No controller, migration, frontend or config change. `compatibleQuery()` removed (no other callers).
Test-mode check follows the per-row display rule (`forReceipts`), not MariaDB's loose
`json_extract(...) = false` comparison.

## Evidence
- Production SELECT-only read `crm-receipt-queue-perf-read` (exotic-ops run
  `20261118T190256Z-d4792-d9410-read-crm-receipt-queue-perf-read-36dc`, MariaDB 11.4.13,
  2,203 receipts / 44,189 payments, candidate sha256 `354ca047…`, matches the local file):
  needs action 24,921 ms → 102 ms (55 = 55 ids identical); already recorded 99,107 ms → 538 ms
  (1,751 = 1,751 ids identical); 25 MB peak.
- Clean-HEAD isolated copy: 43 tests / 506 assertions (Access, MarketScope, ReceiptStatus incl.
  2 new regressions, ReversalFlag, SalesWorkflow, SubsidiaryAutomation). Pint pass.

## Not done / follow-ups
- Push and Ian's cPanel pull + live timing check pending.
- Secondary load costs seen (not changed): `/api/crm/me` fired 14× on load; `/api/crm/payments`
  (~2.8 s) and `/api/crm/settings/integrations` (~2.9 s) load on this workspace.
- The shared checkout's `CollectionPaymentController.php` is behind HEAD from another session's
  edits; untouched here.
