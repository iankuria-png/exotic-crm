#!/usr/bin/env python3
"""Sign the prepared secure candidate with a private office key kept outside Git."""
import argparse
import hashlib
import json
import os
import secrets
import subprocess
from pathlib import Path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("prepared_root", type=Path)
    parser.add_argument("--sdk", type=Path, required=True)
    parser.add_argument("--java", type=Path, required=True)
    parser.add_argument("--private-key-directory", type=Path, required=True)
    parser.add_argument("--log", type=Path, required=True)
    args = parser.parse_args()
    root = args.prepared_root.resolve()
    metadata = json.loads((root.parent / "prepared-manifest.json").read_text())
    if metadata["upstream"]["package"] != "com.exotic.paymentcapture":
        raise SystemExit("Unexpected bridge package")
    private = args.private_key_directory.expanduser().resolve()
    repo = Path(__file__).resolve().parents[2]
    if private == repo or repo in private.parents:
        raise SystemExit("Signing keys must be outside the repository")
    private.mkdir(mode=0o700, parents=True, exist_ok=True)
    private.chmod(0o700)
    keyfile, passwordfile = private / "office-capture.jks", private / "signing-password"
    if keyfile.exists() != passwordfile.exists():
        raise SystemExit("Incomplete private signing material; restore it rather than replacing the key")
    password = passwordfile.read_text().strip() if passwordfile.exists() else secrets.token_hex(32)
    env = os.environ.copy()
    env.update({"JAVA_HOME": str(args.java), "ANDROID_HOME": str(args.sdk), "EXOTIC_PHONE_KEYSTORE": str(keyfile),
                "SIGNING_STORE_PASSWORD": password, "SIGNING_KEY_PASSWORD": password, "SIGNING_KEY_ALIAS": "office-capture"})
    if not keyfile.exists():
        passwordfile.write_text(password + "\n")
        passwordfile.chmod(0o600)
        subprocess.run([str(args.java / "bin/keytool"), "-genkeypair", "-keystore", str(keyfile), "-alias", "office-capture",
                        "-keyalg", "RSA", "-keysize", "3072", "-validity", "3650", "-dname", "CN=Exotic Office Payment Capture",
                        "-storepass:env", "SIGNING_STORE_PASSWORD", "-keypass:env", "SIGNING_KEY_PASSWORD"],
                       env=env, check=True, capture_output=True)
        keyfile.chmod(0o600)
    (root / "local.properties").write_text("sdk.dir=" + str(args.sdk).replace("\\", "\\\\") + "\n")
    args.log.parent.mkdir(parents=True, exist_ok=True)
    with args.log.open("w") as log:
        result = subprocess.run([str(root / "gradlew"), "--no-daemon", "--max-workers=2", ":app:assembleRelease"],
                                cwd=root, env=env, stdout=log, stderr=subprocess.STDOUT)
    if result.returncode:
        raise SystemExit(f"Build failed; inspect {args.log}")
    apk = root / "app/build/outputs/apk/release/app-release.apk"
    verify = subprocess.run([str(args.sdk / "build-tools/33.0.1/apksigner"), "verify", "--print-certs", str(apk)],
                            env=env, check=True, capture_output=True, text=True)
    cert = next(line.split(":", 1)[1].strip() for line in verify.stdout.splitlines() if "certificate SHA-256 digest:" in line)
    manifest = {"package": metadata["upstream"]["package"], "version": metadata["upstream"]["version_name"],
                "sha256": hashlib.sha256(apk.read_bytes()).hexdigest(), "signer_certificate_sha256": cert,
                "source_manifest_sha256": hashlib.sha256((root.parent / "prepared-manifest.json").read_bytes()).hexdigest(),
                "apk": str(apk), "live_accepted": False}
    (root.parent / "apk-manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    print(json.dumps(manifest, indent=2))


if __name__ == "__main__":
    main()
