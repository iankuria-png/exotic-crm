# CRM taxonomy

- A client is a cached WordPress profile in clients; WordPress remains the source of truth.
- A CRM user is an internal operator in users; their market scope comes from role and assignments.
- A visitor is an external person using the public site. A visitor is not a client.
- A customer is a billing concept tied to reportable payment activity.
- visitor_contact_unlocks.client_id identifies the advertiser whose contact was unlocked, not the visitor.

MCP responses use stable opaque handles for external individual entities. For administrator and sub-admin callers, agent-performance responses may include an operator's `agent_display_name`; internal user IDs, phones, emails, bios and free text do not leave the CRM.
