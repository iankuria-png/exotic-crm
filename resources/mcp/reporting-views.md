# Reporting views

The existing AI reporting views are dashboard-adjacent and may contain raw internal identifiers.
They are not automatically eligible for the MCP SQL hatch. MCP SQL is restricted to explicit
aggregate wrapper views that expose platform_id and no entity identifiers.

Use exotic_revenue_summary, exotic_revenue_trend or exotic_render_revenue_dashboard for
historical questions. Supply both from and to (ISO dates or offset timestamps); window=custom
is inferred. Date-only to includes that calendar day in Africa/Nairobi by default. For example,
from=2026-08-01, to=2026-11-14, bucket=month returns August through November-to-date.
Full November cannot be reported before it ends. All returned trend points are available in the app.

The aggregate-only vw_mcp_payment_collections view uses the same eligibility as exact MCP
collections. It includes subscriptions, renewals, contact unlocks and other paid services;
excludes manual review, reversals, invalid/test/sandbox records, wallet topups and ambiguous
receipts. amount/payments_count measure new cash; earned_service_amount/service_payment_count
also include wallet redemptions and unknown funding. Amounts are native currencies: group by
currency and do not sum unlike currencies. occurred_at is UTC. The older revenue rollup has
the narrower dashboard subscription definition; it is not the all-service collections view.

Direct payments-table SQL remains prohibited. SQL requires its existing administrator grant,
enabled hatch, explicit view allowlist, SELECT-only account and deployed view migration.
Normal revenue tools do not require SQL to be enabled. Unknown windows and incomplete date
bounds fail explicitly. Long intervals retain the 50,000-record and 9,000-bucket limits;
narrow the interval or use a coarser bucket if a limit is reached; no rows are silently truncated.

Catalogue coverage.from is client-record history, not payment coverage. payment_retention
gives authorised payment-candidate dates with no assurance of continuous or complete history.
Missing cached FX leaves USD unavailable and original currency totals visible.
