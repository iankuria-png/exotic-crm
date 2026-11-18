package com.exotic.paymentcapture.tests;

import android.app.Activity;
import android.app.Instrumentation;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.os.Bundle;
import java.lang.reflect.Method;
import java.util.Arrays;

/** Ghana build: exercises the installed release APK's filter without reading or injecting any SMS. */
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
                "Payment received for GHS 380.00 from FIXTURE PAYER  Current Balance: GHS 2353.86 . Available Balance: GHS 2353.86. Reference: 1. Transaction ID: 94000000001. TRANSACTION FEE: 0.00",
                "Payment received for GHS 230.00 from FIXTURE  Current Balance: GHS 20.00 . Available Balance: GHS 20.00. Reference: FIXTURE ,233200000001,1 from VODAFONE. Transaction ID: 94000000002. TRANSACTION FEE: 0.00",
                "Cash In received for GHS 380.00 from FIXTURE VENTURES. Current Balance GHS 1.00 Available Balance GHS 1.00. Transaction ID: 94000000003. Fee charged: GHS 0.",
                "Y'ello Valued customer, the transfer of GHS 380.00 from 233590000001 has been withheld and is pending reversal within 15 days. Thank you.",
                "Y'ello Valued Customer, the transfer of GHS 230.00 from 233590000001 has been reversed on 2026-11-15 12:52:23, with transaction ID:93000000001.",
                "The reversal of the transfer 93000000002 has been revoked. Note: si"
            };
            for (String text : financial) {
                expect("MobileMoney", text, "FINANCIAL");
                expect("MTN", text, "REJECT");
                expect("MobileMoney ", text, "REJECT");
                expect("MobileMoney", text + " Your OTP is 123456", "REJECT");
            }
            for (String text : new String[]{"Your MoMo PIN reset code is 1234", "Your verification code is 123456", "Go cashless with MoMoPay. Pay any merchant using ID or QR Code",
                "Payment made for GHS 15000.00 to FIXTURE Current Balance: GHS 1.00 . Available Balance: GHS 1.00. Reference: x. Transaction ID: 92000000001.",
                "Your payment of GHS 100.00 to MTN AIRTIME has been completed at 2026-11-17 10:05:34. Financial Transaction Id: 94000000004",
                "The reversal request has been rejected for the transaction to FIXTURE (233550000001) with 255.00 GHS.",
                "Need cash now? Get a MoMo Loan in minutes", "Yello Valued Customer, thank you for reporting this issue."}) expect("MobileMoney", text, "REJECT");
            expect("MobileMoney", "Payment received for GHS 380.00 in a changed layout without an ID", "DRIFT");
            expect("MobileMoney", "Your transfer has been reversed in a new wording", "DRIFT");
            expect("MobileMoney", "You have received GHS 380.00 from FIXTURE. Transaction ID: 94000000005", "DRIFT");
            expect("MobileMoney", null, "REJECT");
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
