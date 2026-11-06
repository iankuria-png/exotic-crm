package me.capcom.smsgateway.modules.receiver

import android.content.Context
import android.Manifest
import android.content.pm.PackageManager
import android.provider.Telephony
import androidx.core.content.ContextCompat
import androidx.preference.PreferenceManager
import me.capcom.smsgateway.modules.settings.Exporter
import me.capcom.smsgateway.modules.settings.Importer
import org.koin.core.component.KoinComponent
import org.koin.core.component.get

/** Configuration is available only on the authenticated, loopback-only local API. */
class CollectionCaptureSettings(private val context: Context) : Exporter, Importer, KoinComponent {
    private val preferences = PreferenceManager.getDefaultSharedPreferences(context)
    override fun export(): Map<String, *> = mapOf(
        "binding_configured" to preferences.contains("collection.expected_subscription_id"),
        "filter_version" to PaymentOnlyPolicy.VERSION,
        "format_drift" to preferences.getBoolean("collection.format_drift", false),
        "cursor_initialized" to preferences.getBoolean("collection.cursor_initialized", false),
        "inbox_max_id" to maximumInboxId(),
        "processed_after_id" to get<StateStorage>().smsLastProcessedID,
    )

    override fun import(data: Map<String, *>): Boolean {
        val keys = setOf("expected_subscription_id", "expected_recipient", "expected_carrier", "expected_sim_number", "start_after_inbox_id")
        require(data.keys.all { it in keys || it == "reset_format_drift" }) { "Unknown collection setting" }
        if (data.keys.any { it in keys }) {
            require(keys.all { data.containsKey(it) }) { "Complete verified SIM binding required" }
            val subscriptionId = data["expected_subscription_id"]?.toString()?.toIntOrNull()
            val recipient = data["expected_recipient"]?.toString().orEmpty()
            val carrier = data["expected_carrier"]?.toString().orEmpty()
            val slot = data["expected_sim_number"]?.toString()?.toIntOrNull()
            val cursor = data["start_after_inbox_id"]?.toString()?.toLongOrNull()
            require(subscriptionId != null && subscriptionId > 0 && recipient.matches(Regex("255[67][0-9]{8}")) &&
                carrier.isNotBlank() && carrier.length <= 128 && slot != null && slot in 1..16 && cursor != null && cursor >= 0) { "Verified Tanzania SIM values and cursor required" }
            if (preferences.getBoolean("collection.cursor_initialized", false)) {
                require(preferences.getLong("collection.enrolled_cursor", -1) == cursor &&
                    preferences.getInt("collection.expected_subscription_id", -1) == subscriptionId &&
                    preferences.getString("collection.expected_recipient", null) == recipient &&
                    preferences.getString("collection.expected_carrier", null) == carrier &&
                    preferences.getInt("collection.expected_sim_number", -1) == slot) { "Existing binding/cursor requires reviewed re-enrollment" }
                return false // A retried provisioning request never rewinds a progressed cursor.
            }
            get<StateStorage>().smsLastProcessedID = cursor
            preferences.edit().putInt("collection.expected_subscription_id", subscriptionId).putString("collection.expected_recipient", recipient)
                .putString("collection.expected_carrier", carrier).putInt("collection.expected_sim_number", slot)
                .putLong("collection.enrolled_cursor", cursor).putBoolean("collection.cursor_initialized", true).commit()
        }
        if (data["reset_format_drift"] == true) preferences.edit().putBoolean("collection.format_drift", false).commit()
        return data.isNotEmpty()
    }

    private fun maximumInboxId(): Long? {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_SMS) != PackageManager.PERMISSION_GRANTED) return null
        return context.contentResolver.query(Telephony.Sms.Inbox.CONTENT_URI, arrayOf(Telephony.Sms._ID), null, null,
            Telephony.Sms._ID + " DESC LIMIT 1")?.use { if (it.moveToFirst()) it.getLong(0) else 0L }
    }
}
