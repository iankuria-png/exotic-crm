#!/usr/bin/env python3
"""Prepare the pinned, source-built office bridge. Never modifies the stock APK."""
import argparse
import hashlib
import json
import shutil
import tarfile
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent


def replace(path, before, after):
    text = path.read_text()
    if text.count(before) != 1:
        raise SystemExit(f"Expected one upstream source anchor in {path.name}")
    path.write_text(text.replace(before, after))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("destination", type=Path)
    parser.add_argument("--archive", type=Path)
    args = parser.parse_args()
    if args.destination.exists():
        raise SystemExit("Use a new empty build destination; existing directories are preserved.")
    manifest = json.loads((HERE / "upstream.json").read_text())
    archive = args.archive or args.destination.parent / "smsgate-1.78.1.tar.gz"
    archive.parent.mkdir(parents=True, exist_ok=True)
    if not archive.exists():
        urllib.request.urlretrieve(manifest["archive_url"], archive)
    if hashlib.sha256(archive.read_bytes()).hexdigest() != manifest["archive_sha256"]:
        raise SystemExit("Pinned upstream archive digest mismatch")
    args.destination.mkdir()
    with tarfile.open(archive) as source:
        source.extractall(args.destination, filter="data")
    root = next(path for path in args.destination.iterdir() if path.is_dir())
    for name, expected in manifest["source_files"].items():
        if hashlib.sha256((root / name).read_bytes()).hexdigest() != expected:
            raise SystemExit(f"Upstream source mismatch: {name}")

    java = root / "app/src/main/java/me/capcom/smsgateway"
    for name in ("PaymentOnlyPolicy.java", "PaymentCaptureState.kt", "CollectionCaptureSettings.kt"):
        shutil.copy2(HERE / "src" / name, java / "modules/receiver" / name)
    build = root / "app/build.gradle"
    replace(build, "    id 'com.google.gms.google-services'\n", "")
    replace(build, 'applicationId "me.capcom.smsgateway"', 'applicationId "com.exotic.paymentcapture"')
    replace(build, 'compileSdk 33', 'compileSdk 34')
    replace(build, 'targetSdk 33', 'targetSdk 34')
    replace(build, 'versionCode 1', 'versionCode ' + str(manifest['version_code']))
    replace(build, 'versionName "1.0.0"', 'versionName "' + manifest['version_name'] + '"')
    replace(build, 'storeFile file("keystore.jks")', 'storeFile file(System.getenv("EXOTIC_PHONE_KEYSTORE") ?: "missing-release-key.jks")')
    xml = root / "app/src/main/AndroidManifest.xml"
    replace(xml, 'android:allowBackup="true"', 'android:allowBackup="false"')
    replace(xml, '    <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />', '''    <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE_REMOTE_MESSAGING" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE_CONNECTED_DEVICE" />
    <uses-permission android:name="android.permission.CHANGE_NETWORK_STATE" />''')
    xml.write_text(xml.read_text().replace('android:foregroundServiceType="dataSync"', 'android:foregroundServiceType="remoteMessaging"'))
    for permission in ("SEND_SMS", "RECEIVE_MMS", "RECEIVE_WAP_PUSH"):
        replace(xml, f'    <uses-permission android:name="android.permission.{permission}" />\n', '')
    # The manifest has multiple services: insert once immediately after the application opening.
    replace(xml, 'android:theme="@style/Theme.SmsGateway">', '''android:theme="@style/Theme.SmsGateway">
        <provider android:name="com.google.firebase.provider.FirebaseInitProvider" android:authorities="${applicationId}.firebaseinitprovider" tools:node="remove" />
        <meta-data android:name="firebase_messaging_auto_init_enabled" android:value="false" />
        <meta-data android:name="firebase_analytics_collection_enabled" android:value="false" />''')
    replace(root / "app/src/main/res/values/strings.xml", '<string name="app_name" translatable="false">SMSGate</string>', '<string name="app_name" translatable="false">Exotic Payment Capture</string>')
    gateway = java / "modules/gateway/GatewaySettings.kt"
    replace(gateway, 'get() = storage.get<Boolean>(ENABLED) ?: false', 'get() = false // Office capture never registers with a public/private SMS cloud')
    replace(gateway, 'set(value) = storage.set(ENABLED, value)', 'set(value) = storage.set(ENABLED, false)')
    server = java / "modules/localserver/WebService.kt"
    # Configuration is via a paired ADB tunnel; no office-LAN API listener.
    replace(server, '            Netty,\n            port = port,', '            Netty,\n            host = "127.0.0.1",\n            port = port,')
    text = server.read_text()
    start = text.index('                    MessagesRoutes(applicationContext')
    end = text.index('                    WebhooksRoutes(', start)
    text = text[:start] + '                    // Sending and general inbox endpoints are absent in the office payment build.\n' + text[end:]
    server.write_text(text)
    receiver = java / "modules/receiver/ReceiverService.kt"
    text = receiver.read_text()
    text = text.replace('import org.koin.core.component.inject', 'import org.koin.core.component.inject\nimport org.koin.core.component.get')
    start = text.index('    suspend fun export(')
    end = text.index('    private fun saveAndBuildPayload(', start)
    text = text[:start] + '''    suspend fun export(
        context: Context,
        period: Pair<Date, Date>,
        messageTypes: Set<IncomingMessageType>,
        webhookDelivery: WebhookDelivery,
    ): Unit = throw IllegalStateException("History export is disabled in office payment capture")

    @Synchronized
    fun process(context: Context, message: InboxMessage, triggerWebhooks: Boolean) {
        if (message !is InboxMessage.Text) return
        if (!PaymentCaptureState.acceptsSubscription(context, message.subscriptionId)) return
        when (PaymentOnlyPolicy.classify(message.address, message.text)) {
            PaymentOnlyPolicy.Decision.REJECT -> return
            PaymentOnlyPolicy.Decision.DRIFT -> { PaymentCaptureState.noteDrift(context); return }
            PaymentOnlyPolicy.Decision.FINANCIAL -> Unit
        }
        if (!triggerWebhooks) return
        check(get<me.capcom.smsgateway.modules.localserver.LocalServerSettings>().enabled && webHooksService.select(null).any { it.event == WebHookEvent.SmsReceived }) {
            "Payment capture endpoint is not configured"
        }
        val slot = message.subscriptionId?.let { SubscriptionsHelper.getSimSlotIndex(context, it) }
        val addressing = PaymentCaptureState.addressing(context, message.subscriptionId)
        // Persist directly to the durable webhook queue; do not save an incoming-log marker
        // before queuing, because a crash in that gap would suppress authenticated catch-up.
        val payload = SmsEventPayload.SmsReceived(
            messageId = message.hashCode().toUInt().toString(16), message = message.text,
            sender = message.address, simNumber = slot?.plus(1), receivedAt = message.date,
            recipient = addressing["recipient"] as String?, subscriptionId = message.subscriptionId,
            recipientSource = addressing["recipientSource"] as String?, measuredRecipient = addressing["measuredRecipient"] as String?,
            carrierName = addressing["carrierName"] as String?,
        )
        webHooksService.emit(context, WebHookEvent.SmsReceived, payload)
    }

''' + text[end:]
    # The old helper is unreachable with export disabled; guard it as well.
    anchor = '    ): Pair<WebHookEvent, Any>? {\n'
    assert text.count(anchor) == 1
    text = text.replace(anchor, anchor + '        if (message !is InboxMessage.Text || PaymentOnlyPolicy.classify(message.address, message.text) != PaymentOnlyPolicy.Decision.FINANCIAL) return null\n')
    start = text.index('    fun start(context: Context) {')
    end = text.index('    fun stop(context: Context) {', start)
    text = text[:start] + '''    fun start(context: Context) {
        if (!PaymentCaptureState.configured(context) || !get<me.capcom.smsgateway.modules.localserver.LocalServerSettings>().enabled || !webHooksService.select(null).any { it.event == WebHookEvent.SmsReceived }) return
        MessagesReceiver.start(context)
        if (receiverSettings.contentProviderEnabled) smsContentObserver.start()
        PaymentCaptureState.running = true
    }

''' + text[end:]
    text = text.replace('    fun stop(context: Context) {', '    fun stop(context: Context) {\n        PaymentCaptureState.running = false', 1)
    receiver.write_text(text)
    card = java / "modules/localserver/domain/SimCard.kt"
    replace(card, '    val iccid: String?,', '    val iccid: String?,\n    val subscriptionId: Int? = null,')
    helper = java / "helpers/SubscriptionsHelper.kt"
    replace(helper, '                slotIndex = info.simSlotIndex,', '                slotIndex = info.simSlotIndex,\n                subscriptionId = info.subscriptionId,')
    payload = java / "modules/webhooks/payload/SmsEventPayload.kt"
    replace(payload, '        val message: String,\n        val receivedAt: Date,', '''        val message: String,
        val receivedAt: Date,
        val subscriptionId: Int? = null,
        val recipientSource: String? = null,
        val measuredRecipient: String? = null,
        val carrierName: String? = null,''')
    orchestrator = java / "modules/orchestrator/OrchestratorService.kt"
    replace(orchestrator, 'val simCards = SubscriptionsHelper.getActiveSimCards(context)', 'val simCards = SubscriptionsHelper.getActiveSimCards(context).filter { it.simNumber == androidx.preference.PreferenceManager.getDefaultSharedPreferences(context).getInt("collection.expected_sim_number", -1) }')
    observer = java / "modules/receiver/SmsContentObserver.kt"
    text = observer.read_text()
    start = text.index('        if (storage.smsLastProcessedID == 0L) {')
    end = text.index('\n\n', start)
    text = text[:start] + '''        check(androidx.preference.PreferenceManager.getDefaultSharedPreferences(context).getBoolean("collection.cursor_initialized", false)) {
            "A verified capture cursor must be provisioned before receiving"
        }''' + text[end:]
    observer.write_text(text)
    replace(observer, '                }\n                storage.smsLastProcessedID = id', '                    return // Keep high-water mark before a failed queue write; retry on reopen/change.\n                }\n                storage.smsLastProcessedID = id')
    events = java / "modules/webhooks/EventsReceiver.kt"
    replace(events, 'mapOf("health" to it.health)', 'mapOf("health" to it.health, "capture" to me.capcom.smsgateway.modules.receiver.PaymentCaptureState.health(get()))')
    settings = java / "modules/settings/SettingsService.kt"
    replace(settings, '        "device" to deviceSettings,', '        "device" to deviceSettings,\n        "collection" to me.capcom.smsgateway.modules.receiver.CollectionCaptureSettings(context),')
    # Source-generated webhook events remain native; remove raw payloads from legacy log context.
    legacy = java / "modules/webhooks/workers/SendWebhookWorker.kt"
    text = legacy.read_text().replace('"data" to payload,', '"bodyLogged" to false,')
    start = text.index('            val logsService = get<LogsService>()', text.index('        fun start('))
    end = text.index('\n        private const val INPUT_URL', start)
    text = text[:start] + '''            val queue = get<WebhookQueueRepository>()
            runBlocking { queue.enqueueWebhook(url = url, payload = gson.toJson(data)) }
            WebhookQueueProcessorWorker.start(context = context, internetRequired = internetRequired)
        }
''' + text[end:]
    legacy.write_text(text)
    for worker in [legacy, java / "modules/webhooks/workers/WebhookQueueProcessorWorker.kt"]:
        replace(worker, 'return ForegroundInfo(notificationId, notification)', 'return ForegroundInfo(notificationId, notification, if (android.os.Build.VERSION.SDK_INT >= 34) android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING else 0)')
    hooks = java / "modules/webhooks/WebHooksService.kt"
    replace(hooks, '                skippedCount++\n            }\n        }', '                throw e // Never advance the phone cursor after a failed durable queue write.\n            }\n        }')
    repository = java / "modules/webhooks/db/WebhookQueueRepository.kt"
    replace(repository, 'if (webhook.canRetry(maxRetries))', 'if (webhook.status != WebhookStatus.PERMANENTLY_FAILED)')
    replace(repository, '        payloadStorage.delete(webhookId.toString())\n', '        // Retain failed financial evidence for explicit recovery.\n')
    replace(repository, '        val multiplier = (1L shl (retryCount - 1))\n        return baseDelayMs * multiplier', '        val multiplier = (1L shl (retryCount - 1).coerceIn(0, 16))\n        return (baseDelayMs * multiplier).coerceAtMost(15L * 60L * 1000L)')
    dao = java / "modules/webhooks/db/WebhookQueueDao.kt"
    replace(dao, "status IN ('completed', 'permanently_failed') AND created_at", "status = 'completed' AND created_at")
    # Upstream source and all task input hashes make the candidate traceable.
    inputs = [HERE / "prepare.py", HERE / "upstream.json", *sorted((HERE / "src").glob("*"))]
    (args.destination / "prepared-manifest.json").write_text(json.dumps({"upstream": manifest,
        "inputs": {str(path.relative_to(HERE)): hashlib.sha256(path.read_bytes()).hexdigest() for path in inputs},
        "prepared_source": str(root), "live_accepted": False}, indent=2) + "\n")
    print(root)


if __name__ == "__main__":
    main()
