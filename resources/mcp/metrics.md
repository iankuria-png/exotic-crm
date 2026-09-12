# CRM metric definitions

## 30-day payers

Distinct payment client IDs in the mutually exclusive `new_active` and
`existing_active` collected-revenue buckets for the requested window. This is a
windowed payer count, not the number subscribed at the end of the window.

## Active subscribers

For each platform snapshot date, distinct non-null deal client IDs with an
active deal, activated on or before that day and not expired before that day.
Snapshots are read as point-in-time evidence and can be stale or mixed-date.

## Total client profiles

The lifecycle-state totals in the local `clients` cache. WordPress is the
source of truth; this is neither a live WordPress count nor active subscribers.

## Subscription terms and markets

Terms use an explicit deal duration, recognized catalogue duration, or actual
activation-to-expiry interval. A missing expiry is open-ended. Every configured
market is a legitimate property; secondary SEO properties are not deduplicated.
