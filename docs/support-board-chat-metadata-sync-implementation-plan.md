# Support Board Chat Metadata & Sync Implementation Plan

**Date:** 2026-03-20  
**Scope for review:** Planning only. No product changes are included in this document.  
**Priority:** Safety, auditability, and operator UX over speed.

---

## 1) Locked decisions for this planning round

- **Support Board navigation is deferred.**
- **Remove Support Board outbound links from the current client surfaces for now.**
- **Primary implementation focus is the Client Chat tab.**
- **Two features are in immediate scope for design and later implementation:**
  - Support Board metadata panel in the Chat tab
  - Bidirectional profile sync between CRM client and matched Support Board user
- **Good UI/UX is non-negotiable.**
- **Silent overwrites are not allowed.**
- **Country code from Support Board can inform market suggestions for intake, but must not silently reassign an existing client to another market.**
- **Location from Support Board can optionally inform CRM city, but only as an operator-controlled sync field.**
- **Leads also need a direct “convert lead to client” path and this must remain in the master plan even if implemented later.**

---

## 2) Current state summary

### 2.1 CRM today

- Clients store the Support Board link on the client record via `sb_user_id` and `sb_matched_by`.
- The Chat tab can:
  - resolve a matched Support Board user
  - load conversations
  - load a conversation thread
  - send replies
- The Chat tab does **not** currently:
  - display Support Board user metadata beyond the conversation thread
  - offer a controlled profile sync workflow
  - distinguish between preview and apply when writing cross-system profile updates

### 2.2 Leads today

- The Leads page already has a **reconcile** flow that can:
  - link a lead to an existing matched client
  - convert a lead when a matched client already exists
- The Leads page does **not** currently support:
  - creating a brand new client directly from a lead and converting the lead in one operator flow

### 2.3 Support Board capability confirmed

Official Support Board APIs support:

- `get-user`
- `get-user-extra`
- `update-user`
- `add-user`
- admin URL parameters like `?conversation=ID` and `?user=ID`

Sources:

- https://board.support/docs/
- https://board.support/docs/api/web

---

## 3) Implementation phases

## Phase A — Remove current Support Board navigation links

### Objective

Prevent misleading navigation while we are deferring the richer Support Board navigation model.

### Why

- The current outbound link opens the public support chat URL, not the Support Board admin workspace.
- That creates a broken mental model for sales users.

### Planned UI change

- Remove the existing `Support chat` / `Open support board` outbound links from:
  - client header actions
  - client overview summary card
  - Chat tab action area

### Schema changes

- None.

### Backend changes

- None.

### Test coverage

- Frontend render test or snapshot coverage for the client detail screen should be updated if these surfaces are covered.
- Manual verification:
  - no broken empty action placeholders
  - action spacing remains balanced on desktop and mobile

### Acceptance criteria

- No Support Board/public chat outbound link remains visible in the client detail UI.
- No empty action gap or layout regression appears in the client page header or summary card.

---

## Phase B — Support Board metadata panel in the Client Chat tab

### Objective

Show the sales team the matched Support Board user context directly inside the Chat tab, without forcing them to leave CRM.

### UX requirements

- The metadata must feel like a clean operator console, not a raw payload dump.
- Primary information should be visible without scrolling far.
- Unknown or missing values should degrade gracefully.
- Dynamic extras should not break layout.
- The panel must work at desktop and tablet/mobile widths.

### Recommended placement

- Add a dedicated `Support Board profile` panel in the Chat tab.
- Preferred desktop layout:
  - conversations list on the left
  - thread in the center/right
  - compact metadata block above or beside the thread header
- Preferred smaller-screen layout:
  - metadata becomes a collapsible card above the thread

### Initial fields to surface

- Support Board user ID
- full name
- email
- phone
- user type
- creation time
- last activity
- country code
- currency
- current URL
- timezone
- browser language
- location

### Dynamic metadata section

- Include a `More details` expandable block for additional extras returned by Support Board that are not part of the primary summary.
- This is where fields like these may appear if present:
  - device type
  - host
  - landing path
  - landing URL
  - referrer
  - widget language

### Data mapping rules

| Support Board field | CRM meaning | Rule |
|---|---|---|
| `country_code` | market signal | Use for suggestion only in future intake flows; do not silently rewrite an existing client market |
| `location` | city signal | Optional source for CRM city if operator selects it during sync |
| `phone` | client phone | Normalize before any CRM write |
| `email` | client email | Standard exact-value comparison |
| `first_name` + `last_name` | client name | Combine for CRM display and sync preview |

### Schema changes

- None required for the first implementation.
- Metadata can be fetched on demand from Support Board.
- Optional later enhancement:
  - small cached JSON snapshot on the client
  - only if API latency becomes a UX problem

### Backend endpoints/services

#### Service additions

Add to `SupportBoardService`:

- `getUser(int $userId, bool $withExtra = true): array`
- `getUserExtra(int $userId): array`
- `normalizeUserExtra(array $details): array`
- `buildProfilePayload(Client $client): array`

#### Controller additions

Add a dedicated profile endpoint under the current client Support Board namespace:

- `GET /api/crm/clients/{client}/support-board/profile`

Response shape should include:

- `configured`
- `matched`
- `sb_user`
- `sb_user_extra`
- `derived`
  - `market_suggestion`
  - `city_suggestion`
  - `country_code`
  - `location_raw`

### UI changes by screen

#### Client Detail > Chat tab

- Add `Support Board profile` card
- Add compact badges for:
  - matched state
  - user type
  - online/offline if later available
- Add readable rows for primary fields
- Add `More details` disclosure for dynamic extras
- Add low-confidence hint if city/market suggestions are ambiguous

### Test coverage

#### Backend

- matched client returns profile payload successfully
- unmatched client returns safe empty state
- unconfigured market returns safe empty state
- extras normalize correctly when values are missing
- derived market/city suggestions do not mutate CRM data

#### Frontend

- metadata panel renders primary fields cleanly
- missing values render as `—`
- dynamic extras render only inside the expandable section
- mobile layout does not overflow

### Acceptance criteria

- Sales can see the matched Support Board user context without leaving CRM.
- The panel remains readable when half the fields are missing.
- No CRM data is modified by simply viewing the metadata panel.

---

## Phase C — Bidirectional profile sync inside the Chat tab

### Objective

Allow controlled, auditable syncing of selected fields between the CRM client and the matched Support Board user.

### UX principles

- **Preview first, apply second**
- **Operator chooses the direction**
- **Operator chooses the fields**
- **Default mode is safe**
- **Conflicts are visible**
- **Nothing overwrites silently**

### Sync directions

- `Support Board -> CRM`
- `CRM -> Support Board`

### Modes

- `Preview only`
- `Fill blanks only` (default)
- `Overwrite selected fields`

### Initial syncable fields

- name
- email
- phone
- city

### Optional later syncable fields

- current URL
- timezone
- browser language
- location snapshot
- country code snapshot

### Field rules

#### Name

- CRM stores a single `name`.
- Support Board stores `first_name` and `last_name`.
- Rules:
  - `Support Board -> CRM`: combine non-empty first/last name
  - `CRM -> Support Board`: split conservatively
    - first token -> `first_name`
    - remaining text -> `last_name`
  - if split quality is poor, show warning in preview

#### Email

- Exact comparison
- Preview if change is destructive

#### Phone

- Always normalize before CRM write
- Show both raw and normalized values in preview if normalization changes the string

#### City

- For `Support Board -> CRM`, source from `location` only when operator selects it
- If location looks ambiguous, show it as a suggestion and require explicit confirmation

#### Market

- Not part of the first syncable field set
- `country_code` can be shown as a suggestion only
- Existing client market must never change automatically through the profile sync tool

### Safety workflow

Use a two-step flow:

1. Operator opens `Sync profile details`
2. Operator chooses:
   - direction
   - mode
   - fields
3. CRM generates a preview
4. Operator reviews a side-by-side diff
5. Operator applies the sync

### Conflict design

For each field, show:

- current CRM value
- current Support Board value
- resulting value after selected direction/mode
- status chip:
  - `same`
  - `fill blank`
  - `update`
  - `conflict`
  - `skipped`

### Schema changes

- None required for the first version.
- Use existing audit/timeline mechanisms for traceability.

### Backend endpoints/services

#### Service additions

Add to `SupportBoardService`:

- `previewClientSync(Client $client, array $options): array`
- `applyClientSync(Client $client, array $options): array`
- `updateUser(int $userId, array $payload): bool`

#### Controller endpoints

- `POST /api/crm/clients/{client}/support-board/profile-sync/preview`
- `POST /api/crm/clients/{client}/support-board/profile-sync/apply`

Request payload:

- `direction`
- `mode`
- `fields`
- `reason`

Apply response should include:

- applied fields
- skipped fields
- warnings
- updated CRM client payload
- refreshed Support Board summary payload

### Audit and timeline requirements

- Every apply action must write an audit log with:
  - direction
  - mode
  - selected fields
  - before/after summary
  - actor
  - reason
- Add a timeline event on the client for successful sync apply
- Preview actions should not write timeline events

### UI changes by screen

#### Client Detail > Chat tab

- Add `Sync profile details` button near the metadata panel
- Modal or drawer contains:
  - direction selector
  - mode selector
  - field checklist
  - reason textarea
  - preview table
  - apply button

#### Interaction details

- `Preview` is the primary action
- `Apply` remains disabled until preview is present
- `Fill blanks only` is preselected
- `Overwrite selected fields` uses stronger warning styling

### Test coverage

#### Backend

- preview returns correct diff for each direction
- apply updates only selected fields
- `fill blanks only` never overwrites non-empty target values
- `overwrite selected fields` updates selected fields only
- phone normalization works on CRM writes
- city sync from location is optional
- market is never rewritten by profile sync
- audit and timeline records are created on apply

#### Frontend

- preview table renders clear differences
- apply button is blocked until preview succeeds
- destructive overwrite mode shows stronger warning state
- missing matched Support Board user disables the sync action cleanly

### Acceptance criteria

- Sales can safely sync selected details without guessing what will change.
- Every write is previewed before apply.
- Existing client market is never silently changed.
- CRM and Support Board remain linked after sync.

---

## Phase D — Direct lead-to-client conversion

### Objective

Allow operators to convert a lead directly into a new client even when there is no existing matched client.

### Current limitation

- The current Leads reconcile flow expects an already matched or linked client candidate.

### Desired workflow

1. Operator clicks `Convert to client` on a lead
2. CRM opens a `Create client from lead` modal
3. Prefill:
   - name
   - phone
   - email
   - city if available
   - assigned owner
   - market from lead platform
4. Operator confirms
5. CRM creates the client
6. Lead is marked `converted`
7. `converted_client_id` is set

### Schema changes

- None required for the first pass.

### Backend endpoints/services

- Add dedicated endpoint:
  - `POST /api/crm/leads/{lead}/convert-to-client`
- Service should:
  - validate platform consistency
  - create client from mapped lead data
  - update lead status and `converted_client_id`
  - write audit + timeline records

### UI changes by screen

#### Leads page

- Add row action `Convert to client`
- Add modal:
  - editable prefilled fields
  - reason
  - assignment confirmation

### Test coverage

- lead converts to a new client successfully
- platform mismatch is rejected
- converted lead links to created client
- duplicate phone/email rules behave correctly

### Acceptance criteria

- Operators can create and convert from a lead in one guided flow.
- The conversion is explicit, auditable, and market-safe.

---

## 4) Deferred items that remain in the master plan

- Support Board admin deep-link navigation
- Support Board users/conversations -> lead intake
- Background job UX for Support Board link sync
- Optional persistent Support Board metadata caching if live fetch performance becomes poor

These are intentionally deferred, not dropped.

---

## 5) No-regression guardrails

- No silent field overwrites
- No silent market reassignment
- No background writes on metadata view
- No breaking changes to current chat reply flow
- No breaking changes to current Support Board matching logic
- No breaking changes to lead reconciliation flow while adding direct lead-to-client conversion
- Every cross-system write must be auditable
- All new UI must remain usable at desktop and mobile widths

---

## 6) Implementation order when approved

1. Phase A — remove misleading Support Board links
2. Phase B — metadata panel in Chat tab
3. Phase C — bidirectional profile sync with preview/apply workflow
4. Phase D — direct lead-to-client conversion

Deferred after that:

5. Support Board navigation deep links
6. Support Board lead intake
7. Support Board sync-run UX overhaul

---

## 7) Review checklist

- Confirm removal of current Support Board/public chat links from client surfaces
- Confirm metadata field set for first release
- Confirm sync direction/mode defaults
- Confirm `country_code` stays suggestion-only for market handling
- Confirm `location -> city` remains optional and operator-controlled
- Confirm direct lead-to-client conversion belongs in the next approved implementation tranche

