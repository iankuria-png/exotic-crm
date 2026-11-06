package me.capcom.smsgateway.modules.receiver

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import androidx.preference.PreferenceManager
import me.capcom.smsgateway.helpers.SubscriptionsHelper
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

object PaymentCaptureState {
    @Volatile var running = false

    fun noteDrift(context: Context) {
        PreferenceManager.getDefaultSharedPreferences(context).edit().putBoolean("collection.format_drift", true).commit()
    }

    fun health(context: Context): Map<String, Any> {
        val preferences = PreferenceManager.getDefaultSharedPreferences(context)
        val clock = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US).apply { timeZone = TimeZone.getTimeZone("UTC") }
        val sms = listOf(Manifest.permission.READ_SMS, Manifest.permission.RECEIVE_SMS).all {
            ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED
        }
        // Android recommends device-local Subscription ID; ICCID is privileged on Android 10+.
        val expectedId = preferences.getInt("collection.expected_subscription_id", -1)
        val expectedPhone = preferences.getString("collection.expected_recipient", null)
        val expectedCarrier = preferences.getString("collection.expected_carrier", null)
        val expectedSlot = preferences.getInt("collection.expected_sim_number", -1)
        val simVerified = runCatching {
            expectedId > 0 && !expectedPhone.isNullOrBlank() && !expectedCarrier.isNullOrBlank() &&
                SubscriptionsHelper.getActiveSimCards(context).count {
                    it.simNumber == expectedSlot && it.subscriptionId == expectedId &&
                        (it.phoneNumber.isNullOrBlank() || normalize(it.phoneNumber) == expectedPhone) && it.carrierName == expectedCarrier
                } == 1
        }.getOrDefault(false)
        return mapOf("observedAt" to clock.format(Date()), "running" to running, "smsPermission" to sms,
            "filterVersion" to PaymentOnlyPolicy.VERSION, "simVerified" to simVerified,
            "formatDrift" to preferences.getBoolean("collection.format_drift", false),
            "simBindings" to runCatching { SubscriptionsHelper.getActiveSimCards(context).filter { it.simNumber == expectedSlot } }.getOrDefault(emptyList()))
    }

    fun configured(context: Context): Boolean = PreferenceManager.getDefaultSharedPreferences(context).let {
        it.getInt("collection.expected_subscription_id", -1) > 0 && it.getBoolean("collection.cursor_initialized", false)
    }

    fun acceptsSubscription(context: Context, actual: Int?): Boolean = actual != null && actual > 0 &&
        actual == PreferenceManager.getDefaultSharedPreferences(context).getInt("collection.expected_subscription_id", -1)

    fun addressing(context: Context, actual: Int?): Map<String, Any?> {
        val preferences = PreferenceManager.getDefaultSharedPreferences(context)
        check(acceptsSubscription(context, actual)) { "Receiving subscription changed" }
        val card = SubscriptionsHelper.getActiveSimCards(context).singleOrNull {
            it.subscriptionId == actual && it.simNumber == preferences.getInt("collection.expected_sim_number", -1) &&
                it.carrierName == preferences.getString("collection.expected_carrier", null)
        } ?: throw IllegalStateException("Receiving SIM needs verification")
        val expected = preferences.getString("collection.expected_recipient", null)
        val measured = if (card.phoneNumber.isNullOrBlank()) null else normalize(card.phoneNumber)
        check(!expected.isNullOrBlank() && (card.phoneNumber.isNullOrBlank() || measured == expected)) { "Receiving number changed" }
        return mapOf("recipient" to (measured ?: expected), "recipientSource" to if (measured == null) "enrollment" else "device",
            "measuredRecipient" to measured, "subscriptionId" to actual, "carrierName" to card.carrierName)
    }

    private fun normalize(value: String?): String? {
        var digits = value?.filter(Char::isDigit) ?: return null
        if (digits.startsWith("00")) digits = digits.drop(2)
        if (digits.startsWith("0") && digits.length == 10) digits = "255" + digits.drop(1)
        return digits.takeIf { it.matches(Regex("255[67][0-9]{8}")) }
    }
}
