import me.capcom.smsgateway.modules.receiver.PaymentOnlyPolicy;

public final class PaymentOnlyPolicyTest {
    private static int checks;
    private static void expect(String sender, String message, PaymentOnlyPolicy.Decision decision) {
        if (PaymentOnlyPolicy.classify(sender, message) != decision) throw new AssertionError("Synthetic filter case " + checks + " failed");
        checks++;
    }
    public static void main(String[] args) {
        String[] financial = {
            "ABCDEF12345 Confirmed. You have received Tsh35,000.00 from 255700000001 - FIXTURE on 6/11/26 at 12:00.",
            "ALLLETTERS Confirmed. You have received a payment of Tsh35000 from FIXTURE on 6/11/26 at 12:00.",
            "DATEFIRST1 Confirmed. On 6/11/26 at 12:00 Receive Tsh35000 from FIXTURE.",
            "SWAHILI123 Imethibitishwa. Umepokea Tsh35000 kutoka FIXTURE tarehe 6/11/26 saa 12:00.",
            "Transaction ABCDEF12345 has been successfully reversed.",
            "Muamala wa awali: ABCDEF12345 kimerudishwa kikamilifu.",
            "NEWLAYOUT1 Payment received TZS 35000 from FIXTURE at an unfamiliar date layout."
        };
        for (String body : financial) {
            expect("M-PESA", body, PaymentOnlyPolicy.Decision.FINANCIAL);
            expect("PERSONAL", body, PaymentOnlyPolicy.Decision.REJECT);
            expect("M-PESA ", body, PaymentOnlyPolicy.Decision.REJECT);
            expect("M-PESA", body + " OTP 123456", PaymentOnlyPolicy.Decision.REJECT);
        }
        String[] personal = {"OTP 123456", "Your PIN is 1234", "Your verification code is 123456", "Hello, please call me", "Your balance is Tsh50000",
            "TOPUP12345 Confirmed. You have sent Tsh35000 to FIXTURE", "TOPUP12345 Confirmed. Umelipa Tsh35000", "Umepokea namba ya siri 1234",
            "ABCDEF12345 Confirmed. You have received Tsh35000. Password 1234", "ABCDEF12345 Confirmed. You have received Tsh35000. one-time code"};
        for (String body : personal) expect("M-PESA", body, PaymentOnlyPolicy.Decision.REJECT);
        expect("M-PESA", "You have received a payment, changed format without reference", PaymentOnlyPolicy.Decision.DRIFT);
        expect("M-PESA", "A payment has been reversed without a usable reference", PaymentOnlyPolicy.Decision.DRIFT);
        expect("M-PESA", "ABCDEF12345 Confirmed. A changed financial direction Tsh35000", PaymentOnlyPolicy.Decision.DRIFT);
        expect("M-PESA", null, PaymentOnlyPolicy.Decision.REJECT);
        System.out.println(checks + " synthetic filter checks passed; no private SMS used.");
    }
}
