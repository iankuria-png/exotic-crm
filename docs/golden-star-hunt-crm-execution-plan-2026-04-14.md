# Golden Star Hunt CRM Execution Plan

Date: 2026-04-14  
Project: Exotic CRM  
Source concept: `/Users/ian/.claude/plans/logical-strolling-bentley.md`

## 1. Purpose

This document converts the WordPress-heavy Golden Star Hunt concept into a CRM-owned execution plan for `exotic-crm`.

The campaign goals are:

- increase time on site and pages per session by forcing deeper browse paths
- funnel traffic to selected VIP companion profiles
- convert highly engaged hunters into a private Telegram VIP channel
- reward participating companions with homepage promotion and visibility

The WordPress site should own the on-site hunt experience. The CRM should own the operational layer: campaign control, verification workflow, reward fulfillment, reporting, and post-campaign analysis.

## 2. Ownership Split

### WordPress owns

- star discovery UX: modal, banner, toast, profile star render, countdown
- per-profile star placement and star numbering
- on-site profile view counting during the hunt
- public-facing leaderboard widgets, if they stay on the site
- capture of raw hunt submissions if launch uses a site form

### CRM owns

- campaign record and campaign status
- roster of star hosts and companion reward eligibility
- hunter verification queue
- winner selection and reward fulfillment status
- Telegram VIP invite workflow tracking
- operator reporting: submissions, approvals, winners, leaderboard snapshots, conversion totals
- outbound reminder operations using existing push-campaign capability for hints and traffic spikes

### Explicit non-goal for launch

Do not build the hunt UI inside this repo. `exotic-crm` is the operations system, not the user-facing game surface.

## 3. Launch Strategy

### Recommended launch mode: CRM-assisted, not CRM-blocking

For the first live run, the campaign should still be able to launch even if CRM automation is partial.

That means:

- WordPress can run the hunt and collect submissions independently
- CRM receives submissions through import or webhook, but campaign success must not depend on a brand-new Telegram bot or a brand-new frontend
- operator workflows in CRM should be designed so manual override is always possible

This reduces risk and keeps the launch window under team control.

## 4. CRM Scope for v1

### v1 must include

- a campaign definition record for Golden Star Hunt
- a host roster that maps the 5 star profiles to reward eligibility
- a submission inbox with statuses:
  - `pending`
  - `verified`
  - `rejected`
  - `winner`
  - `rewarded`
- hunter contact storage for Telegram handle and proof source
- reward tracking for:
  - client invited to Telegram VIP
  - companion granted homepage feature slot
- simple analytics:
  - total submissions
  - unique hunters
  - verified hunters
  - winners
  - top host profiles by tracked views
- exportable CSV for ops follow-up

### v1 should reuse existing CRM surfaces where practical

- `PushCampaigns` for daily hints, urgency pushes, and last-day reminders
- `Reports` for campaign snapshots and post-campaign metrics
- existing service-layer patterns for sync, imports, and operator actions

### v1 should stay manual or semi-manual for

- Telegram invite delivery
- proof review if screenshots are not parsed automatically
- companion homepage-feature fulfillment if that action still happens in WordPress admin

## 5. Recommended Data Model

Use new campaign-specific tables instead of forcing this into renewals or generic lead state.

### Proposed tables

#### `growth_campaigns`

One record per growth campaign.

Suggested fields:

- `id`
- `name`
- `slug`
- `type` (`golden_star_hunt`)
- `status` (`draft`, `scheduled`, `active`, `closed`, `archived`)
- `starts_at`
- `ends_at`
- `rules_json`
- `reward_rules_json`
- `telegram_channel_name`
- `telegram_admin_handle`
- `source_platform_id` nullable
- `created_by`
- `updated_by`

#### `growth_campaign_hosts`

Maps the chosen star-host companions to the campaign.

Suggested fields:

- `id`
- `campaign_id`
- `platform_id`
- `external_profile_id`
- `profile_name`
- `profile_url`
- `star_number`
- `city`
- `reward_status` (`eligible`, `won`, `granted`, `not_selected`)
- `tracked_views`
- `metadata_json`

#### `growth_campaign_entries`

One row per hunter submission.

Suggested fields:

- `id`
- `campaign_id`
- `host_id` nullable
- `source` (`wordpress_form`, `telegram_manual`, `csv_import`, `api`)
- `finder_name`
- `telegram_handle`
- `profile_url`
- `proof_url` nullable
- `proof_notes` nullable
- `status` (`pending`, `verified`, `rejected`, `winner`, `rewarded`)
- `submitted_at`
- `verified_at` nullable
- `verified_by` nullable
- `winner_rank` nullable
- `rewarded_at` nullable
- `metadata_json`

#### `growth_campaign_reward_events`

Append-only reward audit trail.

Suggested fields:

- `id`
- `campaign_id`
- `entry_id` nullable
- `host_id` nullable
- `reward_type` (`telegram_vip_invite`, `homepage_feature`, `consolation_reward`)
- `status` (`queued`, `sent`, `completed`, `failed`, `cancelled`)
- `notes`
- `executed_by` nullable
- `executed_at` nullable
- `metadata_json`

## 6. Integration Contract with WordPress

The cleanest contract is webhook-first with manual import fallback.

### Webhook events

#### `golden_star_hunt.entry_submitted`

Sent when a user submits a find from the site.

Payload should include:

- `campaign_slug`
- `profile_url`
- `profile_name`
- `star_number`
- `finder_name`
- `telegram_handle`
- `proof_url` if available
- `submitted_at`
- `source_site`

#### `golden_star_hunt.host_snapshot`

Sent on a schedule or on campaign close to sync host standings.

Payload should include:

- `campaign_slug`
- `profile_url`
- `profile_name`
- `star_number`
- `tracked_views`
- `captured_at`

### Fallback import path

If WordPress-side webhooks are not ready for launch:

- export submissions CSV from WordPress admin or Telegram ops sheet
- import into CRM using a dedicated growth-campaign import action
- maintain idempotency by hashing campaign slug + telegram handle + profile URL + submitted timestamp

## 7. Operator Workflow in CRM

### Daily hunt operations

1. Marketing activates the campaign record.
2. Operators confirm the 5 host profiles and star numbering.
3. Push hints are queued from `PushCampaigns`.
4. New entries arrive by webhook or import.
5. Ops reviews each entry and marks it `verified` or `rejected`.
6. First verified entries can be promoted to `winner` according to campaign rules.
7. Telegram invite status is tracked to completion.
8. Companion reward winner is finalized from the host leaderboard snapshot.

### Suggested queue actions

- `Verify`
- `Reject`
- `Mark winner`
- `Undo winner`
- `Queue Telegram invite`
- `Mark reward complete`
- `Add operator note`

## 8. UI Surface Recommendation

Do not bury this inside renewal campaigns. Add a focused campaign-ops workspace.

### Recommended placement

- new page: `Growth Campaigns`
- initial subtype support: `Golden Star Hunt`

### Suggested tabs

- `Overview`
- `Hosts`
- `Entries`
- `Rewards`
- `Analytics`

### Minimal Overview metrics

- campaign status
- days or hours remaining
- total entries
- unique hunters
- verified hunters
- winners assigned
- Telegram invites completed
- leading host profile by tracked views

## 9. Use of Existing Push Campaign Capability

The existing push-campaign stack is a strong fit for the campaign reminder layer.

Use it for:

- day 1 launch announcement
- daily clue drops
- “2 days left” urgency push
- “final hours” push
- winner announcement or post-campaign recap

This lets the team avoid building a separate outbound scheduler for a short-term campaign.

## 10. Delivery Phases

### Phase A: Launch-safe ops support

Build only what is needed so CRM can manage the hunt without blocking launch.

- new growth-campaign tables and models
- create/read/update workflow for Golden Star Hunt campaign
- host roster management
- entry ingestion endpoint plus CSV fallback import
- operator inbox for review and reward tracking
- basic analytics cards

### Phase B: Automation and scale-up

- WordPress webhook integration
- leaderboard snapshot sync
- reward-event automation
- winner rank enforcement
- audit log and improved exports

### Phase C: Full funnel intelligence

- Telegram bot handoff tracking
- deeper attribution from site session to entry to invite to conversion
- reusable growth campaign framework beyond Golden Star Hunt

## 11. Engineering Notes

### Backend conventions

- keep orchestration in services, not controllers
- prefer additive modules under a clear namespace such as `app/Growth/*`
- keep manual override paths available to operators
- add explicit auditability for every reward-state change

### Frontend conventions

- follow existing React Query patterns for lists, filters, and mutation invalidation
- keep the first version operational rather than heavily designed
- support degraded states when webhook data is delayed or unavailable

### Suggested namespaces

- `app/Models/GrowthCampaign.php`
- `app/Models/GrowthCampaignHost.php`
- `app/Models/GrowthCampaignEntry.php`
- `app/Models/GrowthCampaignRewardEvent.php`
- `app/Services/Growth/GrowthCampaignService.php`
- `app/Services/Growth/GrowthCampaignIngestionService.php`
- `app/Http/Controllers/CRM/GrowthCampaignController.php`

## 12. Open Decisions

The team should answer these before implementation starts:

- Is the first source of truth for submissions WordPress, Telegram admin, or CRM?
- Will proof live as screenshot files, links, or operator notes only?
- Does the “first 10 winners” rule require strict timestamp ordering inside CRM?
- Will homepage-feature fulfillment be tracked only in CRM, or also pushed back to WordPress?
- Should hunt entrants become CRM leads automatically, or remain campaign-only records unless they convert?

## 13. Acceptance Criteria for v1

- operators can create and activate a Golden Star Hunt campaign in CRM
- operators can register the 5 host profiles and their star numbers
- CRM can ingest hunt entries without duplicate explosion
- operators can verify, reject, and mark winners from a single inbox
- CRM can track Telegram invite completion and companion reward completion
- CRM can show a simple leaderboard snapshot for star hosts
- campaign reporting can be exported for postmortem and payout/reward reconciliation

## 14. Recommended Next Step

If we build this in this repo, start with Phase A only.

That gives the launch team a real operations console without taking on risky dependencies like screenshot parsing, Telegram bot automation, or a full reusable campaign engine on day one.
