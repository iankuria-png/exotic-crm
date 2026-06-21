# SEO Boost Phase 1

## Goal

Give operators a map-driven way to temporarily activate high-quality inactive profiles in weak or underfilled cities, using tracked free-trial subscription activations that automatically lapse through the existing expiry/deactivation flow.

## Scope

- Create tracked SEO Boost batches, targets, and per-profile items.
- Rank candidate profiles primarily by existing `clients.seo_score`.
- Let users manually remove or reorder selected candidates before confirming.
- Activate selected clients through `SubscriptionProvisioningService::activateDeal()`.
- Stamp created deals with `origin = seo_boost` and `seo_boost_batch_id`.
- Use the existing expired-subscription reconciler for actual profile deactivation.
- Mark SEO Boost items and batches completed when their linked deals expire.

## Non-goals

- Relocate mode and city/taxonomy restoration.
- A new profile quality scoring system.
- Browser-test automation for the final user flow.

## UX

- Entry point lives in the Clients > Locations tab near the map controls.
- The wizard starts from weak/watch cities and allows target count edits.
- Preview shows city demand context, eligible candidate count, selected count, SEO score, verification, image, online recency, and reason chips.
- Confirmation requires the configured free-trial PIN.
- Empty, loading, partial-failure, and success states must be explicit.
- Controls must work on mobile and desktop without table overflow.

## Acceptance Criteria

- Authorized admin, sub-admin, sales, and field-sales users can preview and create batches for markets they can access.
- Marketing users cannot create batches.
- Invalid PIN blocks activation without creating deals.
- Only inactive/private, linked, non-closed, non-duplicate, non-high-risk clients without active deals are eligible.
- Batches persist target rows, item rows, linked deals, status, counts, and actor.
- Partial activation failures are reported per item and do not roll back successful activations.
- Existing expiry reconciliation marks linked SEO Boost items expired and completes the batch when all active items are expired or failed.
