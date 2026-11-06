# Tanzania office payment capture

Pinned SMSGate1.78.1 source derivative for the mixed personal/payment inbox. Upstream
Apache2.0 source/license are retained in each prepared tree; no stock binary is patched.
The reviewed candidate is a separately signed `com.exotic.paymentcapture` package.

`prepare.py DEST --archive ARCHIVE` checks archive/source hashes and creates a fresh
source tree. `build.py SOURCE --sdk SDK --java JDK17 --private-key-directory PRIVATE
--log LOG` creates/verifies the release and an APK manifest. Keep signing material
outside the repository with owner-only permissions. `verify_on_device.py MANIFEST`
with the same SDK/JDK/private-key options, `--serial SERIAL --install --output JSON`,
runs 42 synthetic cases against the actual installed filter and removes its test-only
instrumentation APK. It does not read/inject SMS. A successful run force-stops capture.

Use the authenticated loopback-only API through paired ADB. Read `GET /device` and
`GET /settings` privately. After verifying identity and ensuring `GET /webhooks` is
empty, grant receiving/read permissions, then read only `collection.inbox_max_id`.
Save a complete binding through **PATCH** `/settings` under `collection`:
`expected_subscription_id`, `expected_recipient` (normalized Tanzania number),
`expected_carrier`, `expected_sim_number`, `start_after_inbox_id`. Encode these five
values as strings: upstream Gson generic settings maps parse JSON numbers as doubles.
Require `binding_configured`, `cursor_initialized` and the saved `processed_after_id`.
Do not register an SMS webhook before this check and the CRM cutover/enrollment.
A retried identical binding cannot rewind the cursor; changes require reenrollment.

Native signed outbound webhooks use direct HTTPS to the private enrolled CRM endpoint.
Public cloud, sending/general inbox/history export are disabled. Install/startup/filter,
actual signed delivery, outage/retry/catch-up and HiOS settings need separate acceptance.
Do not treat synthetic filter success as full production readiness. See
`docs/manual-payment-android-setup.md` and the approved phone-release plan.
