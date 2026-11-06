#!/usr/bin/env python3
"""Run synthetic filter checks against the installed signed APK; never reads SMS."""
import argparse
import hashlib
import json
import os
import subprocess
import tempfile
import zipfile
from pathlib import Path


def run(command, **kwargs):
    return subprocess.run([str(value) for value in command], check=True, capture_output=True, text=True, **kwargs)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("apk_manifest", type=Path)
    parser.add_argument("--sdk", type=Path, required=True)
    parser.add_argument("--java", type=Path, required=True)
    parser.add_argument("--private-key-directory", type=Path, required=True)
    parser.add_argument("--serial", required=True)
    parser.add_argument("--install", action="store_true")
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    metadata = json.loads(args.apk_manifest.read_text())
    apk = Path(metadata["apk"])
    if metadata["package"] != "com.exotic.paymentcapture" or hashlib.sha256(apk.read_bytes()).hexdigest() != metadata["sha256"]:
        raise SystemExit("APK identity/digest mismatch")
    adb = [args.sdk / "platform-tools/adb", "-s", args.serial]
    if run([*adb, "get-state"]).stdout.strip() != "device":
        raise SystemExit("Authorized ADB connection required")
    tools = args.sdk / "build-tools/33.0.1"
    jar = args.sdk / "platforms/android-34/android.jar"
    private = args.private_key_directory.resolve()
    env = os.environ.copy()
    env["JAVA_HOME"] = str(args.java)
    env["EXOTIC_PHONE_SIGN_PASS"] = (private / "signing-password").read_text().strip()
    with tempfile.TemporaryDirectory(prefix="exotic-phone-instrumentation-") as directory:
        folder = Path(directory)
        classes, dex = folder / "classes", folder / "dex"
        classes.mkdir(); dex.mkdir()
        manifest = folder / "AndroidManifest.xml"
        manifest.write_text('''<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="com.exotic.paymentcapture.tests">
<uses-sdk android:minSdkVersion="21" android:targetSdkVersion="33" />
<application android:label="Exotic synthetic capture tests" android:testOnly="true" android:allowBackup="false" />
<instrumentation android:name="com.exotic.paymentcapture.tests.OnDevicePolicyTest" android:targetPackage="com.exotic.paymentcapture" android:functionalTest="true" />
</manifest>''')
        source = Path(__file__).parent / "tests/OnDevicePolicyTest.java"
        run([args.java / "bin/javac", "--release", "8", "-cp", jar, "-d", classes, source])
        run([tools / "d8", "--lib", jar, "--min-api", "21", "--output", dex, *classes.rglob("*.class")], env=env)
        unsigned, signed = folder / "unsigned.apk", folder / "signed.apk"
        run([tools / "aapt2", "link", "-I", jar, "--manifest", manifest, "-o", unsigned])
        with zipfile.ZipFile(unsigned, "a") as archive:
            archive.write(dex / "classes.dex", "classes.dex")
        run([tools / "apksigner", "sign", "--ks", private / "office-capture.jks", "--ks-key-alias", "office-capture",
             "--ks-pass", "env:EXOTIC_PHONE_SIGN_PASS", "--key-pass", "env:EXOTIC_PHONE_SIGN_PASS", "--out", signed, unsigned], env=env)
        if args.install:
            run([*adb, "install", "-r", apk], timeout=90)
        run([*adb, "install", "-t", "-r", signed], timeout=90)
        try:
            result = run([*adb, "shell", "am", "instrument", "-w", "com.exotic.paymentcapture.tests/.OnDevicePolicyTest"], timeout=60).stdout
            passed = "42 installed release filter cases passed" in result and "INSTRUMENTATION_CODE: -1" in result
            output = {"passed": passed, "apk_sha256": metadata["sha256"], "synthetic_filter_cases": 42,
                      "sms_read_or_injected": False, "result": result.strip()}
            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(json.dumps(output, indent=2) + "\n")
            print(json.dumps(output, indent=2))
            if not passed:
                raise SystemExit("Installed APK synthetic checks failed")
        finally:
            run([*adb, "uninstall", "com.exotic.paymentcapture.tests"], timeout=30)
            run([*adb, "shell", "am", "force-stop", "com.exotic.paymentcapture"])


if __name__ == "__main__":
    main()
