# Office Android receiving-phone setup

Preparation began30October; current checkpoint is6November2026. Start with the TZ office
phone receiving M-PESA messages. The CRM code/schema are deployed and the wallet/SIM is
enrolled in shadow; capture and activation gates remain disabled. The current status below
supersedes the dated stock-app preparation notes.

## Current setup — 6 November 2026

Ian authorized completion and shipping. The receiving account is an **Exotic-controlled
M-PESA mobile wallet registered to Amani Mollel**, not a till or collector-remittance
account. The user supplied its receiving number and payment image; private enrollment
evidence holds the full number. The limits decision is configurable **Unlimited** for
now, and reassignment grants the full purchased period from correction.

The separate **Exotic Payment Capture** app (`com.exotic.paymentcapture`) is installed
on the TECNO. It is a source-built, privately signed derivative of pinned official
SMSGate1.78.1. Release **1.78.1-exotic.3** targets Android14. APK SHA256:
`de6f5bd43dcb76bdf2b268121e6deae86215ad3416466c6a3d59467f047cba07`.
The 42 synthetic cases passed against this actual installed, non-debuggable APK;
Android confirms that outgoing SMS permission is absent. This proves the filter cases,
not end-to-end live receiving, overnight uptime or production activation acceptance.

Filtering runs before bridge logs, persistence or transport. Only the reviewed M-PESA
receiving/reversal boundary is eligible; personal/OTP messages are discarded on-phone.
Unknown payment-like formats either reach quarantine or set an aggregate format-drift
signal without uploading content. General inbox/export/send routes and public cloud
registration are disabled. Failed eligible deliveries retain durable queue payloads and
retry with a bounded backoff. Source preparation/build/verification tools live in
`tools/collection-phone/`; signing keys stay outside Git in private local storage.

Android reports slot1/Subscription ID1 and carrier **Safaricom — VodaCom Tanzania**.
The number and ICCID are unavailable through the ordinary Android API. The explicit
`android_subscription_v1` enrollment mode therefore combines this device-local identity
with Ian's confirmed receiving number; it never claims that the phone measured it.
Replacement/reset/reinstallation requires reviewed enrollment and a fresh cutover.
Legacy ICCID enrollment remains strict and does not silently fall back.

The handset binding and starting inbox cursor are configured. The cursor was obtained
using an ID-only query; no historical message body was exported. No SMS webhook is
connected yet. The local metadata API binds to handset loopback and is reachable only
through the paired ADB tunnel with a private credential. Stock SMSGate remains stopped.
The new package still needs its own final HiOS background/reopen/retry checks and actual
CRM signed heartbeat/receiving verification. Do not treat the earlier stock-app battery
settings or heartbeat results as acceptance of this separate package.

CRM code adds a persistent scoped phone-recovery card and global attention indicator.
After five minutes without a fresh signed capture heartbeat, staff are shown steps to
check power/network, unlock the handset and open **Exotic Payment Capture**. A fresh
validated capture heartbeat confirms recovery; a manual checkbox cannot clear the alert.
If the UI cannot load health, it reports that separately. Silence alone cannot identify
whether the phone is off, disconnected or stopped.

CRM code and the additive schema are deployed. The reviewed wallet/SIM is enrolled in
**shadow** with a private signing key and explicit configurable Unlimited limits. Server
cutover is6November10:25:42UTC. Independent verification finds zero events/receipts/
allocations/benefit effects. The endpoint is still disabled and **no phone webhook is
registered**. All capture/workspace/entitlement/automatic/writer/correction gates remain off.

The debugging connection dropped during the new package's HiOS setup. Reconnect the
paired phone by unlocking it, enabling **Wireless debugging**, and supplying its current
**IP address & Port** from that screen. Do not send a new pairing code or screen passcode
unless a fresh pairing is actually required. The office's current Internet path also gets
CRM HTTP403, while registered server checks get200; resolve access with hosting or verify
an available mobile-data/working-network fallback before enabling delivery.

Exact WordPress compensation/all-writer acceptance, independent reconciliation and seven
genuine clean observation days/100credits still precede autonomous activation. Historical
XML remains reconciliation-only. Shipping code is not proof of live phone acceptance.

The following sections retain the dated preparation and stock-app experiments. Current
filtered-app status above supersedes their earlier candidate/no-install descriptions.

## Confirmed Tanzania handset — 6 November 2026

Ian confirmed **TECNO SPARK 30C** for Tanzania. Paired ADB inspection verifies TECNO KL5,
Android14/API34, HiOS14.5.0 and security patch1July2026. Automatic time is enabled;
timezone Africa/Nairobi is UTC+3, matching Tanzania. Receiving-account/SIM mapping and
whether the earlier XML came from this handset remain unverified.

Ian confirmed that this phone also receives **personal SMS/OTPs**. The pinned SMSGate
v1.78.1 webhook schema has event/URL/source fields but no sender/content filter. Do not
connect `sms:received` directly to CRM: an on-device payment-only filter or a dedicated
receiving handset is required first. Filtering after uploading all SMS would not meet
this boundary. SMS read/receive/send permissions remain denied during preparation.

[TECNO lists Android 14 for the SPARK 30C](https://www.tecno-mobile.com/latam/phones/tech-specs/techspecs/spark-30c/),
which meets SMSGate's published Android 5.0+ minimum. Check Settings → My phone/About
phone for the actual installed Android and HiOS versions; menu wording may vary.
Compatibility with the minimum OS is not acceptance of background delivery.

Once the pinned secure app is ready for the capture trial:

- In SMSGate, open Settings → System and use its battery-optimization option, as
  described in the [official local-server FAQ](https://docs.sms-gate.app/faq/local-server/).
- In the handset's app settings, allow SMSGate background activity and background
  data. Enable auto-start/start-on-boot if available. HiOS labels vary; verify on this
  handset rather than assuming another TECNO model's menu path.
- Check the running-service notification and prove delivery after screen-off idle,
  reboot and Wi-Fi/mobile-data recovery before accepting unattended capture.

For now, prepare power/connectivity, automatic time (East Africa Time, UTC+3), screen
lock and the existing receiving SIM. Leave cloud registration, forwarding and history
exports off. Computer-assisted preparation below is separate from CRM device enrollment;
no production capture endpoint or collection account has been connected.

## Prepare the phone now

### Computer-assisted setup — 6 November 2026

Ian authorized connecting to and configuring the office handset over USB/shared Wi-Fi.
Use **Android Debug Bridge (ADB) + scrcpy** for direct inspection, input and screen
control. No remote-access account, root or public handset endpoint is required.

- Existing ADB verified: `~/Library/Android/sdk/platform-tools/adb`, platform-tools
  37.0.1. Initial USB discovery was empty; Android's local pairing-code flow subsequently
  paired this Mac over office Wi-Fi. Pairing code was entered locally and was not saved.
- Official scrcpy **5.0.1** downloaded, extracted and `--version` verified on this Intel
  Mac. Tool directory: `~/Library/Application Support/Exotic/AndroidTools/`.
  Archive SHA-256: `31a5467a9e907f162b093267cbbab1276b15c6e709c2ea8159566a8d165beb50`,
  matched against the official release metadata.
- Standard secure SMSGate **v1.78.1** selected from official release metadata for the
  trial, superseding the earlier observed v1.78.0 candidate. APK must match SHA-256
  `12ba5109d3cb613c64b8068c9846a645320d97fa801871a5f1ba2021a775eb93` before installation.
  APK checksum verified and package installed on the TECNO; version1.78.1 independently
  shown by Android App info. Inbox forwarding remains disconnected.

Unlock the phone, enable **USB debugging** inside Developer options, reconnect to this
Mac using a data-capable cable and accept Android's **Allow USB debugging** prompt for
this computer. Developer mode alone does not enable or authorize ADB. A charge-only
cable/USB port cannot provide this connection. If USB remains unavailable, Android's
paired Wireless debugging is the fallback; pair only to this Mac on the office network
and turn wireless debugging off after setup. Avoid exposing a legacy ADB port publicly.

Once connected, first verify the model/OS and capture a Settings screen, then configure
the approved secure bridge with forwarding still off. Do not open/export the inbox or
register a real payment webhook before filtering and private capture are ready. The
computer connection does not enable CRM production gates.

Sources: [scrcpy macOS installation](https://github.com/Genymobile/scrcpy/blob/master/doc/macos.md),
[ADB USB authorization and wireless pairing](https://developer.android.com/tools/adb),
[SMSGate v1.78.1](https://github.com/capcom6/android-sms-gateway/releases/tag/v1.78.1).

### Actual handset verification — 6 November

Screen control and ADB configuration work over the paired wireless connection. Secure
SMSGate installed; public cloud mode off. A temporary local authenticated API and
loopback-only receiver on this Mac exercised **system:ping only** through an ADB tunnel.
The receiver records event/body hashes, signature/freshness results and response status;
it does not retain payloads, SMS content or credentials in shared evidence.

- Native HMAC-SHA256 of exact body plus timestamp validates; no receiving-SMS webhook
  was registered and SMS permissions were denied throughout.
- Android battery Unrestricted plus allowed background AppOps did not alone prove
  reliable background delivery: early screen-off tests stalled for more than a minute.
- HiOS Phone Master → App Launch listed SMSGate as **Prohibited**. Changed only SMSGate
  to **Allowed**, restarted its service and repeated the test. Four of four heartbeats
  during a74-second screen-off window had valid signatures/fresh timestamps and204
  acknowledgments. This short check does not establish overnight/retry-horizon acceptance.
- First reboot showed local and ping services started before manually opening SMSGate,
  but the API did not respond. HiOS Battery Lab → Power Saving Management for Apps →
  SMSGate was still on **Intelligent Management**. Changed only this app to **No
  Restrictions**, verified the selected radio option, and its health API responded200
  without opening SMSGate. A repeat reboot with the final settings again started the
  services, but API/heartbeat recovery failed before opening the app. Phone-loopback,
  ADB-forwarded and office-LAN health checks timed out. Foregrounding SMSGate restored
  HTTP200. **Unattended startup is not accepted**; service presence alone is insufficient.
- A temporary503 response test produced retries; two distinct failed event identities
  subsequently received204 with valid signatures. No provider transaction or SMS send.
- Cleanup verified: zero registered webhooks, ping disabled, require-internet restored,
  test signing key rotated, local/cloud servers and start-on-boot switched off. App
  force-stopped with zero running service records. SMS and phone-state/number permissions
  denied. Temporary ADB tunnels and the Mac receiver removed/stopped. SMSGate remains
  installed; this Mac remains paired for the next authorized setup session.

Private tools/evidence are under `~/Library/Application Support/Exotic/AndroidTools/`;
credentials/signing keys stay in restricted local files. No production CRM flags,
payment/subscription records, receiving wallet or historical inbox were changed.

Next: implement/evaluate the plan's payment-only on-device bridge fallback and prove
its negative personal/OTP fixtures, boot/reconnect recovery and durable retry/catch-up
before enabling incoming capture. The observed SMSGate build is not accepted as the
unattended bridge for this mixed inbox. No additional phone setup is required from Ian
at this checkpoint.

Pinned source inspected for the filtering constraint:
[v1.78.1 webhook DTO](https://github.com/capcom6/android-sms-gateway/blob/v1.78.1/app/src/main/java/me/capcom/smsgateway/modules/webhooks/domain/WebHookDTO.kt).

### Basic preparation

1. Keep the receiving SIM in this handset. Record the phone make/model, Android version,
   receiving number, mobile network and SIM slot. Keep the full number/ICCID private;
   record them in the enrollment file, not a public document or chat screenshot.
2. Keep it on reliable office Wi-Fi, with mobile data available for fallback, and a
   dependable charger. Use automatic date/time and the correct local timezone.
3. Keep a screen lock and a named office custodian. Preserve the SMS inbox and the
   original XML backup. The old archive is reconciliation material and must not be
   re-exported into a live forwarding session.
4. Check whether the SIM/handset also receives personal messages or OTPs. That matters:
   a general `sms:received` webhook can carry unrelated inbox content. On-device filtering
   must be proven, or a dedicated receiving setup/private filtered bridge must be used.
5. Leave forwarding, cloud registration, inbox exports and payment automation off until
   the private shadow capture setup is ready. No provider PIN, banking password or
   payout credential belongs in the forwarding app.

## App and mode for the capture trial

The approved plan recommends **SMS Gateway for Android (SMSGate)** as the first bridge
trial. Use the standard secure build from the project's official release source, with
an exact version/APK digest recorded before installation. The official release page
resolved to **v1.78.0** when checked on30October. That is an observed candidate release,
not acceptance of its behavior on the actual office phone.

- [Official installation instructions](https://docs.sms-gate.app/installation/)
- [Observed v1.78.0 release](https://github.com/capcom6/android-sms-gateway/releases/tag/v1.78.0)
- [Local Server mode](https://docs.sms-gate.app/getting-started/local-server/)
- [Native webhooks and HMAC signing](https://docs.sms-gate.app/features/webhooks/)

The planned topology is local/direct configuration on a trusted office network and
outbound HTTPS to the private CRM capture endpoint. Do not use the public SMSGate cloud
or a public webhook tester for customer payment SMS. Do not expose the handset's local
server port on the public Internet. If the pinned release cannot meet filtering,
identity or reliability requirements in that topology, use the private filtered bridge
fallback before connecting the real inbox.

The app documentation specifies Android5.0+ and identifies `RECEIVE_SMS` for incoming
webhooks and `READ_PHONE_STATE` for SIM selection. `SEND_SMS` is for sending. We need a
receiving integration; confirm the required permissions on the pinned release and do
not configure an outbound SMS/payment workflow. If installation is blocked by device
security, stop and review the official package instead of disabling protection blindly.

## Connect only after a separately authorized shadow deployment

R&D supplies a private HTTPS endpoint, the enrolled device identity and a per-device
signing-key handoff through a private channel. Never paste the signing key into chat.
The CRM route shape is `/api/collection-events/v1/devices/{enrolled_device_id}`; the
actual host/ID must come from that enrollment and cannot be guessed from this guide.

1. Verify receiving-account ownership and the current SIM/ICCID/recipient/carrier mapping.
   Enroll it using the private configuration and named staff actor. Enrollment pins the
   server cutover instant and inbox cursor, and creates **shadow mode only**.
2. Register the signed `sms:received`, `system:ping` and `app:started` events against
   that endpoint. Native signing uses the exact body plus `X-Timestamp`, with the result
   in `X-Signature`. Confirm a retry uses an acceptable fresh delivery timestamp.
3. Enable background operation and unrestricted battery/background data as needed for
   this handset. Configure startup after reboot if supported, then prove it by testing;
   a setting being switched on is not proof that Android will keep the service alive.
4. Validate a permitted receiving event in shadow: one durable raw event, correct account
   and receipt, paired-message merge, visible sales suggestion and **zero activation**.
   Existing arriving payment notifications can be observed; a new real-money test needs
   separate authorization.
5. Test screen-off/idle, reboot, network loss, reconnect, duplicate deliveries and an
   outage beyond the bridge's finite retry horizon. Prove authenticated catch-up against
   the saved cursor without granting historical service or uploading an unfiltered inbox.

SMSGate documents finite retries, so keeping the phone charged is only part of the
acceptance. Independent statement comparison, backlog monitoring and a working catch-up
path are required. Do not press an inbox refresh/export option with webhooks enabled
while setting up this phone: historical individual exports also emit receiving events.

## Activation is a later, separate step

Shadow capture only records and suggests. Before autonomous activation, finish the exact
TZ/Tanzania WordPress operation and correction acceptance, coordinate/exclude alternate
writers, approve per-account caps and the correction-duration/collector policies, review
payer/purchase agreements and complete the observation/statement gates. Ghana needs its
own receiving fixtures and acceptance. No handset switch bypasses those CRM gates.
