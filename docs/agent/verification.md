# CRM verification routing

Commands are grounded in package.json, phpunit.xml and existing tests. Confirm current
prerequisites and test database configuration before execution; avoid production targets.

| Change | Check |
|---|---|
| PHP syntax | `/usr/local/opt/php@8.2/bin/php -l <changed-file>` |
| Behavior | `/usr/local/opt/php@8.2/bin/php artisan test --filter='<RelevantTest>'` |
| Formatting | `/usr/local/opt/php@8.2/bin/php vendor/bin/pint --test <changed-file>` for check-only; formatting without --test mutates files. |
| Browser | `npm run test:browser -- <relevant-spec>` after inspecting playwright.config.js and the base URL; no silent remote-host override. |
| Frontend delivery | `npm run build`; inspect intended source and public/build changes before an authorized commit. |
| Instructions/skills only | Links/imports, skill validation, cold-context exercise, Git/index/ignore preservation; no full application suite. |

No npm lint/test script is assumed. PHPUnit 10 does not support --verbose. Use focused
tests first; expand when shared behavior, failures or release scope justify it.
For lifecycle recovery the existing filter is `LifecycleRestore`; MCP tests live in
`tests/Feature/Mcp/`. Test existence alone is not a pass result.

Record command, runtime, time, exit/result, source HEAD and relevant staged/unstaged/
untracked inputs. A changed input invalidates the old result. Preserve baseline failures
explicitly. Separate local verification, push, manual deploy and observed production behavior.
No root-level doctor/verify runner or CI was added by the foundation task.
