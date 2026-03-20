# Lead Conversion & Support Board Lead Import Plan

**Date:** 2026-03-20  
**Status:** Planning only. No product behavior changes are included in this document.  
**Goal:** Add two high-safety growth workflows without destabilizing the existing leads, clients, or Support Board features.

---

## 1) Scope locked for the next implementation pass

This planning round covers:

1. **Direct lead -> client conversion**
   - A sales/admin operator can convert a lead into a brand new client when no linked client exists yet.
   - The flow must have strong UX, explicit validation, and redirect to the new client profile on success.

2. **Support Board -> CRM lead intake**
   - Import lead candidates from Support Board users/conversations into CRM leads.
   - The flow must prevent duplicate records and preserve traceability back to Support Board.

This planning round does **not** include:

- automatic lead conversion without operator review
- automatic market reassignment of existing clients or leads
- direct Support Board admin deep-link navigation
- direct lead import into clients without first passing through lead-intake rules

---

## 2) Current state summary

### 2.1 Direct lead conversion today

The current leads flow supports **reconcile to an existing client**, not creation of a new client from a lead:

- the Leads UI reconcile modal only lets the operator choose an existing linked/matched client
- the backend rejects converting a lead unless a `converted_client_id` can be resolved

Current backend behavior:

- [LeadController.php](/Users/ian/Projects/exotic-crm/app/Http/Controllers/CRM/LeadController.php:968) returns:
  - `"Lead conversion requires a linked client. Provide converted_client_id or sync client data."`

Current models:

- leads already store `converted_client_id`
- clients already have all core CRM profile fields needed for manual creation

### 2.2 Support Board intake today

What exists:

- Support Board linkage exists only on **clients** via `sb_user_id` and `sb_matched_by`
- the client Chat tab can:
  - load matched Support Board profile metadata
  - preview/apply profile sync
  - reply to conversation threads

What does **not** exist:

- no Support Board identity fields on `leads`
- no Support Board conversation import service for leads
- no UI action to intake Support Board users/conversations into CRM leads

### 2.3 Relevant Support Board API capabilities confirmed

Official docs confirm the APIs we need:

- `get-conversations`
- `get-new-conversations`
- `get-user`
- `get-user-extra`
- `get-user-from-conversation`
- `get-users-with-details`
- `update-user-to-lead`

Primary sources:

- https://board.support/docs/api/web
- https://board.support/docs/

Why this matters:

- `get-conversations` is suitable for an initial bootstrap/manual import run
- `get-new-conversations` is suitable for incremental import after a watermark
- `get-user` and `get-user-extra` let us enrich lead records and dedupe safely

---

## 3) Product principles

These rules should govern both features:

- **No silent destructive updates**
- **Preview before commit where the action is not trivially reversible**
- **Use existing CRM creation rules unless we explicitly approve stricter ones**
- **Always preserve traceability in timeline/audit**
- **Prefer operator confidence over automation speed**
- **Market suggestions are allowed; automatic market reassignment is not**

---

## 4) Feature A: Direct lead -> client conversion

## Objective

Allow an operator to turn a lead into a brand new client in one intentional workflow, without first needing a pre-existing client match.

## UX requirements

- The action should feel like a guided conversion, not a raw form dump.
- The operator should see:
  - which lead is being converted
  - which fields will become the new client profile
  - what still needs input before creation is allowed
- On success:
  - the lead should be marked converted
  - the new client should be linked back to the lead
  - the UI should redirect directly to the new client profile

## Recommended operator flow

### Entry point

Add a new action on lead rows and in the lead reconcile surface:

- `Convert to client`

### Modal / drawer flow

Open a dedicated **Convert Lead to Client** modal or drawer with:

- lead summary at the top
  - name
  - phone
  - email
  - market
  - owner
  - source
- prefilled client fields
  - `platform_id`
  - `name`
  - `phone_normalized`
  - `email`
  - `city`
  - `assigned_to`
  - optional `profile_status`
- field validation state
- “missing required info” guidance

### Validation model

Use current client creation rules as the source of truth:

- required now:
  - `platform_id`
  - `name`
- optional now:
  - `phone_normalized`
  - `email`
  - `city`
  - `assigned_to`

Important UX note:

- Because the current backend does **not** require phone or email for manual client creation, the conversion UI should not introduce stricter rules unless explicitly approved.
- Instead:
  - show **required**
  - show **recommended**
  - block only on truly required fields
  - warn if both phone and email are blank

### Success behavior

On successful conversion:

1. create the client
2. set `lead.status = converted`
3. set `lead.converted_client_id = new client id`
4. create lead + client timeline events
5. audit the conversion
6. redirect to:
   - `/clients/{newClientId}`

Optional UX enhancement:

- show a success toast after redirect:
  - `Lead converted. Client profile opened.`

## Backend design

### New endpoint

Add a dedicated conversion endpoint instead of overloading status change:

- `POST /api/crm/leads/{lead}/convert-to-client`

Why:

- clearer intent
- easier validation
- easier audit semantics
- avoids mixing “status update” with “create a new entity”

### Request payload

Suggested payload:

- `platform_id`
- `name`
- `phone_normalized`
- `email`
- `city`
- `assigned_to`
- `profile_status` (optional, likely default `private`)
- `reason`

### Service layer

Add a dedicated service:

- `LeadConversionService`

Responsibilities:

- validate market access assumptions
- normalize phone/email
- create client in a transaction
- update lead conversion fields
- create timeline entries
- write audit log
- return both lead + client payload summary

### Transaction rules

Wrap the following in one DB transaction:

1. create client
2. update lead status and `converted_client_id`
3. create lead timeline event
4. create client timeline event
5. audit log write

## Duplicate protection

Before creating the client:

- check for existing client match using existing matching rules:
  - exact phone in same market
  - exact email in same market
  - existing linked client if already matched

If a strong existing client is found:

- stop creation flow
- show operator:
  - `A likely existing client already matches this lead`
- offer:
  - `Use existing client instead`
  - `Cancel`

This avoids accidental duplicate client records.

## Timeline / audit expectations

Add a dedicated audit action, for example:

- `lead_convert_to_client`

Timeline events:

- on the lead:
  - `lead_converted_to_client`
- on the client:
  - `client_created_from_lead`

## Test coverage

### Backend

- convert lead with valid minimum data creates client and updates lead
- conversion redirects payload includes new client id
- duplicate detection blocks creation when strong match exists
- market scoping prevents cross-market conversion
- lead already converted is idempotent / blocked appropriately
- timeline + audit are written

### Frontend

- conversion action appears in correct states
- modal pre-fills fields correctly
- required field errors render clearly
- success path redirects to new client profile

## Acceptance criteria

- A lead with no matched client can be converted into a new client
- The operator is guided through missing required fields
- The lead is marked converted and linked to the created client
- The operator lands on the new client profile automatically
- No duplicate client is created when a strong existing client already matches

---

## 5) Feature B: Import leads from Support Board

## Objective

Bring Support Board chat-origin contacts into the CRM leads pipeline with strong dedupe, clear source labeling, and traceability back to Support Board.

## Important design choice

Use **conversations as the intake driver**, not just the raw users list.

Why:

- the product requirement is “users who have a chat”
- conversation-backed intake guarantees actual chat activity
- conversations also give better incrementality and recency handling

## Intake modes

### Mode 1: Manual bootstrap import

For initial backfill or operator-triggered import:

- use `get-conversations`

### Mode 2: Incremental import

For ongoing sync/import jobs:

- use `get-new-conversations`
- store a watermark:
  - last imported conversation ID or timestamp

## Schema changes

Add Support Board identity fields to `leads`:

- `sb_user_id` nullable unsigned integer
- `sb_conversation_id` nullable unsigned integer
- `sb_user_type` nullable string
- `sb_last_activity_at` nullable datetime or string-normalized datetime
- `sb_metadata_snapshot` nullable JSON

Recommended indexes:

- index on `sb_user_id`
- index on `sb_conversation_id`
- composite index on `(platform_id, sb_user_id)`

Why:

- dedupe
- auditability
- future direct Support Board navigation
- future lead/client reconciliation from chat-origin records

## Mapping rules

### Core lead fields

Map from Support Board into CRM lead:

- `name`
  - from Support Board full name
- `email`
  - from user core or extras
- `phone_normalized`
  - from extras, normalized
- `source`
  - new source value: `support_chat`
- `source_url`
  - from `current_url` or `landing_url` if available
- `status`
  - default `new`
- `assigned_to`
  - optional later automation, otherwise null

### Derived fields

- `country_code` -> market suggestion
  - use to choose the target market for **new lead creation** when confident
  - do not silently move an existing CRM record between markets
- `location` -> city
  - optional
  - only use when parse confidence is acceptable

## Market resolution strategy

### Safe initial rule

Do not infer market only from free-text location.

Prefer:

1. explicit operator-selected market for the import run
2. then country code if it maps cleanly to a known market
3. otherwise flag candidate as unresolved and skip or queue for review

Why:

- lead import is lower risk when market is explicit
- chat-origin metadata can be noisy

## Dedupe strategy

For each Support Board candidate:

### Check clients first

1. existing client by `sb_user_id`
2. existing client by exact normalized phone in same market
3. existing client by exact email in same market

If matched:

- do **not** create a lead
- count as `linked_to_existing_client`

### Check leads second

1. existing lead by `sb_user_id`
2. existing lead by exact normalized phone in same market
3. existing lead by exact email in same market

If matched:

- update the lead traceability fields if needed
- do not create a duplicate lead

### Only create a new lead if no strong match exists

This should be the hard rule.

## Import architecture

### Recommended service

Add:

- `SupportBoardLeadImportService`

Responsibilities:

- fetch conversations in pages/chunks
- derive unique candidate users from conversations
- fetch user + extras
- map data into CRM lead candidate
- run dedupe checks
- create/update/skip appropriately
- record import stats

### Recommended import run model

Add an import-run record/table if this is expected to be long-running or operator-visible:

- market
- mode (`bootstrap` / `incremental`)
- started_at
- finished_at
- status
- candidates
- created
- updated
- skipped
- matched_existing_clients
- matched_existing_leads
- errors
- watermark_before
- watermark_after

Why:

- strong operator UX
- resumability
- audit trail

## UI/UX plan

### Initial entry point

Add a dedicated action in Leads or Settings, not hidden in the client chat flow:

- `Import from Support Board`

### Import setup modal

Show:

- target market
- mode:
  - bootstrap
  - incremental
- dry run
- candidate scope note
  - `Imports Support Board users with conversation activity`
- dedupe policy summary

### Results UI

After run:

- total candidates
- new leads created
- existing leads refreshed
- existing clients detected
- skipped
- errors

Optional drilldown:

- show a result table of:
  - created
  - updated
  - skipped with reason

## Backend endpoints

Suggested endpoints:

- `POST /api/crm/leads/support-board/import/preview`
- `POST /api/crm/leads/support-board/import`
- optional:
  - `GET /api/crm/leads/support-board/import-runs/{run}`

Why preview first:

- lets operators understand likely create/update/skip counts
- lowers duplicate risk

## Support Board API usage plan

Preferred flow:

1. fetch conversations in pages using `get-conversations`
2. for incremental mode use `get-new-conversations`
3. extract unique `conversation_user_id`
4. fetch user details:
   - `get-user` with extras
   - fallback `get-user-extra` if needed
5. build lead candidates
6. dedupe + import

Possible optimization later:

- `get-users-with-details` may help bulk-enrich known user IDs when we want to reduce API round trips

## Interaction with Support Board user type

Support Board exposes `update-user-to-lead`, but CRM import should **not** rely on changing remote type to make the intake work.

Recommendation:

- treat Support Board user type as metadata only
- optionally store it in `sb_user_type`
- do not mutate Support Board user type during initial CRM import

This keeps the first version safer and less surprising.

## Test coverage

### Backend

- preview/import creates leads only for unlinked chat users
- existing client match prevents new lead creation
- existing lead match updates traceability but does not duplicate
- market scoping is enforced
- unsupported/ambiguous market mapping is skipped safely
- import run statistics are accurate

### Frontend

- import action is available only to allowed roles
- preview results render clearly
- dry run does not write leads
- result summary messages are trustworthy

## Acceptance criteria

- Operators can preview Support Board lead intake before writing data
- Only chat-backed users are considered
- Duplicate clients and duplicate leads are prevented by strict checks
- New leads are clearly labeled as `support_chat` origin
- Each imported lead retains Support Board traceability

---

## 6) Recommended implementation order

### Step 1

Implement **direct lead -> client conversion** first.

Why:

- smaller surface area
- no external API dependency
- immediate sales value
- simpler regression profile

### Step 2

Implement **Support Board lead import preview + dry run**.

Why:

- validates data quality before any writes
- lets us inspect dedupe behavior safely

### Step 3

Implement **Support Board lead import commit flow** with run stats.

### Step 4

Optionally add scheduled incremental imports after the manual flow proves stable.

---

## 7) No-regression guardrails

- Do not reuse generic lead status update for new-client conversion.
- Do not auto-convert imported Support Board leads into clients.
- Do not auto-change market of existing clients or leads.
- Do not create new client records when a strong existing client match already exists.
- Keep every irreversible action behind an explicit operator step.
- Require timeline + audit for both conversion and import flows.

---

## 8) Review checklist

Before implementation starts, confirm:

1. For lead -> client conversion, should the converted client default to `private` or inherit another profile status?
2. For lead -> client conversion, do we want to keep current backend-required fields only, or should we newly require at least one contact method?
3. For Support Board import, should operators always choose the market manually, or should we allow country-code-based auto-routing when confidence is high?
4. For Support Board import, should dry run be mandatory on first use?
5. Should import results surface directly in the Leads page, Settings, or both?

