# Wallet API Phase 5 Contract

This document freezes the CRM contract added in Phase 5.

## Scope

- Wallet balance and history for WordPress server-to-server calls
- Wallet-funded subscription checkout
- Wallet top-up initiation
- Browser completion return page
- Paystack, Pesapal, and M-Pesa webhook settlement
- Optional user-facing STK retry flow

CyberSource is intentionally excluded from this wallet surface. Existing legacy CyberSource routes remain separate.

## Routes

### CRM admin

- `GET /api/crm/clients/{client}/wallet`
- `GET /api/crm/clients/{client}/wallet/transactions`
- `POST /api/crm/clients/{client}/wallet/topup`
- `POST /api/crm/clients/{client}/wallet/adjustment`

### WP -> CRM

- `GET /api/wallet/balance`
- `GET /api/wallet/transactions`
- `POST /api/wallet/subscribe`
- `POST /api/billing/initiate`
- `POST /api/billing/retry-stk`

### Provider callbacks

- `POST /api/billing/paystack/webhook`
- `GET|POST /api/billing/pesapal/ipn`
- `POST /api/billing/mpesa/callback`

### Browser return

- `GET /billing/complete`

Registered above the CRM SPA catch-all and excluded from the catch-all pattern.

## WP -> CRM Auth

Required on all wallet routes:

- `Authorization: Bearer <wallet bearer key>`
- `X-Exotic-Platform-Id: <platform id>`
- `X-Exotic-Timestamp: <unix timestamp>`

Required on write routes:

- `X-Idempotency-Key: <unique key>`
- `X-Exotic-Signature: <hmac sha256>`

Clock skew window:

- 300 seconds

Signature payload:

```text
<timestamp>\n
<HTTP_METHOD>\n
<request path starting with />\n
<platform id>\n
<idempotency key>\n
<sha256 hex of raw request body>
```

Signature algorithm:

- `hash_hmac('sha256', payload, wp_to_crm_hmac_secret)`

## Request Shapes

### `GET /api/wallet/balance`

Query:

- `wp_user_id` or `wp_post_id`

Response:

```json
{
  "client": {
    "id": 12,
    "wp_user_id": 8801,
    "wp_post_id": 4401,
    "profile_url": "https://escorts.example.test/?p=4401"
  },
  "balance": "1800.00",
  "currency": "KES",
  "mode": "sandbox",
  "refreshed_at": "2026-03-07T15:45:58+03:00",
  "wallet_last_synced_at": null,
  "last_topup": {
    "id": 9,
    "type": "credit",
    "amount": "1200.00",
    "currency": "KES",
    "balance_after": "1800.00",
    "description": "Wallet top-up via PAYSTACK",
    "reference_type": "wallet_topup",
    "reference_id": 55,
    "payment_id": null,
    "deal_id": null,
    "created_at": "2026-03-07T15:45:58+03:00",
    "metadata": null
  },
  "transactions": [],
  "config": {
    "market": {
      "platform_id": 1,
      "currency": "KES"
    },
    "topup_presets": ["500.00", "1000.00", "2500.00"],
    "providers": {
      "paystack": {
        "enabled": true,
        "min_amount": "100.00",
        "max_amount": "500000.00"
      },
      "pesapal": {
        "enabled": true,
        "min_amount": "100.00",
        "max_amount": "150000.00"
      },
      "mpesa_stk": {
        "enabled": true,
        "min_amount": "100.00",
        "max_amount": "150000.00",
        "transport": "direct_provider"
      }
    },
    "show_refresh_button": true,
    "allow_combined_topup_subscribe": true,
    "recent_transactions_limit": 10,
    "wallet_refresh_rate_limit_seconds": 20,
    "wallet_refresh_timeout_seconds": 15,
    "topup_poll_interval_seconds": 8,
    "sandbox_badge": true,
    "business_name": "Exotic Sandbox Billing",
    "description": "Sandbox wallet top-up"
  }
}
```

### `POST /api/wallet/subscribe`

Body:

```json
{
  "wp_user_id": 8801,
  "product_id": 14,
  "duration": "1_month"
}
```

Response:

```json
{
  "message": "Subscription paid from wallet.",
  "replayed": false,
  "payment": {
    "id": 71,
    "reference_number": "WSUB-2E4F6D0C1B8A26F4C1",
    "status": "completed",
    "amount": "2400.00",
    "currency": "KES"
  },
  "deal": {
    "id": 18,
    "status": "active",
    "plan_type": "premium",
    "expires_at": "2026-04-06T15:45:58+03:00"
  },
  "wallet": {
    "balance": "2600.00",
    "currency": "KES",
    "transaction": {
      "id": 22,
      "type": "debit",
      "amount": "2400.00",
      "currency": "KES",
      "balance_after": "2600.00",
      "description": "Wallet subscription payment for Premium Escort (1 Month)",
      "reference_type": "wallet_subscription",
      "reference_id": 71,
      "payment_id": 71,
      "deal_id": null,
      "created_at": "2026-03-07T15:45:58+03:00",
      "metadata": {
        "product_id": 14,
        "duration_key": "1_month",
        "duration_days": 30
      }
    }
  }
}
```

### `POST /api/billing/initiate`

Body:

```json
{
  "wp_user_id": 8801,
  "provider": "paystack",
  "amount": "1200.00"
}
```

Optional combined top-up:

```json
{
  "wp_user_id": 8801,
  "provider": "paystack",
  "amount": "1200.00",
  "auto_subscribe": {
    "enabled": true,
    "product_id": 14,
    "duration": "1_month"
  }
}
```

Redirect response:

```json
{
  "message": "Billing initiation created.",
  "replayed": false,
  "mode": "sandbox",
  "provider": "paystack",
  "payment": {
    "id": 90,
    "transaction_uuid": "d420d62e-34f0-4f23-a3ab-bad8a2b1fa56",
    "reference_number": "WTU-53AF4F0AF45A1E03B4",
    "transaction_reference": "WTU-53AF4F0AF45A1E03B4",
    "status": "pending",
    "purpose": "wallet_topup",
    "source": "gateway",
    "provider": "paystack",
    "provider_environment": "sandbox",
    "amount": "1200.00",
    "currency": "KES",
    "completed_at": null,
    "failure_reason": null
  },
  "action": {
    "type": "redirect",
    "url": "https://checkout.paystack.test/redirect",
    "provider_reference": "WTU-REF-001",
    "access_code": "ACCESS-CODE-001",
    "public_key": "pk_test_wallet"
  }
}
```

STK response:

```json
{
  "message": "Billing initiation created.",
  "replayed": false,
  "mode": "sandbox",
  "provider": "mpesa_stk",
  "payment": {
    "id": 91,
    "transaction_uuid": "123e4567-e89b-12d3-a456-426614174000",
    "reference_number": "WTU-0D8B91AB77A4E110C3",
    "transaction_reference": "https://sandbox.kopokopo.test/incoming_payments/abc123",
    "status": "pending",
    "purpose": "wallet_topup",
    "source": "gateway",
    "provider": "mpesa_stk",
    "provider_environment": "sandbox",
    "amount": "900.00",
    "currency": "KES",
    "completed_at": null,
    "failure_reason": null
  },
  "action": {
    "type": "stk_pending",
    "message": "STK push sent. Complete the prompt on your phone.",
    "retry_available": true,
    "poll_after_seconds": 8,
    "provider_reference": "https://sandbox.kopokopo.test/incoming_payments/abc123"
  }
}
```

### `POST /api/billing/retry-stk`

Body:

```json
{
  "wp_user_id": 8801,
  "payment_id": 91,
  "phone": "254700000111"
}
```

Response:

```json
{
  "message": "STK retry dispatched.",
  "payment": {
    "id": 91,
    "transaction_uuid": "123e4567-e89b-12d3-a456-426614174000",
    "reference_number": "WTU-0D8B91AB77A4E110C3",
    "transaction_reference": "https://sandbox.kopokopo.test/incoming_payments/abc123",
    "status": "pending",
    "purpose": "wallet_topup",
    "source": "gateway",
    "provider": "mpesa_stk",
    "provider_environment": "sandbox",
    "amount": "900.00",
    "currency": "KES",
    "completed_at": null,
    "failure_reason": null
  },
  "action": {
    "type": "stk_pending",
    "message": "STK push sent. Complete the prompt on your phone.",
    "retry_available": true,
    "poll_after_seconds": 8,
    "provider_reference": "https://sandbox.kopokopo.test/incoming_payments/abc123"
  }
}
```

## Failure Envelopes

Insufficient wallet balance:

```json
{
  "message": "Insufficient wallet balance.",
  "error_code": "insufficient_balance"
}
```

Unsupported provider:

```json
{
  "message": "CyberSource remains a legacy coexistence flow and is not part of the wallet billing API.",
  "error_code": "provider_not_supported"
}
```

Wallet disabled or invalid mode:

```json
{
  "message": "Wallet billing is disabled for this market.",
  "error_code": "wallet_disabled"
}
```

Failed STK retry:

```json
{
  "message": "This payment is not eligible for STK retry.",
  "error_code": "stk_retry_failed"
}
```

## Browser Completion

`GET /billing/complete` renders a neutral payment-processing page and redirects to the client WordPress profile with:

- `wallet_refresh=1`
- `wallet_payment_status=<payment status>`
- `wallet_payment_id=<crm payment id>`

Sandbox mode shows a sandbox badge on the completion page.

## Notes

- Provider webhooks are authoritative for wallet crediting.
- Wallet crediting is idempotent via `wallet-topup-credit:<payment_id>`.
- Wallet-funded subscription debits are idempotent via the request `X-Idempotency-Key`.
- M-Pesa top-up initiation in this contract requires `mpesa_stk.transport=direct_provider`.
- Pesapal IPN accepts both `GET` and `POST` because the provider return shape may vary by environment, but the documented route remains `/api/billing/pesapal/ipn`.
