# Weekly scorecard native currencies

6 November 2026. Ian requested toggleable native-currency reporting with world class UI/UX, then explicitly authorized fleet ops and shipping when complete. He clarified this is the only request. Authorization covers scoped implementation, commit/push, backup, exact preview, no-migration deployment/cache/health and independent verification; no messages or business-data repairs.

## Implementation and evidence

[Visual plan](../../../plans/native-scorecard-2026-11-06/plan.mdx) and rendered HTML: audited READY after two rounds. Corrected local wall-clock archive boundaries, stable unresolved-currency keys and source-labelled recovery amounts. Original published report bodies, commentary and reporting amounts retained. Historical native enrichment runs only after existing recipient/expiry authorization and is labelled with capture time. Future reports retain their own native snapshot. Reporting view defaults to the original currency; native revenue cards use an explicit currency picker, market/staff amounts stay separate, churn remains a labelled reporting estimate. PDF and Markdown use the selected view.

Scoped checks: AiBriefingTest + NativeScorecardTest 28 passed / 148 assertions (PHP8.2, SQLite :memory:); four browser fixture journeys pass at320/390/1440px including keyboard/reduced-motion/print/clipboard/empty/unavailable states. Exact isolated production build from HEAD612b8ce8 plus six task runtime files, excluding all unrelated working changes. Existing CSS optimizer/bundle-size warnings retained. Mobile table containment repaired; existing monetary-string double-formatting that rendered NaN repaired and covered by browser assertion.

Live preflight f9c8 and source/archive baseline81f4: production/local HEAD612b8ce8, affected source exact, Week44 archive22 /10cards. Three remote scanner test edits preserved. No pending migration required for this feature. Private evidence: output/native-scorecard-2026-11-06/; Ops var/ns6 runner and var/native-scorecard-2026-11-06/ evidence. Two local ControlPath-length refusals preceded connection; shortened worktree and regenerated normal harness SSH config, with trust/key settings unchanged.

## Delivery

LOCAL TESTED; production deployment authorized and being prepared. No migration, report regeneration, SMS or exchange-rate policy change. Existing local public/build manifest and unrelated work preserved; matching isolated manifest staged directly with task assets. Next: exact release commit/push, guarded backup/preview/fast-forward, served PHP/assets and independent native sum/controller/archive acceptance; update actual live status afterwards.

## Live acceptance — 6 November, 11:15 EAT

Runtime release f964f139 shipped, then strict-MariaDB correction61d27225 shipped and deployed. Original release previewf8c2/apply0c91 at11:09EAT saved verified private source backup `/home/d9410/mkt-backups/native-scorecard-20261106-080901-7c807e`; FPM8.2.35/opcache refresh and served JS/CSS exact. Three scanner edits and27untrackedfiles preserved.

Initial independent read dc4a exposed MariaDB ONLY_FULL_GROUP_BY rejecting repeated computed grouping expressions. Original USD report remained available; native enrichment degraded explicitly to unavailable. Failure evidence/logs retained. Corrected service groups concrete derived-table columns. Seven focused backend checks/37assertions pass after correction, plus two generation/dry-run integration checks/25assertions. Candidate90fc ran in production READ ONLY with strict SQLmode and matched all2,759current/prior payment rows independently before application. No change to SQLmode or financial policy.

Correction preview675b/applyc296 at11:14EAT saved `/home/d9410/mkt-backups/native-scorecard-20261106-081429-324237`. Final independent read d24c at11:15EAT: all16release-file hashes match, native amounts ready across21currencygroups/34markets/14staff; query294ms. Existing authorized recipient controller returns native data in READ ONLY transaction; independent rawSQL totals match. Published archive22 body SHA unchanged, briefing/recipientcounts unchanged22/47. Homepage metadata retained; no new native errors after correction anchor, Laravel log unchanged. One background SMS log appeared between reads; no dispatch performed by this task and no global unchanged-SMS-count claim.

Final isolated-bundle browser suite5passed at320/390/1440px, including negative/native badge direction, decimal preservation, phone overflow, long unresolved labels, TZS15,241,000, empty/unavailable archives, keyboard/reduced-motion and print/clipboard. Source class hash75751740, builtJS app-BqyXCYRR.js andCSS app-eoqsY1B-.css. Existing local build manifest/unrelated work remains intact.

Runtime LIVE/VERIFIED at61d27225. Later evidence/browser-only commits need no application redeployment. No migration, scorecard regeneration, customer/payment mutation or task message send. Future scheduled publication remains ordinary operational observation. Current recovery uses the two guarded code-only backups in reverse order (correction then original release); no database rollback is involved.
