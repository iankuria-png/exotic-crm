# EWMS Customer Service KPI payment evidence

- Updated: 20 November 2026. Status: implemented and SQLite-tested; shipping in progress.
- Request: add Magonya's supplied view safely, ship, then draft an email to him. Gmail source `1a1edd9c3bcd61af`, 18 November, subject “Adding a view table to the CRM”.
- Contract: [integration and deployment guide](../../ewms-kpi-payment-evidence.md). Eleven exact columns; supplied portable filters and success CASE; no customer/staff joins. Deliberate safety change: CREATE without destructive DROP; unexpected pre-existing view causes failure.
- Source inspected: local main `89ce67e7`; origin/main `bf04e935` has four newer payment-matching commits. Preserve all unrelated dirty/staged work and remote commits. No frontend/build changes required.
- Verification: PHP 8.2 `artisan test --filter=EwmsKpiPaymentEvidenceViewTest`: 5 tests / 17 assertions PASS, including 72 success combinations, precise columns/ownership, live reads, exclusions, safe repeat creation and rollback/reapply. Pint check PASS. In-memory SQLite, isolated from configured application database. Local MySQL unavailable (PDO connection failed, no Local socket found); do not claim engine-specific verification.
- Important limits: supplied view is not full `Payment::businessVisible()` parity (legacy JSON test flag and collection-sales confirmation guard omitted as requested). No account/grant provisioned; dedicated SELECT-only account and real access-denial checks required before EWMS use.
- Owned paths: migration `2026_11_20_100000_create_ewms_kpi_payment_evidence_view.php`, `tests/Feature/EwmsKpiPaymentEvidenceViewTest.php`, integration guide, this task; one state navigation line kept local to avoid committing unrelated state edits.
- Deployment: not live-verified; production decision requested separately because the CRM ship workflow means commit/push and manual cPanel deployment. Do not claim push creates the view.
- Next: scoped commit/push, changelog, reply draft to Magonya accurately reflecting release status and access prerequisites.
