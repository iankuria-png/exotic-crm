package me.capcom.smsgateway.modules.receiver;

import java.util.regex.Pattern;

/** Ghana MTN MoMo build: reject nonfinancial content before it reaches the bridge's logs, inbox or outbox. */
public final class PaymentOnlyPolicy {
    public static final String VERSION = "exotic-gh-v1";
    public enum Decision { REJECT, FINANCIAL, DRIFT }
    private static final String SENDER = "MobileMoney";
    private static final Pattern SECRET = Pattern.compile(
        "(?i)\\b(?:OTP|PIN|password|passcode|verification|authentication|one[ -]time|secret code)\\b");
    private static final Pattern TRANSACTION = Pattern.compile("(?i)Transaction ID:?\\s*\\d{9,13}\\b");
    private static final Pattern CREDIT = Pattern.compile("(?i)^(?:Payment|Cash In) received for GHS\\s*[0-9]");
    private static final Pattern HOLD = Pattern.compile("(?i)transfer of GHS\\s*[0-9][0-9,.]* from \\d{9,13} has been (?:withheld|reversed)");
    private static final Pattern REVOKED = Pattern.compile("(?i)^The reversal of the transfer \\d{9,13} has been revoked");
    private static final Pattern REVERSAL = Pattern.compile("(?i)revers|withheld");
    private static final Pattern OUTGOING = Pattern.compile("(?i)^(?:Payment made for|Your payment of|Cash Out|Transfer of GHS|You have sent)|reversal request has been rejected for the transaction to");
    private static final Pattern RECEIVING = Pattern.compile("(?i)\\breceived\\b.*\\bGHS\\b|\\bGHS\\b.*\\breceived\\b");

    private PaymentOnlyPolicy() {}

    public static Decision classify(String sender, String body) {
        if (!SENDER.equals(sender) || body == null || body.length() > 16000 || SECRET.matcher(body).find()) {
            return Decision.REJECT;
        }
        if (OUTGOING.matcher(body).find()) return Decision.REJECT;
        // Holds, reversals and revocations are retained even when the credit template changes.
        if (HOLD.matcher(body).find() || REVOKED.matcher(body).find()) return Decision.FINANCIAL;
        if (REVERSAL.matcher(body).find()) return Decision.DRIFT;
        if (CREDIT.matcher(body).find()) {
            // Unfamiliar credit layouts reach CRM quarantine; never silently drop a new template.
            return TRANSACTION.matcher(body).find() ? Decision.FINANCIAL : Decision.DRIFT;
        }
        if (RECEIVING.matcher(body).find() && TRANSACTION.matcher(body).find()) return Decision.DRIFT;
        return Decision.REJECT;
    }
}
