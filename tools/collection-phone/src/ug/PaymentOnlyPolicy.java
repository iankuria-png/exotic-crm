package me.capcom.smsgateway.modules.receiver;

import java.util.regex.Pattern;

/**
 * Uganda MTN MoMoPay Till build: reject nonfinancial content before it reaches the bridge's logs, inbox or outbox.
 * Only Till receipts (both paired views) and completed reversals leave the phone; person-to-person transfers into
 * the SIM's personal wallet stay on the device (Ian, 25 Nov 2026: Till receipts only).
 */
public final class PaymentOnlyPolicy {
    public static final String VERSION = "exotic-ug-v1";
    public enum Decision { REJECT, FINANCIAL, DRIFT }
    private static final String SENDER = "MTNMobMoney";
    private static final Pattern SECRET = Pattern.compile(
        "(?i)\\b(?:OTP|PIN|password|passcode|verification|authentication|one[ -]time|secret code)\\b");
    private static final Pattern TRANSACTION = Pattern.compile("(?i)(?:Transaction (?:ID|Id)|\\bID):?\\s*\\d{9,13}\\b");
    private static final Pattern CREDIT = Pattern.compile("(?i)^You have received (?:UGX\\s*[0-9]|[0-9][0-9,]*(?:\\.[0-9]{1,2})? UGX)");
    private static final Pattern TILL = Pattern.compile("(?i)Message(?: from sender)?:\\s*Till\\b|Your new MoMoPay(?:Account)? balance");
    private static final Pattern PERSONAL = Pattern.compile("(?i)^You have received UGX\\s*[0-9][0-9,.]* from .+?\\bon \\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\..*?Reason:");
    private static final Pattern REVERSED = Pattern.compile("(?i)^The transaction from .+? \\(\\+?\\d{9,12}\\) with [0-9][0-9,.]* UGX has been reversed\\b");
    private static final Pattern REVERSAL = Pattern.compile("(?i)revers|withheld");
    private static final Pattern OUTGOING = Pattern.compile(
        "(?i)^(?:You have sent|You have bought|You have deposited|You have withdrawn|You have paid|Dial \\*|An adjustment has been made)|\\bfailed to send\\b|\\bhas deducted UGX\\b|^Y'ello, the transaction with amount");

    private PaymentOnlyPolicy() {}

    public static Decision classify(String sender, String body) {
        if (!SENDER.equals(sender) || body == null || body.length() > 16000 || SECRET.matcher(body).find()) {
            return Decision.REJECT;
        }
        if (OUTGOING.matcher(body).find() || PERSONAL.matcher(body).find()) return Decision.REJECT;
        // Completed reversals are retained even when the credit template changes.
        if (REVERSED.matcher(body).find()) return Decision.FINANCIAL;
        if (REVERSAL.matcher(body).find()) return Decision.DRIFT;
        if (CREDIT.matcher(body).find()) {
            // Unfamiliar credit layouts reach CRM quarantine; never silently drop a new template.
            return TILL.matcher(body).find() && TRANSACTION.matcher(body).find() ? Decision.FINANCIAL : Decision.DRIFT;
        }
        return Decision.REJECT;
    }
}
