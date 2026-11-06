package me.capcom.smsgateway.modules.receiver;

import java.util.regex.Pattern;

/** Reject nonfinancial content before it reaches the bridge's logs, inbox or outbox. */
public final class PaymentOnlyPolicy {
    public static final String VERSION = "exotic-tz-v1";
    public enum Decision { REJECT, FINANCIAL, DRIFT }
    private static final Pattern SECRET = Pattern.compile(
        "(?i)\\b(?:OTP|PIN|password|passcode|verification|authentication|nenosiri|nywila|one[ -]time)\\b|namba\\s+ya\\s+siri");
    private static final Pattern REFERENCE = Pattern.compile("(?i)\\b[A-Z0-9]{10,11}\\b");
    private static final Pattern PREFIX = Pattern.compile("(?i)^[A-Z0-9]{10,11}\\s+(?:confirmed|imethibitishwa)\\.");
    private static final Pattern RECEIVING = Pattern.compile("(?i)(?:you have received|umepokea|\\breceive\\s+Tsh|payment\\s+received)");
    private static final Pattern REVERSAL = Pattern.compile("(?i)(?:revers|rudish|rejesh)");
    private static final Pattern OUTGOING = Pattern.compile("(?i)\\b(?:sent|paid|umetuma|umelipa)\\b");
    private static final Pattern CURRENCY = Pattern.compile("(?i)\\b(?:Tshs?|TZS)\\s*[0-9]");

    private PaymentOnlyPolicy() {}

    public static Decision classify(String sender, String body) {
        if (!"M-PESA".equals(sender) || body == null || body.length() > 16000 || SECRET.matcher(body).find()) {
            return Decision.REJECT;
        }
        // Reversals and holds are retained even when the credit template changes.
        if (REVERSAL.matcher(body).find()) {
            return REFERENCE.matcher(body).find() ? Decision.FINANCIAL : Decision.DRIFT;
        }
        if (OUTGOING.matcher(body).find()) return Decision.REJECT;
        if (RECEIVING.matcher(body).find()) {
            // Unfamiliar financial layouts reach CRM quarantine; do not silently drop a new template.
            return PREFIX.matcher(body).find() || (REFERENCE.matcher(body).find() && CURRENCY.matcher(body).find())
                ? Decision.FINANCIAL : Decision.DRIFT;
        }
        // A receipt-like provider message without an understood direction needs format review.
        if (PREFIX.matcher(body).find() && CURRENCY.matcher(body).find()) return Decision.DRIFT;
        return Decision.REJECT;
    }
}
