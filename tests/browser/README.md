# Browser Automation Harness

This directory contains the first local Playwright harness for `BILL-010`.

Current scope:

- local browser automation wiring
- API-backed auth seeding for CRM roles
- representative smoke coverage for `admin`, `sub_admin`, and `sales`
- explicit placeholders for forbidden-state, degraded diagnostics, Billing Diagnostics, and wallet fallback scenarios

## Prerequisites

1. Install frontend dependencies:

```bash
npm install
```

2. Install the Playwright Chromium browser:

```bash
npm run test:browser:install
```

3. Make the CRM available locally. By default the harness uses:

```bash
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000
```

Override `PLAYWRIGHT_BASE_URL` if your local CRM runs elsewhere.
The harness refuses non-local hosts unless you explicitly opt in with:

```bash
export PLAYWRIGHT_ALLOW_REMOTE_HOSTS=true
```

4. Provide role credentials for the flows you want to execute:

```bash
export PLAYWRIGHT_ADMIN_EMAIL='admin@example.com'
export PLAYWRIGHT_ADMIN_PASSWORD='secret'
export PLAYWRIGHT_SUB_ADMIN_EMAIL='subadmin@example.com'
export PLAYWRIGHT_SUB_ADMIN_PASSWORD='secret'
export PLAYWRIGHT_SALES_EMAIL='sales@example.com'
export PLAYWRIGHT_SALES_PASSWORD='secret'
```

Optional future fixture ids:

```bash
export PLAYWRIGHT_PAYMENT_ID_FOR_DIAGNOSTICS='123'
export PLAYWRIGHT_CLIENT_ID_FOR_ACTIVATION='456'
export PLAYWRIGHT_CLIENT_ID_FOR_WALLET='456'
```

## Commands

List the suite:

```bash
npm run test:browser:list
```

Run the harness:

```bash
npm run test:browser
```

Run headed:

```bash
npm run test:browser:headed
```

Open Playwright UI mode:

```bash
npm run test:browser:ui
```

Open the HTML report after a run:

```bash
npm run test:browser:report
```

## What runs now

Runnable smoke coverage:

- `admin` can reach `Settings > Wallet`
- `admin` can reach `Payments`
- `admin` can open the `Billing` workspace shell
- `admin` can lazy-load the `Billing > Diagnostics` shell
- `sub_admin` can reach `Settings > Wallet`
- `sub_admin` can reach `Payments`
- `sales` is redirected away from `Settings`
- `sales` can reach `Payments`

This is `8 runnable smoke checks`, not full workflow coverage.

Optional seeded workflow coverage:

- current `Payment Diagnostics` drawer contract for a seeded payment fixture
- current client `Activate Subscription` dialog contract for a seeded client fixture

These tests skip automatically until you provide the optional fixture ids above.

Intentional placeholders:

- out-of-scope forbidden-state coverage for `sub_admin`
- wallet renewal fallback visibility

The remaining placeholders are tracked with `test.fixme(...)` and should never be counted as implemented coverage.

The placeholders are marked with `test.fixme(...)` so they show up in the suite without pretending coverage exists yet.
