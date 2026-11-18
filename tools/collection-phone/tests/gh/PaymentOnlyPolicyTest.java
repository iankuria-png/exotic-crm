import me.capcom.smsgateway.modules.receiver.PaymentOnlyPolicy;

/** Synthetic Ghana MTN MoMo cases only; no private SMS. */
public final class PaymentOnlyPolicyTest {
    private static int checks;
    private static void expect(String sender, String message, PaymentOnlyPolicy.Decision decision) {
        if (PaymentOnlyPolicy.classify(sender, message) != decision) throw new AssertionError("Synthetic Ghana filter case " + checks + " failed");
        checks++;
    }
    public static void main(String[] args) {
        String[] financial = {
            "Payment received for GHS 380.00 from FIXTURE PAYER  Current Balance: GHS 2353.86 . Available Balance: GHS 2353.86. Reference: 1. Transaction ID: 94000000001. TRANSACTION FEE: 0.00",
            "Payment received for GHS 230.00 from FIXTURE  Current Balance: GHS 20.00 . Available Balance: GHS 20.00. Reference: FIXTURE ,233200000001,1 from VODAFONE. Transaction ID: 94000000002. TRANSACTION FEE: 0.00",
            "Cash In received for GHS 380.00 from FIXTURE VENTURES. Current Balance GHS 1.00 Available Balance GHS 1.00. Transaction ID: 94000000003. Fee charged: GHS 0.",
            "Y'ello Valued customer, the transfer of GHS 380.00 from 233590000001 has been withheld and is pending reversal within 15 days. Thank you.",
            "Y'ello Valued Customer, the transfer of GHS 230.00 from 233590000001 has been reversed on 2026-11-15 12:52:23, with transaction ID:93000000001.",
            "The reversal of the transfer 93000000002 has been revoked. Note: si",
        };
        for (String body : financial) {
            expect("MobileMoney", body, PaymentOnlyPolicy.Decision.FINANCIAL);
            expect("MTN", body, PaymentOnlyPolicy.Decision.REJECT);
            expect("MobileMoney ", body, PaymentOnlyPolicy.Decision.REJECT);
            expect("MobileMoney", body + " Your OTP is 123456", PaymentOnlyPolicy.Decision.REJECT);
        }
        String[] personal = {"Your MoMo PIN reset code is 1234", "Your verification code is 123456", "Go cashless with MoMoPay. Pay any merchant using ID or QR Code",
            "Payment made for GHS 15000.00 to FIXTURE Current Balance: GHS 1.00 . Available Balance: GHS 1.00. Reference: x. Transaction ID: 92000000001.",
            "Your payment of GHS 100.00 to MTN AIRTIME has been completed at 2026-11-17 10:05:34. Financial Transaction Id: 94000000004",
            "The reversal request has been rejected for the transaction to FIXTURE (233550000001) with 255.00 GHS.",
            "Need cash now? Get a MoMo Loan in minutes", "Yello Valued Customer, thank you for reporting this issue."};
        for (String body : personal) expect("MobileMoney", body, PaymentOnlyPolicy.Decision.REJECT);
        expect("MobileMoney", "Payment received for GHS 380.00 in a changed layout without an ID", PaymentOnlyPolicy.Decision.DRIFT);
        expect("MobileMoney", "Your transfer has been reversed in a new wording", PaymentOnlyPolicy.Decision.DRIFT);
        expect("MobileMoney", "You have received GHS 380.00 from FIXTURE. Transaction ID: 94000000005", PaymentOnlyPolicy.Decision.DRIFT);
        expect("MobileMoney", null, PaymentOnlyPolicy.Decision.REJECT);
        expect("M-PESA", financial[0], PaymentOnlyPolicy.Decision.REJECT);
        System.out.println(checks + " synthetic Ghana filter checks passed; no private SMS used.");
    }
}
