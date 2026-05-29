# Exotic WhatsApp Sidecar

This service is the isolated Baileys execution boundary for the Exotic CRM messaging gateway.

It is intentionally not required for Meta Cloud API sending. Laravel can route through Meta alone, and only uses this service for Baileys profiles.

## Runtime

- Node `>=20`
- Start: `npm start`
- Test: `npm test`

## Environment

```bash
PORT=4080
LARAVEL_BASE_URL=https://crm.exotic-online.com
WHATSAPP_SIDECAR_HMAC_SECRET=laravel_to_sidecar_current
WHATSAPP_SIDECAR_HMAC_SECRET_PREVIOUS=laravel_to_sidecar_previous
WHATSAPP_SIDECAR_LARAVEL_HMAC_SECRET=sidecar_to_laravel_current
WHATSAPP_SIDECAR_CLOCK_SKEW_SECONDS=300
WHATSAPP_SIDECAR_IDEMPOTENCY_TTL_MS=86400000
```

## Contracts

### `POST /messages`

Protected by `X-Signature` HMAC. Requires `Idempotency-Key`.

```json
{
  "senderId": 1,
  "attemptUuid": "uuid",
  "to": "254712345678",
  "body": "Message",
  "mediaUrl": null,
  "messageType": "transactional"
}
```

The idempotency record is written before enqueueing so Laravel retries do not duplicate sends.

### `GET /healthz`

Returns clock and restore state. Returns `503` during restore windows.

### `GET /metrics`

Returns per-sender queue depth, in-flight count, and local auth-state count.

## Baileys Adapter Boundary

The current scaffold provides the HTTP, HMAC, idempotency, queue, and metrics contract. The actual Baileys socket adapter should replace the local provider-id stub inside `src/server.js` without changing Laravel contracts.

Required before production activation:

- use a custom memory-only auth-state provider
- no `useMultiFileAuthState()` disk writes
- restore auth blobs from Laravel one-shot tokens
- debounce `session.creds.update`
- drain per-sender queue on `session.banned`
- emit `message.status` and `message.received` webhooks to Laravel
