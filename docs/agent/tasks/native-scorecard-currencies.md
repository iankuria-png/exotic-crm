# Weekly scorecard native currencies

6 November 2026. Ian requested toggleable native-currency reporting with world class UI/UX, then explicitly authorized fleet ops and shipping when complete. He clarified this is the only request. Authorization covers scoped implementation, commit/push, backup, exact preview, no-migration deployment/cache/health and independent verification; no messages or business-data repairs.

## Implementation and evidence

[Visual plan](../../../plans/native-scorecard-2026-11-06/plan.mdx) and rendered HTML: audited READY after two rounds. Corrected local wall-clock archive boundaries, stable unresolved-currency keys and source-labelled recovery amounts. Original published report bodies, commentary and reporting amounts retained. Historical native enrichment runs only after existing recipient/expiry authorization and is labelled with capture time. Future reports retain their own native snapshot. Reporting view defaults to the original currency; native revenue cards use an explicit currency picker, market/staff amounts stay separate, churn remains a labelled reporting estimate. PDF and Markdown use the selected view.

Scoped checks: AiBriefingTest + NativeScorecardTest 28 passed / 148 assertions (PHP8.2, SQLite :memory:); four browser fixture journeys pass at320/390/1440px including keyboard/reduced-motion/print/clipboard/empty/unavailable states. Exact isolated production build from HEAD612b8ce8 plus six task runtime files, excluding all unrelated working changes. Existing CSS optimizer/bundle-size warnings retained. Mobile table containment repaired; existing monetary-string double-formatting that rendered NaN repaired and covered by browser assertion.

Live preflight f9c8 and source/archive baseline81f4: production/local HEAD612b8ce8, affected source exact, Week44 archive22 /10cards. Three remote scanner test edits preserved. No pending migration required for this feature. Private evidence: output/native-scorecard-2026-11-06/; Ops var/ns6 runner and var/native-scorecard-2026-11-06/ evidence. Two local ControlPath-length refusals preceded connection; shortened worktree and regenerated normal harness SSH config, with trust/key settings unchanged.

## Delivery

LOCAL TESTED; production deployment authorized and being prepared. No migration, report regeneration, SMS or exchange-rate policy change. Existing local public/build manifest and unrelated work preserved; matching isolated manifest staged directly with task assets. Next: exact release commit/push, guarded backup/preview/fast-forward, served PHP/assets and independent native sum/controller/archive acceptance; update actual live status afterwards.
