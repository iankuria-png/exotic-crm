# MCP intelligence contracts

MCP is a read-only presentation layer over CRM calculations. It does not write
to clients, payments, messaging, Support Board, or WordPress.

| Tool family | CRM source | Scope and result policy |
| --- | --- | --- |
| CEO dashboard | `CeoDashboardDataService` | Preserves the existing summary, trend, market, peak-hour, agent and context calculations. MCP only projects transaction-level FX rows out of serialization. |
| Weekly executive scorecard | Completed CEO `Briefing` archives | Read-only completed weeks, optionally selected by `week_start`; it never generates a scorecard. |
| Team and member performance | `TeamActivityService` | Administrators/sub-admins see their permitted team; staff see their own scorecard. Staff identities are `agent_alias` values only. |
| Visitor demand | `ContactUnlockPulseService` and `ContactUnlockAnalyticsService` | Contact-unlock demand only, explicitly separate from subscription revenue. Native/normalized currency labels remain in the source payload. |
| Field commissions | `Commission` aggregates | Field staff see self; permitted administrators see scoped aggregates. No payout action or payment reference is returned. |
| Client operations | `Client` conversion queues | Aggregate/pseudonymous rows by default. Named rows require an admin-owned `mcp:identified-clients` grant, `identified: true`, `confirm_identified: true`, and a stated purpose. |
| Lifecycle and churn | `ChurnAggregatorService` | Returns CRM lifecycle, retention, churn and risk definitions with source coverage caveats. |
| City performance | `CityPerformanceService` | Keeps the 30% client-count / 40% views / 30% contact-rate calculation. If analytics inputs are unavailable, the response says so rather than inventing a score. |

All tool calls enforce active-user role and market scope. Result serialization
removes phone numbers, email addresses, payment references, message content,
Support Board data, raw staff identifiers and full staff names. It also removes
`normalization_meta.rows`, `unresolved_rows`, and currency-alias event detail;
only aggregate FX coverage and breakdown metadata may remain.

Named client requests create an `audit_log` record containing the actor, MCP
tool, queue/filter context, returned count and business purpose. The record is
for governance; it does not duplicate the returned client data.
