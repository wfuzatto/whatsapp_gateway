#!/usr/bin/env python3
"""Install the FCM service-account JSON in the hosting account via pinned SFTP."""
from __future__ import annotations

import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tempfile

HOST = "ftp.zapapi1.hospedagemdesites.ws"
USERNAME = "zapapi1"
PROJECT_ID = "zapapi-ffc8b"
REMOTE_PATH = "whatsapp_firebase_service_account.json"
HOST_KEY = (
    "ftp.zapapi1.hospedagemdesites.ws ssh-ed25519 "
    "AAAAC3NzaC1lZDI1NTE5AAAAIHxux8t181nlnmb7asFWOud1oIQewR6UaIp11GVqMNX/\n"
)


def fail(message: str) -> None:
    print(message, file=sys.stderr)
    raise SystemExit(1)


def main() -> None:
    raw = os.environ.get("FCM_SERVICE_ACCOUNT_JSON", "")
    password = os.environ.get("SFTP_PASSWORD", "")
    username = os.environ.get("SFTP_USERNAME", "")
    host = os.environ.get("SFTP_HOST", "")
    if not raw or not password:
        fail("FCM credential or SFTP password is missing")
    if host != HOST or username != USERNAME:
        fail("SFTP destination does not match the approved host/account")
    try:
        credential = json.loads(raw)
    except (TypeError, json.JSONDecodeError):
        fail("FCM service-account JSON is invalid")
    if (credential.get("type") != "service_account" or
            credential.get("project_id") != PROJECT_ID or
            credential.get("client_email") !=
            "zapapi-fcm-sender@zapapi-ffc8b.iam.gserviceaccount.com" or
            "PRIVATE KEY" not in credential.get("private_key", "")):
        fail("FCM service-account JSON does not match the approved project/account")

    sshpass = os.environ.get("SSHPASS_EXECUTABLE", "sshpass")
    sftp = os.environ.get("SFTP_EXECUTABLE", "sftp")
    with tempfile.TemporaryDirectory(prefix="zapapi-fcm-") as temp_dir:
        folder = pathlib.Path(temp_dir)
        known_hosts = folder / "known_hosts"
        probe_file = folder / "probe.sftp"
        batch_file = folder / "upload.sftp"
        verify_file = folder / "verify.json"
        known_hosts.write_text(HOST_KEY, encoding="ascii")
        probe_file.write_text("\n".join([
            "pwd", "ls -ld public_html", "ls -1", "quit",
        ]) + "\n", encoding="utf-8")
        batch_file.write_text(
            "\n".join([
                f"put {folder / 'service-account.json'} whatsapp_firebase_service_account.json.uploading",
                "chmod 600 whatsapp_firebase_service_account.json.uploading",
                f"rename whatsapp_firebase_service_account.json.uploading {REMOTE_PATH}",
                f"get {REMOTE_PATH} {verify_file}",
                "quit",
            ]) + "\n",
            encoding="utf-8",
        )
        credential_path = folder / "service-account.json"
        credential_path.write_text(raw, encoding="utf-8")
        try:
            os.chmod(credential_path, 0o600)
        except OSError:
            pass

        env = os.environ.copy()
        env.pop("FCM_SERVICE_ACCOUNT_JSON", None)
        env.pop("SFTP_PASSWORD", None)
        env["SSHPASS"] = password
        command = [
            sshpass, "-e", sftp,
            "-b", str(batch_file),
            "-P", "22",
            "-oStrictHostKeyChecking=yes",
            f"-oUserKnownHostsFile={known_hosts}",
            "-oGlobalKnownHostsFile=/dev/null",
            "-oPubkeyAuthentication=no",
            "-oPreferredAuthentications=password",
            "-oNumberOfPasswordPrompts=1",
            "-oConnectTimeout=20",
            f"{username}@{host}",
        ]
        try:
            probe = subprocess.run(
                [*command[:3], "-b", str(probe_file), *command[5:]],
                env=env, capture_output=True, text=True, timeout=45,
            )
        except (OSError, subprocess.TimeoutExpired):
            fail("Pinned SFTP preflight could not be started or timed out")
        if probe.returncode != 0:
            diagnostic = (probe.stdout + "\n" + probe.stderr).lower()
            if "permission denied" in diagnostic or "authentication failed" in diagnostic:
                fail("SFTP login was rejected by the hosting server; no credential was uploaded")
            if "host key verification failed" in diagnostic:
                fail("SFTP host-key pin did not match; no credential was uploaded")
            if "subsystem request failed" in diagnostic or "sftp subsystem" in diagnostic:
                fail("The SSH service does not allow SFTP; no credential was uploaded")
            fail(f"SFTP preflight failed (exit {probe.returncode}); no credential was uploaded")
        probe_lines = [line.strip() for line in probe.stdout.splitlines()]
        working_dir = next((line.split(":", 1)[1].strip() for line in probe_lines
                            if line.startswith("Remote working directory:")), "")
        if not working_dir:
            fail("SFTP did not report the account root; no credential was uploaded")
        if "public_html" not in probe.stdout:
            fail("SFTP could not confirm the public_html directory; no credential was uploaded")
        if "public_html" in pathlib.PurePosixPath(working_dir).parts:
            fail("SFTP starts inside public_html; no credential was uploaded")
        if REMOTE_PATH in probe.stdout:
            fail("FCM credential file already exists; existing file was left untouched")
        try:
            result = subprocess.run(command, env=env, capture_output=True, text=True, timeout=90)
        except (OSError, subprocess.TimeoutExpired):
            fail("Pinned SFTP upload could not be started or timed out")
        if result.returncode != 0:
            cleanup_file = folder / "cleanup.sftp"
            cleanup_file.write_text(
                "rm whatsapp_firebase_service_account.json.uploading\nquit\n",
                encoding="utf-8",
            )
            cleanup_command = [*command[:3], "-b", str(cleanup_file), *command[5:]]
            try:
                subprocess.run(cleanup_command, env=env, capture_output=True, text=True, timeout=30)
            except (OSError, subprocess.TimeoutExpired):
                pass
            # Do not echo remote output; it may include user or path details.
            fail(f"Pinned SFTP upload failed (exit {result.returncode})")
        try:
            uploaded = verify_file.read_bytes()
            verified = json.loads(uploaded)
        except (OSError, json.JSONDecodeError):
            fail("SFTP upload verification failed")
        if (hashlib.sha256(uploaded).digest() != hashlib.sha256(raw.encode("utf-8")).digest() or
                verified.get("project_id") != PROJECT_ID or
                verified.get("client_email") != credential["client_email"]):
            fail("SFTP upload verification did not match the supplied credential")
        print("FCM service account installed and verified at the account root outside public_html (mode 600).")


if __name__ == "__main__":
    main()

