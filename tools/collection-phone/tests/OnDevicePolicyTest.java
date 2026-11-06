package com.exotic.paymentcapture.tests;

import android.app.Activity;
import android.app.Instrumentation;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.os.Bundle;
import java.lang.reflect.Method;
import java.util.Arrays;

/** Exercises the installed release APK's filter without reading or injecting any SMS. */
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
                "ABCDEF12345 Confirmed. You have received Tsh35,000.00 from FIXTURE on 6/11/26 at 12:00.",
                "ALLLETTERS Confirmed. You have received a payment of Tsh35000 from FIXTURE on 6/11/26 at 12:00.",
                "DATEFIRST1 Confirmed. On 6/11/26 at 12:00 Receive Tsh35000 from FIXTURE.",
                "SWAHILI123 Imethibitishwa. Umepokea Tsh35000 kutoka FIXTURE tarehe 6/11/26 saa 12:00.",
                "Transaction ABCDEF12345 has been successfully reversed.",
                "Muamala wa awali: ABCDEF12345 kimerudishwa kikamilifu.",
                "NEWLAYOUT1 Payment received TZS 35000 with an unfamiliar date layout."
            };
            for (String text : financial) {
                expect("M-PESA", text, "FINANCIAL");
                expect("PERSONAL", text, "REJECT");
                expect("M-PESA ", text, "REJECT");
                expect("M-PESA", text + " OTP 123456", "REJECT");
            }
            for (String text : new String[]{"OTP 123456", "Your PIN is 1234", "Your verification code is 123456", "Hello, call me", "Your balance is Tsh50000",
                "TOPUP12345 Confirmed. You have sent Tsh35000 to FIXTURE", "TOPUP12345 Confirmed. Umelipa Tsh35000", "Umepokea namba ya siri 1234",
                "ABCDEF12345 Confirmed. You have received Tsh35000. Password 1234", "ABCDEF12345 Confirmed. You have received Tsh35000. one-time code"}) expect("M-PESA", text, "REJECT");
            expect("M-PESA", "You have received a payment, changed format without reference", "DRIFT");
            expect("M-PESA", "A payment has been reversed without a reference", "DRIFT");
            expect("M-PESA", "ABCDEF12345 Confirmed. A changed financial direction Tsh35000", "DRIFT");
            expect("M-PESA", null, "REJECT");
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
