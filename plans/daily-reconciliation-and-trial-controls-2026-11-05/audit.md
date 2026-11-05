# Independent plan audit — 5 November 2026

Plan: [plan.mdx](plan.mdx). Planning only; no application implementation.

## Round 1 — NOT READY

Independent auditor inspected the actual CRM source and reported:

1. BLOCKER: SEO Boost also creates discretionary free trials and must be included in the market policy, authorization, UI and caller matrix.
2. BLOCKER: paid payment recovery rejects noncash and finalizes paid deals; the trial execution adapter must be specified instead of assumed compatible.
3. SHOULD-FIX: unused PIN authorizations must be invalidated when the global PIN rotates independently of the market policy version.

Changes made: SEO Boost now requires frozen batch/item authorization before financial records or activation; a new durable trial service explicitly uses a single-dispatch relative WordPress adapter with uncertainty holds and a local-only finalizer; paid recovery remains unchanged. A non-secret PIN generation binds authorization to the current rotation. Added operation schema, recovery endpoint, source/UI paths and verification cases. Also clarified flag/reopen transitions, one unresolved correction request per review and the exact production evidence window.

## Round 2 — NOT READY

Fresh source audit confirmed the single-dispatch adapter matches the current POST transport and the earlier findings were addressed. One SHOULD-FIX remained: all writers replace the same wallet_system_config JSON row; a stale unrelated save could restore an old trial PIN hash/generation.

Changes made: explicitly require one locked fresh-read mutation helper across saveSystemConfig/updateOperatorPin/updateFreeTrialPin/updateDiscountPin/updateDiscountConfig, caller-owned field allowlists, preservation of the latest secret/generation, generation persistence/normalization/projection coverage, and atomic PIN verification/generation capture. Dispatch rechecks the same snapshot. Added real MySQL rotation-vs-save/authorization races.

## Round 3 — READY

A fresh third auditor read the revised plan and actual source. No BLOCKER or SHOULD-FIX findings remain. Verified the five shared settings writers, unique configuration key, PIN storage/projection paths, trial entry points including SEO Boost, single-send WordPress POST transport, paid recovery noncash exclusion, bundle grouping and legacy rejection effects. Confirmed that PIN rotation races, trial uncertainty, review-only decisions, scoped access, UI states and rollback are covered without changing paid recovery rules.

Final verdict: READY for Ian's plan review; not an implementation or deployment acceptance.

## Artifact checks

Typed plan renders successfully. Eight static wireframes; no external load dependencies in the rendered HTML. Playwright CLI could not open the local file because its browser blocks file URLs; the owned session was closed. No preview server started. Browser visual acceptance is not claimed. Application workflow tests are proposed for implementation, not executed during planning.
