# Exotic CRM — shared agent contract

## Start and resume
Read `docs/agent/state.md`, then the matching task. Use `docs/agent/map.md` to retrieve
only relevant architecture, decisions or incident context. Inspect live Git status/HEAD;
state notes and historical plans do not establish current completion or deployment.
Handovers checkpoint long conversations and are often already consumed. Compare referenced
commits with HEAD, file history and present code; extract lessons, not an automatic backlog.
Update the task and compact state at significant checkpoints with evidence and next action.

## Architecture and boundaries
- React SPA talks to Laravel over `/api/crm/*`. This repository also serves the legacy
  Ads API under `/api/*`; preserve payment callbacks and STK Push auto-activation.
- WordPress owns advertiser profiles; CRM `clients` caches them. CRM owns customer-product,
  KYC and integration state. Read the ownership glossary linked from `docs/agent/map.md`.
- CRM controllers belong in `app/Http/Controllers/CRM/`; business logic in `app/Services/`
  or the existing `app/Billing/` domain. Respect billing flags and legacy behavior until
  an authorized cutover. Inspect current dependencies/configuration for exact versions.
- Customers are `(platform_id, wp_user_id)` WP members; CRM `users` are staff; anonymous
  `visitor_contact_unlocks.client_id` refers to the advertiser, never the buyer.
- Preserve site HMAC and customer/session validation; never treat a site's signature
  as proof of a person. Keep sandbox and live payment providers separate.
- Follow existing phone normalization (country prefixes; leading `+`/local `0` handling),
  PSR-12 and Laravel naming. Make surgical edits, especially Settings.jsx, Payments.jsx
  and shared billing services; other agents may be editing them.

## Commands and UI
- Use `/usr/local/opt/php@8.2/bin/php` for Laravel/PHP tests and lint. Verify its presence;
  do not assume bare `php` is the required version. Local supplies the development DB.
- Read `docs/agent/environment.md` and `docs/agent/verification.md`. Scope tests to the
  changed behavior, widening only when risk/failures justify it. No invented `npm run lint`
  or `npm run test`; package.json is authoritative.
- UI standards live in `docs/agent/ui-rules.md`; use the existing design language and
  meaningful empty/loading/error states. Start with one suitable design skill.
- Ask about consequential missing product decisions after inspecting evidence. Wait when
  Ian explicitly says a feedback batch is incomplete; carry existing authorization through
  an otherwise specified task. Keep explanations concise, natural and audience-appropriate.

## Git, shipping and production
- Default is this `main` checkout. Do not create feature branches/worktrees by default.
  An explicitly authorized existing worktree is an exception: inspect its branch/root,
  dependency and landing constraints before editing; never switch main inside it.
  Exclude `.claude/worktrees/` from ordinary searches. A worktree note is not permission to push.
- Preserve unrelated work and pre-existing staging. Stage only task-owned changes, using
  reviewed hunks for mixed files; never use `git add -A` as a routine finish step.
  If a commit cannot be isolated, explain the specific conflict instead of altering others' work.
- Commit/push only within the user's scope. When asked to push, `origin main` is the
  expected destination; no AI co-author trailers. Use the portable `ship` skill.
- cPanel cannot build frontend assets: build affected UI locally and include the matching
  `public/build/` artifact in an authorized commit. Verify generated assets represent the
  intended source changes. Ian deploys by pulling; WP plugin uploads are separate and manual.
- Follow the WP workspace's `changelog/README.md` after commits: pending → entries/skip →
  build/check → separate changelog commit. Keep CRM/WP entries distinct and record local,
  pushed and live states accurately. Do not deploy Netlify without Ian's request.
- For production diagnosis use the portable `prod-debug` skill and available read-only tools
  before asking Ian to relay logs. Any authorized production data repair needs a complete
  dry-run → backup → apply → verify script, with dry-run default and explicit apply.
- Authentication belongs in credential helpers/configuration; never ask for tokens in chat.
