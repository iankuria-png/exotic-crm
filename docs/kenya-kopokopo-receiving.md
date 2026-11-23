# Kenya direct till receiving

Direct customer payments to till **268491** enter the Collections workspace as KopoKopo receipts. STK checkout on **K317087** keeps its existing callback and fulfillment path. CRM can review and activate a new direct payment through the existing sales drawer. A protected payer identifier can suggest a profile; it never proves the intended purchase.

## Staff workflow

1. Open Payments → Incoming payments and choose Kenya. The receiving connection shows setup, reconciliation and review states separately from individual receipts.
2. Search by receipt code, supplied payer name or supplied full phone. When KopoKopo protects the phone, the screen says so; no hash is shown or treated as a telephone number.
3. Open **Match & activate**, confirm the beneficiary and purchased service, review the allocation and activate. Existing-use checks and durable site operation identities protect retries.
4. A reversal stays red and opens **View receipt**. A later credit notification cannot clear it. Reversals after activation require a staff/Finance decision; receiving does not automatically revoke service or refund money.
5. Historical statement receipts are for comparison and payer history. Importing or replaying them cannot activate a subscription.

Only Kenya-authorized administrators manage the connection. Sales staff can review receipts in their permitted markets. Technical addresses stay inside Connection details. A manager can verify an interrupted request using its official KopoKopo resource address; CRM checks ownership and scope before adopting it.

## Configure after deployment

The release includes one additive migration. Preserve the database, encrypted evidence and APP_KEY in a private backup before migrating. Keep `KOPOKOPO_COLLECTION_AUTOMATIC_RELEASED=false` for the initial receiving release.

```dotenv
KOPOKOPO_COLLECTION_ENABLED=true
KOPOKOPO_COLLECTION_AUTOMATIC_RELEASED=false
```

Existing `COLLECTION_CAPTURE_ENABLED` and `COLLECTION_WORKSPACE_ENABLED` must be enabled for the current Collections workspace. Reuse the selected active **production** KopoKopo provider profile; this adapter never replaces its STK till or asks staff to paste credentials. `APP_URL` must be `https://crm.exotic-online.com`.

After the new source and matching build are installed:

```sh
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan queue:restart
php artisan schedule:list
```

Use the server's PHP binary required by this CRM. Its scheduler must already run every minute, and the existing collections outbox worker must continue running. The new bounded reconciliation command is scheduled every five minutes, offset to minute 4. Confirm both commands in the actual server scheduler/worker configuration.

In Kenya incoming payments:

1. **Set up receiving till** creates the one assisted account and records its cutover time. It does not turn on automation or contact the provider.
2. **Connect KopoKopo** registers `buygoods_transaction_received` and `buygoods_transaction_reversed` on v2, scoped to till 268491, with K2Connect payloads (`enable_daraja_payload=false`). Do this only once the public callback route is deployed and reachable. The application-level switches in the portal do not prove event subscriptions exist. The portal's Reversals switch was off during research; if the provider refuses a reversed-event subscription, resolve that entitlement with KopoKopo before accepting automatic readiness. Do not assume permission to initiate financial reversals is required merely to receive reversal notifications.
3. Wait at least 30 seconds after setup, then **Check reconciliation**. A successful empty result is valid evidence for a quiet till. The clock starts at cutover; historical CSV rows do not advance live coverage.
4. If a request times out, verify its existing result before creating another. Missing/ambiguous identities remain held. GET adoption requires the exact till/window/callback identity. A subscription GET must also prove K2Connect format; if the provider omits that field, leave it held and obtain provider confirmation rather than guessing.

The server creates callback URLs from the enrolled feed UUID:

- `POST /api/collection-events/v1/kopokopo/{feed}`
- `POST /api/collection-events/v1/kopokopo/{feed}/polls/{poll}`

Both validate the exact raw body using `X-KopoKopo-Signature` and the profile's SDK `api_key`. Never reserialize the body before verification. No existing `/api/payment-callback` setting changes.

## Historical statement import

The accepted incoming statement is the transaction report, not the outgoing Payments Report. Keep the original file private. Preview is the default and validates the entire file before any apply.

```sh
php artisan collections:kopokopo-import '/private/path/transactions_report.csv'
```

The supplied report preview validated 3,508 rows, two reversed, SHA256 `a75a479b8c1246efa7a426d11cb48e5d8e400b838979e2531ba6e88af54d0015`. This establishes parser compatibility with that file. It does not prove live API sender-identifier equivalence. If importing, use the exact preview hash and an actual Kenya-authorized manager's CRM user ID:

```sh
php artisan collections:kopokopo-import '/private/path/transactions_report.csv' --apply --actor=MANAGER_ID --expected-sha256=PREVIEW_SHA256
```

Apply is audited and idempotent. New statement rows remain historical. Overlap can enrich an existing live receipt without changing its first ingest epoch or historical classification. No payment/revenue record or entitlement is manufactured by import. Payer learning can be enabled by an administrator in the account's automation rules, with a reason. Historical suggestions remain advisory and cannot authorize the next purchase.

## Evidence recovery

```sh
php artisan collections:kopokopo-reconcile
php artisan collections:kopokopo-review EVENT_ID
```

The review command previews quarantined evidence without exposing its raw body. `--apply` requires `--actor`, `--expected-sha256` and a meaningful `--reason`. Default action `dismiss` acknowledges the event without clearing any receipt conflict, reversal or ownership hold. `--action=replay` is restricted to previously unrecognized signed webhook envelopes that the current parser can now interpret; replay remains historical and cannot grant service. Identity conflicts cannot be rewritten by replay.

## Automatic activation acceptance

Automatic deployment eligibility remains off until engineering releases the account and the existing Finance/Operations acceptance policy passes. Check actual signed received/reversed envelopes, raw signature verification, till/currency/amount/time/resource identity, sender-hash consistency with the CSV, duplicate delivery, interrupted/early callbacks, missed-delivery polling, quiet-till health and a controlled assisted activation/correction. Record evidence in the account's existing sign-offs; never mark those from fixture results.

Automatic matching requires a valid reviewed purchase for that receipt, or a pre-existing manager-approved standing agreement with current payer authority. Names, amounts and historical identity alone cannot activate service. Shared or conflicting payers remain for staff review. Existing limits, temporal approval, receipt spending, current site identity and correction gates still apply. New policies do not adopt old receipts into a later automation epoch.

## Rollback

Prefer an in-place feature disable:

```dotenv
KOPOKOPO_COLLECTION_ENABLED=false
KOPOKOPO_COLLECTION_AUTOMATIC_RELEASED=false
```

Rebuild the config cache and restart workers. Keep the shared receipt spending guards, all tables, encrypted events and operation identities. Disabling receiving must not delete pending or completed receipt evidence. Do not run `migrate:rollback`; the transport migration refuses to remove enrolled evidence.

For a full parent-code incident rollback, pause Kenya manual grants first and retain the database/schema and APP_KEY backup. Reconcile any in-flight site operations and captured receipt ownership before re-enabling older writers. Preserve the previous hashed assets for open tabs and restore the matching old manifest. Do not revert receipt/payment rows or retry an uncertain activation as a new operation.
