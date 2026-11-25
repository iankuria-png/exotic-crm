package com.exotic.paymentcapture.tests;

import android.app.Activity;
import android.app.Instrumentation;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.os.Bundle;
import java.lang.reflect.Method;
import java.util.Arrays;

/** Uganda build: exercises the installed release APK's filter without reading or injecting any SMS. */
public final class OnDevicePolicyTest extends Instrumentation {
    private int checks;
    private Method classify;

    @Override public void onCreate(Bundle arguments) { super.onCreate(arguments); start(); }
    private void expect(String sender, String text, String expected) throws Exception {
        String actual = classify.invoke(null, sender, text).toString();
        if (!actual.equals(expected)) throw new AssertionError("Synthetic installed-filter case " + checks + " failed");
        checks++;
    }
    @Override public void onStart() {
        Bundle result = new Bundle();
        try {
            Class<?> policy = getTargetContext().getClassLoader().loadClass("me.capcom.smsgateway.modules.receiver.PaymentOnlyPolicy");
            classify = policy.getMethod("classify", String.class, String.class);
            String[] financial = {
                "You have received UGX 50000 from FIXTURE PAYER at 2026-11-24 17:04:16.  Fee: 500. Message: Till:632555. New balance is: UGX 900680. Transaction ID: 45000000001. Download MoMo App http://bit.ly/x to get 500MBs.",
                "You have received UGX 50,000 from (FIXTURE PAYER) 256780000001. Your new MoMoPay balance: 900680.46. Transaction ID: 45000000001. Download MoMo App http://bit.ly/x to get 500MBs.",
                "You have received UGX 50,000 from (FIXTURE PAYER) 256780000001. Your new MoMoPay balance: UGX 900680.46. Transaction ID: 45000000002.",
                "You have received 60000 UGX from FIXTURE PAYER (256770000002) on your mobile money account at 2025-08-05 20:45:03. Message from sender: Till:632555. Your new balance: 1410121 UGX. Coupon amount was . Fee was 900 UGX. Financial Transaction Id: 34436500280. External Transaction Id: -.",
                "You have received UGX 60,000 from (FIXTURE PAYER) 256780000003. Your new MoMoPayAccount balance: 1410120.82. ID: 34436500281.",
                "The transaction from FIXTURE (256770000004) with 60000 UGX has been reversed at 2026-03-18 11:03:39. Your new balance is 1903665 UGX. Financial Transaction Id: 39000000001. External Transaction Id: -. ."
            };
            for (String text : financial) {
                expect("MTNMobMoney", text, "FINANCIAL");
                expect("MTNMoMo", text, "REJECT");
                expect("MTNMobMoney ", text, "REJECT");
                expect("MTNMobMoney", text + " Your OTP is 123456", "REJECT");
            }
            for (String text : new String[]{
                "You have received UGX 90000 from FIXTURE, 256790000006 on 2026-11-23 11:52:26. fee:0. Reason: Apps. New balance: UGX 387200. ID: 45000000003.",
                "You have received UGX 90000 from FIXTURE, 256790000006 on 2026-11-23 11:52:26. Reason: 1. New balance: UGX 387200. ID: 45000000004.",
                "You have received UGX 40000 from Airtel Money on 2026-11-20 10:00:00. fee:0. Reason: FIXTURE , 256700000001. New balance: UGX 1. ID: 45000000005.",
                "You have sent UGX 10000 to FIXTURE, 256770000007 on 2026-11-20 10:00:00. fee: 500. Reason: family. New balance: 1. ID: 45000000006.",
                "You have bought UGX 1000 airtime. New MoMo balance: UGX 5. Do NOT share your Mobile Money PIN.",
                "You have deposited UGX 5000 from FIXTURE on 2026-11-20 10:00:00. New balance: UGX 10. ID: 45000000007.",
                "FIXTURE failed to send 5000000 UGX from your account (FRI:1/MM). Maximum amount to send per transaction is 5000000 UGX.",
                "Y'ello. FIXTURE LIMITED . has deducted UGX 1000 at a fee of UGX 0 Transaction ID: 45000000008.",
                "Dial *165# and select My Approvals to allow payment of UGX 1000 to FIXTURE LIMITED",
                "FIXTURE, 256760000005 has initiated a reversal of 50000 wrongly sent to you. Please approve the reversal by dialling *165*8*3# and enter your PIN.",
                "Your MoMo PIN reset code is 1234",
                "Y'ello! Money sent successfully. You can also pay Bills fast and securely with MoMo App."}) expect("MTNMobMoney", text, "REJECT");
            expect("MTNMobMoney", "You have received UGX 50000 in a changed layout without an ID", "DRIFT");
            expect("MTNMobMoney", "Your transfer has been reversed in a new wording", "DRIFT");
            expect("MTNMobMoney", "You have received UGX 50000 from FIXTURE. Transaction ID: 45000000009", "DRIFT");
            expect("MTNMobMoney", null, "REJECT");
            expect("M-PESA", financial[0], "REJECT");
            PackageInfo info = getTargetContext().getPackageManager().getPackageInfo(getTargetContext().getPackageName(), PackageManager.GET_PERMISSIONS);
            if (Arrays.asList(info.requestedPermissions).contains("android.permission.SEND_SMS")) throw new AssertionError("Release requested outgoing SMS permission");
            if ((info.applicationInfo.flags & ApplicationInfo.FLAG_DEBUGGABLE) != 0) throw new AssertionError("Candidate must be a release build");
            result.putString("result", checks + " installed release filter cases passed; outgoing SMS permission absent; release is not debuggable");
            finish(Activity.RESULT_OK, result);
        } catch (Throwable error) {
            result.putString("result", "Installed bridge acceptance failed: " + error.getClass().getSimpleName());
            finish(Activity.RESULT_CANCELED, result);
        }
    }
}
