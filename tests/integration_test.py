#!/usr/bin/env python3
"""Black-box integration checks for the SQLite RustDesk API.

Only Python's standard library is used.  The test creates a disposable legacy
database, starts the front controller on a random loopback port, exercises the
RustDesk contract and the web-admin contract, then removes all temporary data.
"""

from __future__ import annotations

import argparse
import hashlib
import http.cookiejar
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid
import socket
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SQLITE_DIR = ROOT / "sqlite"
DEFAULT_RUNTIME = "/tmp/rustdesk-local-runtime/frankenphp"
DEVICE_AUTH_SEED_HEX = "11" * 32


def php_sodium(expression: str, env: dict[str, str] | None = None) -> str:
    runtime = Path(os.environ.get("FRANKENPHP", DEFAULT_RUNTIME))
    command = [str(runtime), "php-cli", "-r", expression] if runtime.name == "frankenphp" else [str(runtime), "-r", expression]
    return subprocess.check_output(command, cwd=ROOT, env={**os.environ, **(env or {})}, text=True).strip()


def device_public_key(seed_hex: str = DEVICE_AUTH_SEED_HEX) -> str:
    return php_sodium(
        '$kp=sodium_crypto_sign_seed_keypair(hex2bin(getenv("SEED")));'
        'echo base64_encode(sodium_crypto_sign_publickey($kp));',
        {"SEED": seed_hex},
    )


def device_auth_headers(method: str, canonical_path: str, client_id: str, client_uuid: str, payload=None, command_id: str = "", nonce: str | None = None, timestamp: int | None = None, seed_hex: str = DEVICE_AUTH_SEED_HEX) -> dict[str, str]:
    body = b"" if payload is None else json.dumps(payload).encode()
    timestamp = int(time.time()) if timestamp is None else timestamp
    nonce = nonce or uuid.uuid4().hex
    public_key = device_public_key(seed_hex)
    canonical = (
        "rustdesk-update-auth-v1\n"
        f"method={method.upper()}\npath={canonical_path}\nclient_id={client_id}\nclient_uuid={client_uuid}\n"
        f"timestamp={timestamp}\nnonce={nonce}\ncommand_id={command_id}\nbody_sha256={hashlib.sha256(body).hexdigest()}\n"
    )
    signature = php_sodium(
        '$kp=sodium_crypto_sign_seed_keypair(hex2bin(getenv("SEED")));'
        'echo base64_encode(sodium_crypto_sign_detached(getenv("MESSAGE"),sodium_crypto_sign_secretkey($kp)));',
        {"SEED": seed_hex, "MESSAGE": canonical},
    )
    return {
        "X-RustDesk-Device-ID": client_id,
        "X-RustDesk-Device-Public-Key": public_key,
        "X-RustDesk-Device-Timestamp": str(timestamp),
        "X-RustDesk-Device-Nonce": nonce,
        "X-RustDesk-Device-Signature": signature,
    }


def legacy_password(password: str) -> str:
    return hashlib.md5((password + "rustdesk").encode()).hexdigest()


def create_fixture(path: Path) -> None:
    """Create the pre-migration four-table shape with sentinel records."""
    db = sqlite3.connect(path)
    db.executescript(
        """
        CREATE TABLE rustdesk_peers (
          deviceid INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER NOT NULL DEFAULT 0,
          id TEXT NOT NULL, username TEXT, hostname TEXT, alias TEXT,
          platform TEXT, tags TEXT, hash TEXT
        );
        CREATE TABLE rustdesk_tags (
          id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER NOT NULL, tag TEXT NOT NULL
        );
        CREATE TABLE rustdesk_token (
          access_token TEXT NOT NULL, username TEXT NOT NULL, uid INTEGER NOT NULL DEFAULT 0,
          id TEXT NOT NULL, uuid TEXT, login_time INTEGER NOT NULL DEFAULT 0,
          expire_time INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE rustdesk_users (
          id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password TEXT NOT NULL,
          create_time INTEGER NOT NULL DEFAULT 0, delete_time INTEGER NOT NULL DEFAULT 0
        );
        """,
    )
    db.execute(
        "INSERT INTO rustdesk_users(id, username, password, create_time, delete_time) VALUES (1, ?, ?, 1700000000, 0), (2, ?, ?, 1700000001, 0)",
        ("admin", legacy_password("old-admin-password"), "legacy", legacy_password("legacy123")),
    )
    db.execute("INSERT INTO rustdesk_peers(deviceid,uid,id,username,hostname,alias,platform,tags,hash) VALUES (11,2,'legacy-id','alice','old-host','Old alias','windows','prod,blue','legacy-hash')")
    db.execute("INSERT INTO rustdesk_tags(id,uid,tag) VALUES (7,2,'prod'),(8,2,'blue')")
    db.execute("INSERT INTO rustdesk_token(access_token,username,uid,id,uuid,login_time,expire_time) VALUES (?, 'legacy',2,'legacy-id','legacy-uuid',1700000002,0)", ("a" * 64,))
    db.execute("INSERT INTO rustdesk_token(access_token,username,uid,id,uuid,login_time,expire_time) VALUES (?, 'admin',1,'admin-id','admin-uuid',1700000003,0)", ("b" * 64,))
    db.commit()
    db.close()


class HttpClient:
    def __init__(self, base: str):
        self.base = base.rstrip("/")
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, method: str, path: str, payload=None, headers=None, expected=None):
        data = None
        hdr = {"Accept": "application/json"}
        if payload is not None:
            if isinstance(payload, (bytes, bytearray)):
                data = bytes(payload)
                hdr["Content-Type"] = "application/octet-stream"
            elif isinstance(payload, str):
                data = payload.encode()
                hdr["Content-Type"] = "text/plain"
            else:
                data = json.dumps(payload).encode()
                hdr["Content-Type"] = "application/json"
        if headers:
            hdr.update(headers)
        req = urllib.request.Request(self.base + path, data=data, headers=hdr, method=method)
        try:
            with self.opener.open(req, timeout=8) as resp:
                raw = resp.read()
                status = resp.status
                ctype = resp.headers.get("Content-Type", "")
        except urllib.error.HTTPError as exc:
            raw = exc.read()
            status = exc.code
            ctype = exc.headers.get("Content-Type", "")
            exc.close()
        text = raw.decode("utf-8", "replace")
        try:
            body = json.loads(text)
        except json.JSONDecodeError:
            body = text
        if expected is not None and status not in expected:
            raise AssertionError(f"{method} {path}: HTTP {status}, body={body!r}")
        return status, body, ctype

    def json(self, method, path, payload=None, headers=None, expected=(200,)):
        return self.request(method, path, payload, headers, expected)


class IntegrationTest(unittest.TestCase):
    def test_target_release_metadata_is_selected_per_client(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:8]
        db = sqlite3.connect(self.db)
        latest_build = db.execute(
            "SELECT COALESCE(MAX(build_seq), 0) FROM update_releases WHERE channel='stable'"
        ).fetchone()[0]
        db.close()
        build = max(int(time.time()), int(latest_build) + 2)
        version = f"1.5.{int(suffix, 16)}"
        source_commit = "c" * 40
        device_ids = (f"target-android-{suffix}", f"target-windows-{suffix}")
        def cleanup():
            db = sqlite3.connect(self.db)
            db.execute("DELETE FROM device_update_commands WHERE device_id IN (?,?)", device_ids)
            db.execute("DELETE FROM device_reports WHERE id IN (?,?)", device_ids)
            db.execute("DELETE FROM device_deployments WHERE id IN (?,?)", device_ids)
            db.execute("DELETE FROM update_releases WHERE version=? AND build_seq=? AND channel='stable'", (version, build + 1))
            db.commit()
            db.close()
        self.addCleanup(cleanup)
        manifest = {
            "schema": 2, "product": "rustdesk-yan", "edition": "multi",
            "targets": {
                "android-arm64-apk-standard": {"version": version, "build_number": str(build), "build_seq": build, "source_commit": source_commit, "source_tag": "android-only", "primary": "https://download.yan.life/rustdesk/stable/a.apk", "mirrors": [], "size": 12, "sha256": "a" * 64, "signature": __import__("base64").b64encode(b"t" * 64).decode(), "signature_key_id": "yan-release-2026"},
                "windows-x86_64-exe-standard": {"version": "1.5.0", "build_number": "20261001.7", "build_seq": 2026100107, "source_commit": "d" * 40, "source_tag": "desktop-old", "primary": "https://download.yan.life/rustdesk/stable/w.exe", "mirrors": [], "size": 12, "sha256": "b" * 64, "signature": __import__("base64").b64encode(b"t" * 64).decode(), "signature_key_id": "yan-release-2026"},
            },
        }
        payload = {"version": version, "build_seq": build + 1, "channel": "stable", "manifest": manifest}
        invalid_manifest = json.loads(json.dumps(manifest))
        invalid_manifest["targets"]["android-arm64-apk-standard"].pop("build_number")
        self.client.json(
            "POST",
            "/?s=/ops-x9/api/update/releases",
            {**payload, "manifest": invalid_manifest},
            {"X-CSRF-Token": csrf},
            expected=(422,),
        )
        android = {"client_id": device_ids[0], "client_uuid": f"target-android-u-{suffix}", "product": "rustdesk-yan", "edition": "standard", "platform": "android", "arch": "arm64", "package_kind": "apk", "version": version, "build_seq": build - 1, "channel": "stable"}
        windows = {"client_id": device_ids[1], "client_uuid": f"target-windows-u-{suffix}", "product": "rustdesk-yan", "edition": "standard", "platform": "windows", "arch": "x86_64", "package_kind": "exe", "version": "1.5.0", "build_seq": 2026100107, "channel": "stable"}
        for client in (android, windows):
            self.client.json("POST", "/?s=/api/heartbeat", {"id": client["client_id"], "uuid": client["client_uuid"], "conns": []})
            self.client.json("POST", "/?s=/api/sysinfo", {"id": client["client_id"], "uuid": client["client_uuid"], **client})
        self.client.json("POST", "/?s=/rd/update/v1/check", android)
        self.client.json("POST", "/?s=/rd/update/v1/check", windows)
        self.client.json("POST", "/?s=/ops-x9/api/update/releases", payload, {"X-CSRF-Token": csrf}, expected=(201,))
        _, android_check, _ = self.client.json("POST", "/?s=/rd/update/v1/check", android)
        _, windows_check, _ = self.client.json("POST", "/?s=/rd/update/v1/check", windows)
        self.assertEqual(android_check["target_build_seq"], build)
        self.assertTrue(android_check["update_available"])
        self.assertEqual(android_check["manifest"]["version"], version)
        self.assertEqual(android_check["manifest"]["build_seq"], build)
        self.assertEqual(android_check["manifest"]["source_commit"], source_commit)
        self.assertEqual(windows_check["target_build_seq"], 2026100107)
        self.assertFalse(windows_check["update_available"])
        db = sqlite3.connect(self.db)
        queued = db.execute("SELECT device_id,target_build_seq FROM device_update_commands WHERE device_id IN (?,?)", (android["client_id"], windows["client_id"])).fetchall()
        self.assertEqual(queued, [(android["client_id"], build)])
        db.close()
        endpoint = f"/?s=/ops-x9/api/update/commands/{windows['client_id']}"
        _, command, _ = self.client.json("POST", endpoint, {"uuid": windows["client_uuid"], "action": "install", "target_version": "1.5.0", "target_build_seq": 2026100107}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.assertEqual((command["target_version"], command["target_build_seq"]), ("1.5.0", 2026100107))
    def test_20_address_book_management_extensions(self):
        csrf = self.admin_csrf()
        _, options, _ = self.client.json("GET", "/?s=/ops-x9/api/address-book/assignment-options")
        user_id = options["users"][0]["id"]
        device_id = "extension-device-" + uuid.uuid4().hex[:8]
        self.client.json("POST", "/?s=/api/heartbeat", {"id": device_id, "uuid": device_id + "-uuid", "conns": []})
        preview = self.client.json("POST", "/?s=/ops-x9/api/devices/address-book", {"mode": "preview", "devices": [{"id": device_id, "uuid": device_id + "-uuid"}], "user_ids": [user_id]}, {"X-CSRF-Token": csrf})[1]
        self.assertEqual(preview["mode"], "preview")
        self.assertIn("snapshot", preview); self.assertIn("invalid", preview); self.assertIn("new", preview); self.assertIn("remove", preview)
        self.client.json("POST", "/?s=/ops-x9/api/devices/address-book", {"mode": "add", "apply": True, "devices": [{"id": device_id, "uuid": device_id + "-uuid"}], "user_ids": [user_id]}, {"X-CSRF-Token": csrf})
        _, owned, _ = self.client.json("GET", f"/?s=/ops-x9/api/address-book/users/{user_id}")
        self.assertIn(device_id, {row["id"] for row in owned["data"]})
        _, exported, ctype = self.client.request("GET", f"/?s=/ops-x9/api/address-book/export?user_id={user_id}&format=json")
        self.assertIn("application/json", ctype)
        self.assertIn(device_id, {peer["id"] for peer in exported["peers"]})
        _, csv_body, csv_type = self.client.request("GET", f"/?s=/ops-x9/api/address-book/export?user_id={user_id}&format=csv")
        self.assertIn("text/csv", csv_type)
        self.assertIn(device_id, csv_body)
        _, conflict, _ = self.client.json("DELETE", f"/?s=/ops-x9/api/devices/{device_id}?uuid={device_id}-uuid", {}, {"X-CSRF-Token": csrf}, expected=(409,))
        self.assertTrue(conflict["references"])
        self.client.request("GET", f"/?s=/ops-x9/api/address-book/export?user_id={user_id}&format=csv")
    runtime = None
    proc = None
    temp = None
    client = None

    @classmethod
    def setUpClass(cls):
        cls.temp = Path(tempfile.mkdtemp(prefix="rustdesk-api-test-"))
        cls.db = cls.temp / "legacy.db"
        create_fixture(cls.db)
        runtime = Path(cls.runtime or os.environ.get("FRANKENPHP", DEFAULT_RUNTIME))
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            port = probe.getsockname()[1]
        cls.url = f"http://127.0.0.1:{port}"
        env = os.environ.copy()
        env["RUSTDESK_DB"] = str(cls.db)
        env["RUSTDESK_INSTALL_CONFIG"] = str(cls.temp / "install.json")
        # Production defaults to a custom path; tests explicitly pin the public path for compatibility cases.
        env["RUSTDESK_ADMIN_PATH"] = "/ops-x9"
        env["RUSTDESK_TRUSTED_PROXY_IPS"] = "127.0.0.0/8"
        env["RUSTDESK_UPDATE_PUBLISH_TOKEN"] = "p" * 48
        env["RUSTDESK_UPDATE_DOWNLOAD_PREFIX"] = "https://download.yan.life/rustdesk/stable/"
        env["RUSTDESK_UPDATE_KEYS_JSON"] = json.dumps({
            "schema": 1,
            "keys": [{"id": "yan-release-2026", "algorithm": "ed25519", "public_key": "k" * 44}],
        })
        if os.environ.get("RUSTDESK_GEOIP_DATABASE"):
            env["RUSTDESK_GEOIP_DATABASE"] = os.environ["RUSTDESK_GEOIP_DATABASE"]
        if runtime.name == "frankenphp":
            cmd = [str(runtime), "php-server", "--listen", f"127.0.0.1:{port}", "--root", str(SQLITE_DIR)]
        else:
            cmd = [str(runtime), "-S", f"127.0.0.1:{port}", "-t", str(SQLITE_DIR), str(SQLITE_DIR / "index.php")]
        cls.proc = subprocess.Popen(cmd, cwd=ROOT, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        cls.client = HttpClient(cls.url)
        deadline = time.time() + 12
        while time.time() < deadline:
            try:
                status, _, _ = cls.client.request("GET", "/", expected=(200, 404))
                if status in (200, 404):
                    _, setup, _ = cls.client.json("GET", "/setup/api/status")
                    cls.client.json("POST", "/setup/api/install", {
                        "database": "sqlite", "admin_path": "/ops-x9", "username": "admin",
                        "password": "admin123", "password_confirm": "admin123",
                    }, {"X-CSRF-Token": setup["csrf"]})
                    db = sqlite3.connect(cls.db)
                    promoted = db.execute("SELECT id,is_admin,enabled,auth_version,password FROM rustdesk_users WHERE username='admin'").fetchone()
                    count = db.execute("SELECT COUNT(*) FROM rustdesk_users WHERE username='admin'").fetchone()[0]
                    old_tokens = db.execute("SELECT COUNT(*) FROM rustdesk_token WHERE uid=1").fetchone()[0]
                    db.close()
                    if promoted[:4] != (1, 1, 1, 1) or promoted[4] == legacy_password("old-admin-password") or count != 1 or old_tokens != 0:
                        raise RuntimeError(f"Web setup did not preserve and promote the legacy administrator: {promoted!r}, count={count}, tokens={old_tokens}")
                    return
            except (urllib.error.URLError, TimeoutError):
                time.sleep(0.1)
        cls.tearDownClass()
        raise RuntimeError("API server did not start")

    @classmethod
    def tearDownClass(cls):
        if cls.proc and cls.proc.poll() is None:
            cls.proc.terminate()
            try:
                cls.proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                cls.proc.kill()
                cls.proc.wait(timeout=3)
        if cls.proc:
            for stream in (cls.proc.stdout, cls.proc.stderr):
                if stream:
                    stream.close()
        if cls.temp:
            shutil.rmtree(cls.temp, ignore_errors=True)

    def test_00_failed_config_publish_rolls_back_legacy_admin_changes(self):
        db_path = self.temp / "rollback.db"
        create_fixture(db_path)
        config_target = self.temp / "config-is-a-directory"
        config_target.mkdir()
        script = self.temp / "rollback-test.php"
        script.write_text(
            "<?php declare(strict_types=1);"
            f"require {json.dumps(str(SQLITE_DIR / 'lib.php'))};"
            "$db=open_database($argv[1]);putenv('RUSTDESK_INSTALL_CONFIG='.$argv[2]);"
            "try{create_initial_administrator($db,'admin',password_hash('new-admin-password',PASSWORD_DEFAULT),"
            "static fn()=>write_installation_config(['database'=>'sqlite','admin_path'=>'/ops-x9']));exit(2);}"
            "catch(Throwable $error){fwrite(STDOUT,$error->getMessage());}",
            encoding="utf-8",
        )
        runtime = Path(self.runtime or os.environ.get("FRANKENPHP", DEFAULT_RUNTIME))
        command = [str(runtime), "php-cli", str(script), str(db_path), str(config_target)] if runtime.name == "frankenphp" else [str(runtime), str(script), str(db_path), str(config_target)]
        result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("cannot publish installation config", result.stdout)
        db = sqlite3.connect(db_path)
        user = db.execute("SELECT password,is_admin,enabled,auth_version FROM rustdesk_users WHERE id=1").fetchone()
        token_count = db.execute("SELECT COUNT(*) FROM rustdesk_token WHERE uid=1").fetchone()[0]
        db.close()
        self.assertEqual(user, (legacy_password("old-admin-password"), 0, 1, 0))
        self.assertEqual(token_count, 1)

    def test_00b_duplicate_legacy_username_blocks_setup_without_publishing_config(self):
        db_path = self.temp / "duplicate-admin.db"
        create_fixture(db_path)
        db = sqlite3.connect(db_path)
        db.execute(
            "INSERT INTO rustdesk_users(username,password,create_time,delete_time) VALUES ('admin',?,1700000004,0)",
            (legacy_password("second-password"),),
        )
        db.commit()
        db.close()
        config_target = self.temp / "duplicate-install.json"
        script = self.temp / "duplicate-admin-test.php"
        script.write_text(
            "<?php declare(strict_types=1);"
            f"require {json.dumps(str(SQLITE_DIR / 'lib.php'))};"
            "$db=open_database($argv[1]);"
            "try{create_initial_administrator($db,'admin',password_hash('new-admin-password',PASSWORD_DEFAULT),"
            "static fn()=>file_put_contents($argv[2],'published'));exit(2);}"
            "catch(InstallationConflict $error){fwrite(STDOUT,$error->getMessage());}",
            encoding="utf-8",
        )
        runtime = Path(self.runtime or os.environ.get("FRANKENPHP", DEFAULT_RUNTIME))
        command = [str(runtime), "php-cli", str(script), str(db_path), str(config_target)] if runtime.name == "frankenphp" else [str(runtime), str(script), str(db_path), str(config_target)]
        result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("重复记录", result.stdout)
        self.assertFalse(config_target.exists())
        db = sqlite3.connect(db_path)
        rows = db.execute("SELECT id,is_admin,auth_version FROM rustdesk_users WHERE username='admin' ORDER BY id").fetchall()
        db.close()
        self.assertEqual(rows, [(1, 0, 0), (3, 0, 0)])

    def test_01_legacy_fixture_is_preserved_on_startup(self):
        db = sqlite3.connect(self.db)
        row = db.execute("SELECT username,password,create_time,delete_time FROM rustdesk_users WHERE id=2").fetchone()
        peer = db.execute("SELECT uid,id,alias,tags,hash FROM rustdesk_peers WHERE deviceid=11").fetchone()
        token = db.execute("SELECT access_token,expire_time FROM rustdesk_token WHERE uid=2").fetchone()
        schema_version = db.execute("SELECT value FROM app_meta WHERE key='schema_version'").fetchone()[0]
        columns = {row[1] for row in db.execute("PRAGMA table_info(device_reports)").fetchall()}
        policy_columns = {row[1] for row in db.execute("PRAGMA table_info(device_update_policies)").fetchall()}
        db.close()
        self.assertEqual(row, ("legacy", legacy_password("legacy123"), 1700000001, 0))
        self.assertEqual(peer, (2, "legacy-id", "Old alias", "prod,blue", "legacy-hash"))
        self.assertEqual(token, ("a" * 64, 0))
        self.assertEqual(schema_version, "14")
        self.assertTrue({"runtime_payload", "network_payload"}.issubset(columns))
        self.assertTrue({"enable_check_update", "allow_auto_update", "enable_scheduled_update", "scheduled_update_interval_hours"}.issubset(policy_columns))
        auth = {"Authorization": "Bearer " + ("a" * 64)}
        _, current, _ = self.client.json("POST", "/?s=/api/currentUser", {"id": "legacy-id", "uuid": "legacy-uuid"}, auth)
        self.assertEqual(current.get("name"), "legacy")
        db = sqlite3.connect(self.db)
        migrated = int(db.execute("SELECT value FROM app_meta WHERE key='migrated_at'").fetchone()[0])
        db.execute("UPDATE app_meta SET value=? WHERE key='migrated_at'", (str(int(time.time()) - 8 * 86400),))
        db.commit()
        db.close()
        self.client.json("POST", "/?s=/api/currentUser", {"id": "legacy-id", "uuid": "legacy-uuid"}, auth, expected=(401,))
        db = sqlite3.connect(self.db)
        db.execute("UPDATE app_meta SET value=? WHERE key='migrated_at'", (str(migrated),))
        db.commit()
        db.close()

    def test_01b_v9_update_policy_migrates_without_losing_values(self):
        db_path = self.temp / "policy-v9.db"
        db = sqlite3.connect(db_path)
        db.executescript(
            """
            CREATE TABLE app_meta (key TEXT PRIMARY KEY,value TEXT NOT NULL);
            INSERT INTO app_meta VALUES ('schema_version','9');
            CREATE TABLE device_update_policies (
              id TEXT NOT NULL,uuid TEXT NOT NULL,mode TEXT NOT NULL DEFAULT 'notify',
              channel TEXT NOT NULL DEFAULT 'stable',target_version TEXT,target_build_seq INTEGER,
              auto_install INTEGER NOT NULL DEFAULT 0,policy_revision INTEGER NOT NULL DEFAULT 1,
              updated_by INTEGER,updated_at INTEGER NOT NULL,PRIMARY KEY(id,uuid)
            );
            INSERT INTO device_update_policies VALUES ('legacy-policy','legacy-policy-uuid','download','beta','1.9.0',99,1,7,NULL,1700000000);
            """
        )
        db.commit()
        db.close()
        script = self.temp / "migrate-policy-v9.php"
        script.write_text("<?php require $argv[1].'/lib.php'; open_database($argv[2]);", encoding="utf-8")
        runtime = Path(self.runtime or os.environ.get("FRANKENPHP", DEFAULT_RUNTIME))
        command = [str(runtime), "php-cli", str(script), str(SQLITE_DIR), str(db_path)] if runtime.name == "frankenphp" else [str(runtime), str(script), str(SQLITE_DIR), str(db_path)]
        result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        db = sqlite3.connect(db_path)
        columns = {row[1] for row in db.execute("PRAGMA table_info(device_update_policies)")}
        policy = db.execute("SELECT mode,channel,target_version,target_build_seq,auto_install,enable_check_update,allow_auto_update,enable_scheduled_update,scheduled_update_interval_hours,policy_revision FROM device_update_policies WHERE id='legacy-policy'").fetchone()
        version = db.execute("SELECT value FROM app_meta WHERE key='schema_version'").fetchone()[0]
        db.close()
        self.assertTrue({"enable_check_update", "allow_auto_update", "enable_scheduled_update", "scheduled_update_interval_hours"}.issubset(columns))
        self.assertEqual(policy, ("download", "beta", "1.9.0", 99, 1, 0, 0, 0, 5, 7))
        self.assertEqual(version, "14")

    def test_01c_v10_update_events_migrate_before_command_index(self):
        db_path = self.temp / "events-v10.db"
        db = sqlite3.connect(db_path)
        db.executescript(
            """
            CREATE TABLE app_meta (key TEXT PRIMARY KEY,value TEXT NOT NULL);
            INSERT INTO app_meta VALUES ('schema_version','10');
            CREATE TABLE rustdesk_users (
              id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL,password TEXT NOT NULL,
              create_time INTEGER NOT NULL DEFAULT 0,delete_time INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE device_update_events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,uuid TEXT NOT NULL,
              from_version TEXT,to_version TEXT,from_build_seq INTEGER,to_build_seq INTEGER,
              status TEXT NOT NULL,source TEXT,error_code TEXT,started_at INTEGER NOT NULL,finished_at INTEGER
            );
            INSERT INTO device_update_events (
              device_id,uuid,from_version,to_version,status,source,started_at
            ) VALUES ('legacy-device','legacy-uuid','1.0.0','1.1.0','completed','client',1700000000);
            """
        )
        db.commit()
        db.close()
        script = self.temp / "migrate-events-v10.php"
        script.write_text("<?php require $argv[1].'/lib.php'; open_database($argv[2]);", encoding="utf-8")
        runtime = Path(self.runtime or os.environ.get("FRANKENPHP", DEFAULT_RUNTIME))
        command = [str(runtime), "php-cli", str(script), str(SQLITE_DIR), str(db_path)] if runtime.name == "frankenphp" else [str(runtime), str(script), str(SQLITE_DIR), str(db_path)]
        result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        db = sqlite3.connect(db_path)
        columns = {row[1] for row in db.execute("PRAGMA table_info(device_update_events)")}
        indexes = {row[1] for row in db.execute("PRAGMA index_list(device_update_events)")}
        event = db.execute("SELECT device_id,uuid,status,command_id FROM device_update_events").fetchone()
        version = db.execute("SELECT value FROM app_meta WHERE key='schema_version'").fetchone()[0]
        db.close()
        self.assertIn("command_id", columns)
        self.assertIn("device_update_event_command_status", indexes)
        self.assertEqual(event, ("legacy-device", "legacy-uuid", "completed", None))
        self.assertEqual(version, "14")

    def test_02_rustdesk_login_current_user_and_logout_revokes_token(self):
        status, body, _ = self.client.json("POST", "/?s=/api/login", {"username": "legacy", "password": "legacy123", "id": "legacy-id", "uuid": "legacy-uuid"})
        self.assertEqual(status, 200)
        self.assertEqual(body.get("type"), "access_token")
        token = body["access_token"]
        auth = {"Authorization": "Bearer " + token}
        _, current, _ = self.client.json("POST", "/?s=/api/currentUser", {"id": "legacy-id", "uuid": "legacy-uuid"}, auth)
        self.assertEqual(current.get("name"), "legacy")
        self.client.json("POST", "/?s=/api/logout", {"id": "legacy-id", "uuid": "legacy-uuid"}, auth)
        status, _, _ = self.client.json("POST", "/?s=/api/currentUser", {"id": "legacy-id", "uuid": "legacy-uuid"}, auth, expected=(401,))
        self.assertEqual(status, 401)

    def rust_login(self, username="legacy", password="legacy123", device_id="legacy-id"):
        _, body, _ = self.client.json("POST", "/?s=/api/login", {"username": username, "password": password, "id": device_id, "uuid": "legacy-uuid"})
        return {"Authorization": "Bearer " + body["access_token"]}

    def admin_csrf(self):
        _, session, _ = self.client.json("GET", "/?s=/ops-x9/api/session")
        if session.get("user") is not None:
            return session["csrf"]
        for password in ("admin456", "admin123"):
            status, login, _ = self.client.json(
                "POST", "/?s=/ops-x9/api/login",
                {"username": "admin", "password": password},
                {"X-CSRF-Token": session["csrf"]}, expected=(200, 401),
            )
            if status == 200:
                self.admin_password = password
                return login["csrf"]
        self.fail("administrator login failed with both fixture passwords")

    def rust_admin_login(self, device_id):
        for password in ("admin456", "admin123"):
            status, body, _ = self.client.json(
                "POST", "/?s=/api/login",
                {"username": "admin", "password": password, "id": device_id, "uuid": "admin-book-uuid"},
                expected=(200, 401),
            )
            if status == 200:
                return {"Authorization": "Bearer " + body["access_token"]}
        self.fail("RustDesk administrator login failed with both fixture passwords")

    def test_03_address_book_bad_payload_does_not_clear_existing_rows(self):
        auth = self.rust_login()
        self.client.json("POST", "/?s=/api/ab", {"data": "not-json"}, auth, expected=(422,))
        db = sqlite3.connect(self.db)
        row = db.execute("SELECT id,alias,tags,hash FROM rustdesk_peers WHERE deviceid=11").fetchone()
        db.close()
        self.assertEqual(row, ("legacy-id", "Old alias", "prod,blue", "legacy-hash"))

    def test_04_address_book_preserves_unknown_fields_commas_and_empty_set(self):
        auth = self.rust_login()
        payload = {
            "tags": ["comma,tag", "blue"],
            "peers": [{"id": "roundtrip-id", "username": "u", "hostname": "h", "alias": "a",
                       "platform": "linux", "tags": ["comma,tag"], "hash": "x", "future_field": {"v": 1}}],
            "future_top_level": {"enabled": True},
        }
        self.client.json("POST", "/?s=/api/ab", {"data": json.dumps(payload, ensure_ascii=False)}, auth)
        _, result, _ = self.client.json("GET", "/?s=/api/ab", None, auth)
        returned = json.loads(result["data"])
        self.assertEqual(returned["future_top_level"], {"enabled": True})
        self.assertEqual(returned["peers"][0]["future_field"], {"v": 1})
        self.assertEqual(returned["tags"][0], "comma,tag")
        self.client.json("POST", "/?s=/api/ab", {"data": json.dumps({"tags": [], "peers": []})}, auth)
        _, empty, _ = self.client.json("GET", "/?s=/api/ab", None, auth)
        self.assertEqual(json.loads(empty["data"]), {"tags": [], "peers": []})

    def test_05_sysinfo_and_anonymous_heartbeat(self):
        status, text, ctype = self.client.request("POST", "/?s=/api/sysinfo", {"id": "new-id", "uuid": "new-uuid", "version": "1.5.0", "hostname": "host", "os": "linux", "cpu": "x", "memory": "1G", "platform": "linux", "distribution": "portable", "install_mode": "portable", "enable_check_update": True, "allow_auto_update": False, "network": {"private_ips": ["192.168.1.20", "fd12:3456:789a::20"]}}, expected=(200,))
        self.assertEqual(status, 200)
        self.assertTrue("text/plain" in ctype)
        self.assertIn("SYSINFO", text)
        db = sqlite3.connect(self.db)
        runtime, network = db.execute("SELECT runtime_payload,network_payload FROM device_reports WHERE id='new-id'").fetchone()
        self.assertEqual(json.loads(runtime)["distribution"], "portable")
        self.assertTrue(json.loads(runtime)["enable_check_update"])
        self.assertFalse(json.loads(runtime)["allow_auto_update"])
        self.assertEqual(json.loads(network)["private_ips"], ["192.168.1.20", "fd12:3456:789a::20"])
        db.close()
        _, heartbeat, _ = self.client.json("POST", "/?s=/api/heartbeat", {"id": "anonymous", "uuid": str(uuid.uuid4()), "conns": []})
        self.assertIsInstance(heartbeat, dict)

    def test_05a_sqlite_login_waits_for_writer_and_uses_wal(self):
        lock = sqlite3.connect(self.db, timeout=0)
        self.assertEqual(lock.execute("PRAGMA journal_mode").fetchone()[0].lower(), "wal")
        lock.execute("BEGIN IMMEDIATE")
        try:
            with ThreadPoolExecutor(max_workers=1) as pool:
                login = pool.submit(
                    self.client.json,
                    "POST",
                    "/?s=/api/login",
                    {
                        "username": "admin",
                        "password": "admin123",
                        "id": "locked-login-device",
                        "uuid": "locked-login-uuid",
                    },
                )
                time.sleep(5.5)
                lock.commit()
                status, body, _ = login.result(timeout=12)
        finally:
            if lock.in_transaction:
                lock.rollback()
            lock.close()
        self.assertEqual(status, 200)
        self.assertEqual(body["type"], "access_token")
        db = sqlite3.connect(self.db)
        token = db.execute(
            "SELECT username,id,uuid FROM rustdesk_token WHERE access_token=?",
            (body["access_token"],),
        ).fetchone()
        db.close()
        self.assertEqual(token, ("admin", "locked-login-device", "locked-login-uuid"))

    def test_client_release_identity_is_visible_from_sysinfo_and_update_check(self):
        device_id = "release-" + uuid.uuid4().hex[:8]
        device_uuid = device_id + "-uuid"
        status, body, _ = self.client.request("POST", "/?s=/api/sysinfo", {
            "id": device_id, "uuid": device_uuid, "hostname": "release-host",
            "client_id": "RustDesk Yan", "client_uuid": device_uuid,
            "product": "rustdesk-yan", "edition": "custom", "version": "1.5.0",
            "build_number": "20260930.2", "build_seq": 2026093002, "channel": "stable",
            "platform": "windows", "arch": "x86_64", "distribution": "desktop",
            "install_mode": "installed", "source_commit": "commit-sha",
            "os": "Windows", "os_version": "Windows 11",
        }, expected=(200,))
        self.assertEqual(status, 200)
        self.assertEqual(body, "SYSINFO_UPDATED")
        self.admin_csrf()
        _, listing, _ = self.client.json("GET", f"/?s=/ops-x9/api/devices?q={device_id}&page=1&pageSize=20")
        row = next(item for item in listing["data"] if item["id"] == device_id)
        self.assertEqual(row["client_id"], "RustDesk Yan")
        self.assertEqual(row["client_uuid"], device_uuid)
        self.assertEqual(row["product"], "rustdesk-yan")
        self.assertEqual(row["edition"], "custom")
        self.assertEqual(row["version"], "1.5.0")
        self.assertEqual(row["build_number"], "20260930.2")
        self.assertEqual(row["build_seq"], 2026093002)
        self.assertEqual(row["channel"], "stable")
        self.assertEqual(row["arch"], "x86_64")
        self.assertEqual(row["distribution"], "desktop")
        self.assertEqual(row["install_mode"], "installed")
        self.assertEqual(row["source_commit"], "commit-sha")
        self.assertEqual(row["os"], "Windows")
        self.assertEqual(row["os_version"], "Windows 11")
        db = sqlite3.connect(self.db)
        stored = json.loads(db.execute("SELECT payload FROM device_reports WHERE id=?", (device_id,)).fetchone()[0])
        self.assertEqual(stored["build_number"], "20260930.2")
        self.assertEqual(stored["source_commit"], "commit-sha")
        db.execute(
            "UPDATE device_reports SET payload=?, runtime_payload=? WHERE id=?",
            (
                json.dumps({"version": "1.4.0", "hostname": "release-host", "build_number": "20260930.2", "build_seq": 2026093002, "source_commit": "commit-sha", "client_id": "RustDesk Yan", "client_uuid": device_uuid, "product": "rustdesk-yan", "edition": "custom", "channel": "stable", "platform": "windows", "arch": "x86_64", "distribution": "desktop", "install_mode": "installed", "os": "Windows", "os_version": "Windows 11"}),
                json.dumps({"version": "1.5.0", "build_number": "20260930.2", "build_seq": 2026093002, "source_commit": "commit-sha"}),
                device_id,
            ),
        )
        db.commit()
        db.close()
        _, conflict, _ = self.client.json("GET", f"/?s=/ops-x9/api/devices?q={device_id}&page=1&pageSize=20")
        conflicted = next(item for item in conflict["data"] if item["id"] == device_id)
        self.assertEqual(conflicted["version"], "1.5.0")
        self.assertEqual(conflicted["version_text"], "1.5.0")
        db = sqlite3.connect(self.db)
        db.execute(
            "UPDATE device_reports SET runtime_payload=? WHERE id=?",
            (json.dumps({"version": "1.5.0", "platform": "windows", "distribution": "desktop", "install_mode": "installed", "client_arch": "x86_64", "executable_name": "rustdesk.exe"}), device_id),
        )
        db.commit()
        db.close()
        _, again, _ = self.client.json("GET", f"/?s=/ops-x9/api/devices?q={device_id}&page=1&pageSize=20")
        preserved = next(item for item in again["data"] if item["id"] == device_id)
        self.assertEqual(preserved["build_number"], "20260930.2")
        self.assertEqual(preserved["build_seq"], 2026093002)
        self.assertEqual(preserved["client_id"], "RustDesk Yan")
        self.assertEqual(preserved["source_commit"], "commit-sha")
        bare = "check-" + uuid.uuid4().hex[:8]
        bare_uuid = bare + "-uuid"
        self.client.json("POST", "/?s=/api/heartbeat", {"id": bare, "uuid": bare_uuid, "ver": 1, "conns": []})
        self.client.json("POST", "/?s=/rd/update/v1/check", {
            "client_id": "RustDesk Yan", "client_uuid": bare_uuid,
            "product": "rustdesk-yan", "edition": "custom", "version": "1.5.0",
            "build_number": "20260930.2", "build_seq": 2026093002, "channel": "stable",
            "platform": "windows", "arch": "x86_64", "distribution": "desktop",
            "install_mode": "installed", "source_commit": "commit-sha",
            "os": "Windows", "os_version": "Windows 11",
        })
        _, checked, _ = self.client.json("GET", f"/?s=/ops-x9/api/devices?q={bare}&page=1&pageSize=20")
        shown = next(item for item in checked["data"] if item["id"] == bare)
        self.assertEqual(shown["client_id"], "RustDesk Yan")
        self.assertEqual(shown["build_number"], "20260930.2")
        self.assertEqual(shown["os_version"], "Windows 11")
        self.assertEqual(shown["arch"], "x86_64")
        before = self.client.json("GET", "/?s=/ops-x9/api/devices?q=Ghost&page=1&pageSize=20")[1]["total"]
        self.client.json("POST", "/?s=/rd/update/v1/check", {"client_id": "Ghost", "client_uuid": "missing-uuid", "version": "1.5.0", "build_seq": 1, "channel": "stable"})
        self.assertEqual(self.client.json("GET", "/?s=/ops-x9/api/devices?q=Ghost&page=1&pageSize=20")[1]["total"], before)

    def test_update_manifest_check_and_event_round_trip(self):
        csrf = self.admin_csrf()
        self.client.json("POST", "/?s=/api/heartbeat", {"id": "update-publish-device", "uuid": "update-publish-uuid", "conns": []})
        self.client.json("POST", "/?s=/api/sysinfo", {"id": "update-publish-device", "uuid": "update-publish-uuid", "product": "rustdesk-yan", "edition": "custom", "platform": "windows", "arch": "x86_64", "package_kind": "exe"})
        target = {
            "primary": "https://download.yan.life/rustdesk/stable/v1.5.0-build-2026.10.01-01/rustdesk-1.5.0-custom-windows-x86_64.exe",
            "mirrors": [], "size": 12, "sha256": "b" * 64,
            "signature": __import__("base64").b64encode(b"t" * 64).decode(), "signature_key_id": "yan-release-2026",
        }
        manifest = {"product": "rustdesk-yan", "edition": "custom", "source_commit": "admin-commit", "targets": {"windows-x86_64-exe-custom": target}}
        payload = {"version": "1.5.0", "build_seq": 2026100101, "channel": "stable", "manifest": manifest}
        _, published, _ = self.client.json("POST", "/?s=/ops-x9/api/update/releases", payload, {"X-CSRF-Token": csrf}, expected=(201,))
        self.assertEqual(published["build_seq"], 2026100101)
        self.assertGreaterEqual(published["notified_clients"], 1)
        self.assertTrue(self.client.json("POST", "/?s=/ops-x9/api/update/releases", payload, {"X-CSRF-Token": csrf}, expected=(201,))[1]["idempotent"])
        conflict = json.loads(json.dumps(payload)); conflict["manifest"]["source_commit"] = "different-admin-commit"
        self.client.json("POST", "/?s=/ops-x9/api/update/releases", conflict, {"X-CSRF-Token": csrf}, expected=(409,))
        rollback = json.loads(json.dumps(payload)); rollback["version"] = "1.4.9"; rollback["build_seq"] = 2026100100
        self.client.json("POST", "/?s=/ops-x9/api/update/releases", rollback, {"X-CSRF-Token": csrf}, expected=(409,))
        invalid = json.loads(json.dumps(payload)); invalid["manifest"]["targets"]["windows-x86_64-exe-custom"]["sha256"] = "bad"
        self.client.json("POST", "/?s=/ops-x9/api/update/releases", invalid, {"X-CSRF-Token": csrf}, expected=(422,))
        _, check, _ = self.client.json("POST", "/?s=/rd/update/v1/check", {"client_id": "update-device", "client_uuid": "update-uuid", "product": "rustdesk-yan", "edition": "custom", "version": "1.5.0", "build_seq": 1, "channel": "stable"})
        self.assertTrue(check["update_available"])
        self.assertEqual(check["target_build_seq"], 2026100101)
        _, event, _ = self.client.json("POST", "/?s=/rd/update/v1/events", {"client_id": "update-device", "client_uuid": "update-uuid", "status": "installed", "from_version": "1.5.0", "to_version": "1.5.0", "from_build_seq": 1, "to_build_seq": 2026100101}, expected=(201,))
        self.assertTrue(event["ok"])
        self.client.json("POST", "/?s=/rd/update/v1/events", {"client_id": "update-device", "client_uuid": "update-uuid", "status": "deferred", "to_build_seq": 2026100101, "error_code": "user_deferred"}, expected=(201,))
        self.client.json("POST", "/?s=/rd/update/v1/events", {"client_id": "update-device", "client_uuid": "update-uuid", "status": "rollback_failed", "to_build_seq": 2026100101, "error_code": "restore_failed"}, expected=(201,))
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT status,error_code FROM device_update_events WHERE device_id='update-device' ORDER BY id").fetchall(), [("installed", None), ("deferred", "user_deferred"), ("rollback_failed", "restore_failed")])
        self.assertEqual(db.execute("SELECT COUNT(*) FROM update_releases WHERE channel='stable' AND build_seq=2026100101").fetchone()[0], 1)
        self.assertEqual(db.execute("SELECT action,target_version,target_build_seq,status FROM device_update_commands WHERE device_id='update-publish-device' AND uuid='update-publish-uuid'").fetchall(), [("check", "1.5.0", 2026100101, "pending")])
        db.close()

    def test_update_identity_resolves_reported_device_by_uuid(self):
        csrf = self.admin_csrf()
        device_id = "update-real-device"
        device_uuid = "update-shared-uuid"
        self.client.json("POST", "/?s=/api/heartbeat", {
            "id": device_id, "uuid": device_uuid, "ver": 1, "conns": [],
        })
        self.client.json(
            "PATCH",
            f"/?s=/ops-x9/api/update/policies/{device_id}",
            {"uuid": device_uuid, "mode": "auto_install", "channel": "stable", "auto_install": True},
            {"X-CSRF-Token": csrf},
        )

        _, check, _ = self.client.json("POST", "/?s=/rd/update/v1/check", {
            "client_id": "RustDesk Yan", "client_uuid": device_uuid,
            "version": "1.5.0", "build_seq": 1, "channel": "stable",
        })
        self.assertEqual(check["mode"], "auto_install")
        self.assertTrue(check["auto_install"])
        self.assertEqual(check["policy_revision"], 1)

        self.client.json("POST", "/?s=/rd/update/v1/events", {
            "client_id": "RustDesk Yan", "client_uuid": device_uuid,
            "status": "installed", "to_build_seq": 2026100101,
        }, expected=(201,))
        db = sqlite3.connect(self.db)
        self.assertEqual(
            db.execute(
                "SELECT device_id,uuid,status FROM device_update_events WHERE uuid=?",
                (device_uuid,),
            ).fetchall(),
            [(device_id, device_uuid, "installed")],
        )
        db.close()

        self.client.json("POST", "/?s=/api/heartbeat", {
            "id": "update-duplicate-device", "uuid": device_uuid, "ver": 1, "conns": [],
        })
        _, exact, _ = self.client.json("POST", "/?s=/rd/update/v1/check", {
            "client_id": device_id, "client_uuid": device_uuid,
            "version": "1.5.0", "build_seq": 1, "channel": "stable",
        })
        self.assertEqual(exact["mode"], "auto_install")
        self.assertEqual(exact["policy_revision"], 1)
        _, ambiguous, _ = self.client.json("POST", "/?s=/rd/update/v1/check", {
            "client_id": "RustDesk Alias", "client_uuid": device_uuid,
            "version": "1.5.0", "build_seq": 1, "channel": "stable",
        })
        self.assertEqual(ambiguous["mode"], "notify")
        self.assertEqual(ambiguous["policy_revision"], 0)

    def test_machine_publish_validates_manifest_and_drives_platform_checks(self):
        asset = {
            "primary": "https://download.yan.life/rustdesk/stable/v1.5.0-build-2026.09.30-01/rustdesk-1.5.0-standard-windows-x86_64.exe",
            "mirrors": [], "size": 12, "sha256": "a" * 64,
            "signature": __import__("base64").b64encode(b"s" * 64).decode(), "signature_key_id": "yan-release-2026",
        }
        msi_asset = dict(asset, primary=asset["primary"].removesuffix(".exe") + ".msi")
        manifest = {
            "version": "1.5.0", "build_number": "20260930.5", "build_seq": 2026093005,
            "product": "rustdesk-yan", "edition": "standard", "channel": "stable",
            "source_commit": "commit-sha", "targets": {
                "windows-x86_64-exe-standard": asset,
                "windows-x86_64-msi-standard": msi_asset,
            },
        }
        auth = {"Authorization": "Bearer " + "p" * 48}
        status, published, _ = self.client.json(
            "POST", "/?s=/rd/update/v1/publish", manifest, auth, expected=(201,)
        )
        self.assertEqual(status, 201)
        self.assertEqual(published["build_seq"], 2026093005)
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/publish", manifest, auth, expected=(200, 201))[1]["build_seq"], 2026093005)
        conflict = json.loads(json.dumps(manifest)); conflict["source_commit"] = "different-commit"
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/publish", conflict, auth, expected=(409,))[0], 409)
        rollback = json.loads(json.dumps(manifest)); rollback["version"] = "1.4.9"; rollback["build_seq"] = 2026093004
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/publish", rollback, auth, expected=(409,))[0], 409)

        request = {
            "client_id": "update-standard", "client_uuid": "update-standard-uuid",
            "product": "rustdesk-yan", "edition": "standard", "version": "1.5.0",
            "build_seq": 2026093004, "channel": "stable", "platform": "windows",
            "arch": "x86_64", "package_kind": "exe", "distribution": "desktop", "install_mode": "portable",
        }
        _, check, _ = self.client.json("POST", "/?s=/rd/update/v1/check", request)
        self.assertTrue(check["update_available"])
        self.assertEqual(check["url"], asset["primary"])
        self.assertEqual(check["manifest"]["targets"]["windows-x86_64-exe-standard"]["sha256"], "a" * 64)
        request["target_key"] = "windows-x86_64-msi-standard"
        _, exact, _ = self.client.json("POST", "/?s=/rd/update/v1/check", request)
        self.assertEqual(exact["url"], msi_asset["primary"])
        request.pop("target_key"); request["package_kind"] = "msi"
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/check", request)[1]["url"], msi_asset["primary"])
        request.pop("package_kind")
        self.assertFalse(self.client.json("POST", "/?s=/rd/update/v1/check", request)[1]["update_available"])
        request["package_kind"] = "exe"; request["build_seq"] = 2026093005
        _, current_release, _ = self.client.json("POST", "/?s=/rd/update/v1/check", request)
        self.assertFalse(current_release["update_available"])
        self.assertEqual((current_release["target_version"], current_release["target_build_seq"]), ("1.5.0", 2026093005))
        self.assertNotIn("url", current_release)
        self.assertNotIn("manifest_url", current_release)
        self.assertNotIn("manifest", current_release)
        request["build_seq"] = 2026093004
        _, public_manifest, _ = self.client.json("GET", "/?s=/rd/update/v1/manifest/stable.json")
        self.assertEqual(public_manifest, manifest)
        _, keys, _ = self.client.json("GET", "/?s=/rd/update/v1/keys.json")
        self.assertEqual(keys["keys"][0]["id"], "yan-release-2026")
        request["edition"] = "sos"
        self.assertFalse(self.client.json("POST", "/?s=/rd/update/v1/check", request)[1]["update_available"])
        request["edition"] = "standard"; request["platform"] = "macos"; request["arch"] = "aarch64"
        self.assertFalse(self.client.json("POST", "/?s=/rd/update/v1/check", request)[1]["update_available"])

        db = sqlite3.connect(self.db)
        stored = db.execute(
            "SELECT manifest FROM update_releases WHERE version='1.5.0' AND build_seq=2026093005 AND channel='stable'"
        ).fetchone()
        release_count = db.execute("SELECT COUNT(*) FROM update_releases WHERE channel='stable'").fetchone()[0]
        db.close()
        self.assertEqual(json.loads(stored[0])["edition"], "standard")
        self.assertEqual(release_count, 1)

        bad = json.loads(json.dumps(manifest)); bad["targets"]["windows-x86_64-exe-standard"]["sha256"] = "bad"
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/publish", bad, auth, expected=(422,))[0], 422)
        bad = json.loads(json.dumps(manifest)); bad["targets"]["windows-x86_64-exe-standard"]["size"] = 0
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/publish", bad, auth, expected=(422,))[0], 422)
        bad = json.loads(json.dumps(manifest)); bad["targets"]["windows-x86_64-exe-standard"]["signature"] = "invalid"
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/publish", bad, auth, expected=(422,))[0], 422)
        bad = json.loads(json.dumps(manifest)); bad["targets"]["windows-x86_64-exe-standard"]["primary"] = "https://evil.example/rustdesk.exe"
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/publish", bad, auth, expected=(422,))[0], 422)
        bad = json.loads(json.dumps(manifest)); bad["targets"]["windows-x86_64-exe-standard"]["signature_key_id"] = "unknown-key"
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/publish", bad, auth, expected=(422,))[0], 422)
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/publish", manifest, expected=(401,))[0], 401)
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/events", {
            "client_id": "update-standard", "client_uuid": "update-standard-uuid", "status": "unknown",
        }, expected=(422,))[0], 422)

    def test_admin_device_update_policy_round_trip_and_validation(self):
        csrf = self.admin_csrf()
        device_id, uuid = "policy-device", "policy-device-uuid"
        self.client.json("POST", "/?s=/rd/update/v1/check", {"client_id": device_id, "client_uuid": uuid, "version": "1.5.0", "build_seq": 1, "channel": "stable"})
        _, initial, _ = self.client.json("GET", f"/?s=/ops-x9/api/update/policies/{device_id}?uuid={uuid}")
        self.assertEqual(initial["mode"], "notify")
        self.assertFalse(initial["enable_check_update"])
        self.assertFalse(initial["allow_auto_update"])
        self.assertFalse(initial["enable_scheduled_update"])
        self.assertEqual(initial["scheduled_update_interval_hours"], 5)
        _, saved, _ = self.client.json("PATCH", f"/?s=/ops-x9/api/update/policies/{device_id}", {"uuid": uuid, "mode": "auto_install", "channel": "beta", "target_version": "1.6.0", "target_build_seq": 2026100102, "auto_install": True, "enable_check_update": True, "allow_auto_update": True, "enable_scheduled_update": True, "scheduled_update_interval_hours": 12}, {"X-CSRF-Token": csrf})
        self.assertEqual(saved["mode"], "auto_install")
        self.assertTrue(saved["enable_check_update"])
        self.assertTrue(saved["allow_auto_update"])
        self.assertTrue(saved["enable_scheduled_update"])
        self.assertEqual(saved["scheduled_update_interval_hours"], 12)
        _, persisted, _ = self.client.json("GET", f"/?s=/ops-x9/api/update/policies/{device_id}?uuid={uuid}&channel=stable")
        self.assertEqual((persisted["mode"], persisted["channel"], persisted["target_version"], int(persisted["target_build_seq"])), ("auto_install", "beta", "1.6.0", 2026100102))
        self.assertTrue(persisted["enable_check_update"])
        self.assertTrue(persisted["allow_auto_update"])
        self.assertTrue(persisted["enable_scheduled_update"])
        self.assertEqual(persisted["scheduled_update_interval_hours"], 12)
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT mode,channel,target_version,target_build_seq,auto_install,enable_check_update,allow_auto_update,enable_scheduled_update,scheduled_update_interval_hours FROM device_update_policies WHERE id=? AND uuid=?", (device_id, uuid)).fetchone(), ("auto_install", "beta", "1.6.0", 2026100102, 1, 1, 1, 1, 12))
        db.close()
        status, stream, content_type = self.client.request("GET", f"/?s=/rd/update/v1/policy/stream&client_id={device_id}&client_uuid={uuid}", headers={"Accept": "text/event-stream"}, expected=(200,))
        self.assertEqual(status, 200)
        self.assertIn("text/event-stream", content_type)
        self.assertIn("event: update-policy\n", stream)
        first_event_id = int(next(line[4:] for line in stream.splitlines() if line.startswith("id: ")))
        event = json.loads(next(line[6:] for line in stream.splitlines() if line.startswith("data: ")))
        self.assertEqual((event["client_id"], event["client_uuid"], event["policy_revision"]), (device_id, uuid, 1))
        self.assertTrue(event["enable_check_update"])
        self.assertTrue(event["allow_auto_update"])
        self.assertTrue(event["enable_scheduled_update"])
        self.assertEqual(event["scheduled_update_interval_hours"], 12)
        _, saved_again, _ = self.client.json("PATCH", f"/?s=/ops-x9/api/update/policies/{device_id}", {"uuid": uuid, "mode": "notify", "channel": "stable", "target_version": None, "target_build_seq": None, "auto_install": False, "enable_check_update": False, "allow_auto_update": False, "enable_scheduled_update": False, "scheduled_update_interval_hours": 5}, {"X-CSRF-Token": csrf})
        self.assertEqual(saved_again["policy_revision"], 2)
        _, resumed, _ = self.client.request("GET", f"/?s=/rd/update/v1/policy/stream&client_id={device_id}&client_uuid={uuid}", headers={"Accept": "text/event-stream", "Last-Event-ID": str(first_event_id)}, expected=(200,))
        second_event_id = int(next(line[4:] for line in resumed.splitlines() if line.startswith("id: ")))
        resumed_event = json.loads(next(line[6:] for line in resumed.splitlines() if line.startswith("data: ")))
        self.assertEqual(resumed_event["policy_revision"], 2)
        self.assertFalse(resumed_event["enable_check_update"])
        self.assertFalse(resumed_event["allow_auto_update"])
        self.assertFalse(resumed_event["enable_scheduled_update"])
        self.assertEqual(resumed_event["scheduled_update_interval_hours"], 5)
        self.client.json("PATCH", f"/?s=/ops-x9/api/update/policies/{device_id}", {"uuid": uuid, "mode": "notify", "channel": "stable", "enable_check_update": True, "allow_auto_update": True}, {"X-CSRF-Token": csrf})
        started = time.monotonic()
        _, live_stream, _ = self.client.request("GET", f"/?s=/rd/update/v1/policy/stream&client_id={device_id}&client_uuid={uuid}", headers={"Accept": "text/event-stream", "Last-Event-ID": str(second_event_id)}, expected=(200,))
        self.assertLess(time.monotonic() - started, 1)
        live_event = json.loads(next(line[6:] for line in live_stream.splitlines() if line.startswith("data: ")))
        self.assertEqual(live_event["policy_revision"], 3)
        self.assertTrue(live_event["enable_check_update"])
        self.assertTrue(live_event["allow_auto_update"])
        self.assertEqual(self.client.request("GET", "/?s=/rd/update/v1/policy/stream", headers={"Accept": "text/event-stream"}, expected=(422,))[0], 422)
        self.assertEqual(self.client.request("GET", f"/?s=/rd/update/v1/policy/stream&client_id={device_id}&client_uuid={uuid}", headers={"Accept": "text/event-stream", "Last-Event-ID": "bad"}, expected=(422,))[0], 422)
        status, error, _ = self.client.json("PATCH", f"/?s=/ops-x9/api/update/policies/{device_id}", {"uuid": uuid, "mode": "notify", "channel": "stable", "target_build_seq": "not-a-number"}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.assertEqual(status, 422)
        self.assertIn("build_seq", error["error"])
        self.assertEqual(self.client.json("PATCH", f"/?s=/ops-x9/api/update/policies/{device_id}", {"uuid": uuid, "scheduled_update_interval_hours": 0}, {"X-CSRF-Token": csrf}, expected=(422,))[0], 422)
        self.assertEqual(self.client.json("PATCH", f"/?s=/ops-x9/api/update/policies/{device_id}", {"uuid": uuid, "scheduled_update_interval_hours": 169}, {"X-CSRF-Token": csrf}, expected=(422,))[0], 422)
        status, error, _ = self.client.json("PATCH", f"/?s=/ops-x9/api/update/policies/{device_id}", {"uuid": uuid, "mode": "notify", "channel": "stable", "target_build_seq": -1}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.assertEqual(status, 422)
        self.assertIn("build_seq", error["error"])

    def test_reported_update_settings_initialize_and_reconcile_policy_by_revision(self):
        csrf = self.admin_csrf()
        device_id, uuid = "reported-policy-device", "reported-policy-device-uuid"
        reported = {
            "id": device_id, "uuid": uuid, "hostname": "reported-policy-host",
            "enable_check_update": True, "allow_auto_update": True,
            "enable_scheduled_update": True, "scheduled_update_interval_hours": 18,
            "update_policy_revision": 0,
        }
        self.client.json("POST", "/?s=/api/sysinfo", reported)
        _, initial, _ = self.client.json("GET", f"/?s=/ops-x9/api/update/policies/{device_id}?uuid={uuid}")
        self.assertTrue(initial["enable_check_update"])
        self.assertTrue(initial["allow_auto_update"])
        self.assertTrue(initial["enable_scheduled_update"])
        self.assertEqual(initial["scheduled_update_interval_hours"], 18)
        self.assertEqual(initial["policy_revision"], 0)

        _, saved, _ = self.client.json("PATCH", f"/?s=/ops-x9/api/update/policies/{device_id}", {
            "uuid": uuid, "mode": "notify", "channel": "stable",
            "enable_check_update": False, "allow_auto_update": False,
            "enable_scheduled_update": False, "scheduled_update_interval_hours": 6,
        }, {"X-CSRF-Token": csrf})
        self.assertEqual(saved["policy_revision"], 1)

        self.client.json("POST", "/?s=/api/sysinfo", reported)
        _, after_stale, _ = self.client.json("GET", f"/?s=/ops-x9/api/update/policies/{device_id}?uuid={uuid}")
        self.assertFalse(after_stale["enable_check_update"])
        self.assertFalse(after_stale["allow_auto_update"])
        self.assertFalse(after_stale["enable_scheduled_update"])
        self.assertEqual(after_stale["scheduled_update_interval_hours"], 6)
        self.assertEqual(after_stale["policy_revision"], 1)

        self.client.json("POST", "/?s=/api/sysinfo", {
            **reported, "update_policy_revision": 1, "enable_check_update": True,
            "allow_auto_update": True, "enable_scheduled_update": True,
            "scheduled_update_interval_hours": 9,
        })
        _, reconciled, _ = self.client.json("GET", f"/?s=/ops-x9/api/update/policies/{device_id}?uuid={uuid}")
        self.assertTrue(reconciled["enable_check_update"])
        self.assertTrue(reconciled["allow_auto_update"])
        self.assertTrue(reconciled["enable_scheduled_update"])
        self.assertEqual(reconciled["scheduled_update_interval_hours"], 9)
        self.assertEqual(reconciled["policy_revision"], 2)
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute(
            "SELECT enable_check_update,allow_auto_update,enable_scheduled_update,scheduled_update_interval_hours,policy_revision FROM device_update_policies WHERE id=? AND uuid=?",
            (device_id, uuid),
        ).fetchone(), (1, 1, 1, 9, 2))
        db.close()
    def test_admin_bulk_update_commands_and_scheduled_policy(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        devices = [{"id": f"bulk-update-{suffix}-{index}", "uuid": f"bulk-update-uuid-{suffix}-{index}"} for index in range(3)]
        for device in devices:
            self.client.json("POST", "/?s=/api/heartbeat", {**device, "conns": []})
            self.client.json("POST", "/?s=/api/sysinfo", {**device, "product": "rustdesk-yan", "edition": "custom", "platform": "windows", "arch": "x86_64", "package_kind": "exe"})

        endpoint = "/?s=/ops-x9/api/update/policies/batch"
        _, saved, _ = self.client.json("PATCH", endpoint, {
            "devices": devices[:2], "enable_scheduled_update": True, "scheduled_update_interval_hours": 8,
        }, {"X-CSRF-Token": csrf})
        self.assertEqual(saved["updated"], 2)
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT id,enable_scheduled_update,scheduled_update_interval_hours FROM device_update_policies WHERE id LIKE ? ORDER BY id", (f"bulk-update-{suffix}-%",)).fetchall(), [(devices[0]["id"], 1, 8), (devices[1]["id"], 1, 8)])
        db.close()

        _, repeated, _ = self.client.json("PATCH", endpoint, {
            "devices": devices[:2], "enable_scheduled_update": True, "scheduled_update_interval_hours": 8,
        }, {"X-CSRF-Token": csrf})
        self.assertEqual(repeated["updated"], 2)
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT policy_revision FROM device_update_policies WHERE id=?", (devices[0]["id"],)).fetchone()[0], 1)
        db.close()

        _, all_saved, _ = self.client.json("PATCH", endpoint, {
            "all": True, "enable_scheduled_update": False, "scheduled_update_interval_hours": 5,
        }, {"X-CSRF-Token": csrf})
        self.assertGreaterEqual(all_saved["updated"], 3)

        _, commands, _ = self.client.json("POST", "/?s=/ops-x9/api/update/commands/batch", {
            "devices": devices[:2], "action": "check",
        }, {"X-CSRF-Token": csrf}, expected=(201,))
        self.assertEqual(commands["created"], 2)
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT device_id,uuid,action,status FROM device_update_commands WHERE device_id LIKE ? ORDER BY device_id", (f"bulk-update-{suffix}-%",)).fetchall(), [(devices[0]["id"], devices[0]["uuid"], "check", "pending"), (devices[1]["id"], devices[1]["uuid"], "check", "pending")])
        db.close()

        unknown = {"id": "not-a-device", "uuid": devices[0]["uuid"]}
        self.assertEqual(self.client.json("POST", "/?s=/ops-x9/api/update/commands/batch", {
            "devices": [unknown], "action": "check",
        }, {"X-CSRF-Token": csrf}, expected=(404,))[0], 404)

    def test_admin_one_shot_update_commands_stream_resume_and_status(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"command-{suffix}", f"command-uuid-{suffix}"
        other_id, other_uuid = f"command-other-{suffix}", f"command-other-uuid-{suffix}"
        self.client.json("POST", "/?s=/api/heartbeat", {"id": device_id, "uuid": device_uuid, "conns": []})
        self.client.json("POST", "/?s=/api/heartbeat", {"id": other_id, "uuid": other_uuid, "conns": []})
        for current_id, current_uuid in ((device_id, device_uuid), (other_id, other_uuid)):
            self.client.json("POST", "/?s=/api/sysinfo", {"id": current_id, "uuid": current_uuid, "product": "rustdesk-yan", "edition": "custom", "platform": "windows", "arch": "x86_64", "package_kind": "exe"})
        endpoint = f"/?s=/ops-x9/api/update/commands/{device_id}"
        payload = {"uuid": device_uuid, "action": "install", "target_version": "0.0.1", "target_build_seq": 7, "expires_in": 3600}
        anonymous = HttpClient(self.url)
        self.assertEqual(anonymous.json("POST", endpoint, payload, expected=(401,))[0], 401)
        self.assertEqual(self.client.json("POST", endpoint, payload, {"X-CSRF-Token": "bad"}, expected=(403,))[0], 403)
        self.assertEqual(self.client.json("POST", endpoint, {**payload, "action": "restart"}, {"X-CSRF-Token": csrf}, expected=(422,))[0], 422)
        self.assertEqual(self.client.json("POST", endpoint, {**payload, "uuid": "not-the-real-uuid"}, {"X-CSRF-Token": csrf}, expected=(404,))[0], 404)
        self.assertEqual(self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "install"}, {"X-CSRF-Token": csrf}, expected=(409,))[0], 409)
        db = sqlite3.connect(self.db)
        compatible = {"product": "rustdesk-yan", "edition": "custom", "targets": {"windows-x86_64-exe-custom": {}}}
        incompatible = {"product": "rustdesk-yan", "edition": "custom", "targets": {"linux-x86_64-appimage-custom": {}}}
        for current_id, current_uuid in ((device_id, device_uuid), (other_id, other_uuid)):
            db.execute("INSERT INTO device_deployments(id,uuid,pk,uid,payload,updated_at) VALUES(?,?,?,?,?,?)", (current_id, current_uuid, device_public_key(), 1, "{}", int(time.time())))
        db.execute("INSERT OR REPLACE INTO update_releases(version,build_seq,channel,manifest,published_at,active) VALUES(?,?,?,?,?,1)", ("0.0.1", 7, "stable", json.dumps(compatible), int(time.time())))
        db.execute("INSERT OR REPLACE INTO update_releases(version,build_seq,channel,manifest,published_at,active) VALUES(?,?,?,?,?,1)", ("0.0.2", 8, "stable", json.dumps(incompatible), int(time.time())))
        db.commit(); db.close()

        _, created, _ = self.client.json("POST", endpoint, payload, {"X-CSRF-Token": csrf}, expected=(201,))
        self.assertRegex(created["command_id"], r"^[0-9a-f]{32}$")
        self.assertEqual((created["action"], created["status"], created["target_version"], int(created["target_build_seq"])), ("install", "pending", "0.0.1", 7))
        db = sqlite3.connect(self.db)
        stored = db.execute("SELECT device_id,uuid,action,target_version,target_build_seq,status,created_at,expires_at FROM device_update_commands WHERE command_id=?", (created["command_id"],)).fetchone()
        self.assertEqual(stored[:6], (device_id, device_uuid, "install", "0.0.1", 7, "pending"))
        self.assertGreater(stored[7], stored[6])
        db.close()

        _, stream, ctype = self.client.request("GET", f"/?s=/rd/update/v1/policy/stream&client_id=RustDesk%20Yan&client_uuid={device_uuid}&after_revision=0", headers={"Accept": "text/event-stream", **device_auth_headers("GET", "/rd/update/v1/policy/stream", device_id, device_uuid)}, expected=(200,))
        self.assertIn("text/event-stream", ctype)
        self.assertIn("event: update-command\n", stream)
        self.assertIn(f"id: {created['command_id']}\n", stream)
        event = json.loads(next(line[6:] for line in stream.splitlines() if line.startswith("data: ")))
        self.assertEqual((event["command_id"], event["action"], event["client_id"], event["client_uuid"]), (created["command_id"], "install", device_id, device_uuid))
        _, replayed, _ = self.client.request("GET", f"/?s=/rd/update/v1/policy/stream&client_id={device_id}&client_uuid={device_uuid}&after_revision=0", headers={"Accept": "text/event-stream", "Last-Event-ID": created["command_id"], **device_auth_headers("GET", "/rd/update/v1/policy/stream", device_id, device_uuid)}, expected=(200,))
        self.assertIn(f"id: {created['command_id']}\n", replayed)

        _, other_stream, _ = self.client.request("GET", f"/?s=/rd/update/v1/policy/stream&client_id={other_id}&client_uuid={other_uuid}", headers={"Accept": "text/event-stream"}, expected=(200,))
        self.assertNotIn(created["command_id"], other_stream)
        _, latest, _ = self.client.json("POST", f"/?s=/ops-x9/api/update/commands/{other_id}", {"uuid": other_uuid, "action": "install"}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.assertEqual((latest["target_version"], int(latest["target_build_seq"])), ("0.0.1", 7))
        _, latest_stream, _ = self.client.request("GET", f"/?s=/rd/update/v1/policy/stream&client_id={other_id}&client_uuid={other_uuid}&after_revision=0", headers={"Accept": "text/event-stream", **device_auth_headers("GET", "/rd/update/v1/policy/stream", other_id, other_uuid)}, expected=(200,))
        latest_event = json.loads(next(line[6:] for line in latest_stream.splitlines() if line.startswith("data: ")))
        self.assertEqual((latest_event["target_version"], latest_event["target_build_seq"]), ("0.0.1", 7))

        accepted = {"client_id": "RustDesk Yan", "client_uuid": device_uuid, "command_id": created["command_id"], "command_action": "install", "status": "accepted"}
        self.client.json("POST", "/?s=/rd/update/v1/events", accepted, {"Content-Type": "application/json", **device_auth_headers("POST", "/rd/update/v1/events", device_id, device_uuid, accepted, created["command_id"])}, expected=(201,))
        wrong_action = {**accepted, "command_action": "check"}
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/events", wrong_action, {"Content-Type": "application/json", **device_auth_headers("POST", "/rd/update/v1/events", device_id, device_uuid, wrong_action, created["command_id"])}, expected=(422,))[0], 422)
        report = {"client_id": "RustDesk Yan", "client_uuid": device_uuid, "command_id": created["command_id"], "command_action": "install", "status": "completed", "to_version": "0.0.1", "to_build_seq": 7, "finished_at": int(time.time())}
        self.client.json("POST", "/?s=/rd/update/v1/events", report, {"Content-Type": "application/json", **device_auth_headers("POST", "/rd/update/v1/events", device_id, device_uuid, report, created["command_id"])}, expected=(201,))
        self.client.json("POST", "/?s=/rd/update/v1/events", report, {"Content-Type": "application/json", **device_auth_headers("POST", "/rd/update/v1/events", device_id, device_uuid, report, created["command_id"])}, expected=(201,))
        _, commands, _ = self.client.json("GET", f"{endpoint}?uuid={device_uuid}")
        self.assertEqual(commands["data"][0]["status"], "completed")
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT status FROM device_update_commands WHERE command_id=?", (created["command_id"],)).fetchone(), ("completed",))
        self.assertEqual(db.execute("SELECT COUNT(*) FROM device_update_events WHERE command_id=?", (created["command_id"],)).fetchone()[0], 2)
        db.execute("DELETE FROM update_releases WHERE build_seq IN (7,8) AND channel='stable'")
        db.commit()
        db.close()

    def test_one_shot_commands_resolve_latest_release_without_reported_package_kind(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"missing-kind-{suffix}", f"missing-kind-uuid-{suffix}"
        public_key = device_public_key()
        current_build = 2026100106
        target_build = 2026100201
        catalog_build = 2026100202
        current = {
            "client_id": device_id,
            "client_uuid": device_uuid,
            "product": "rustdesk-yan",
            "edition": "standard",
            "version": "1.5.0",
            "build_number": "20261001.6",
            "build_seq": current_build,
            "channel": "stable",
            "platform": "Windows",
            "arch": "x86_64",
            "install_mode": "installed",
        }
        target = {
            "version": "1.5.0",
            "build_number": "20261002.1",
            "build_seq": target_build,
            "source_commit": "a" * 40,
            "source_tag": "desktop-target",
            "primary": "https://download.yan.life/rustdesk/stable/current.exe",
            "mirrors": [],
            "size": 12,
            "sha256": "a" * 64,
            "signature": "c" * 88,
            "signature_key_id": "yan-release-2026",
        }
        manifest = {
            "schema": 2,
            "product": "rustdesk-yan",
            "edition": "multi",
            "version": "1.5.1",
            "build_number": "20261002.2",
            "build_seq": catalog_build,
            "catalog_revision": catalog_build,
            "channel": "stable",
            "targets": {
                "windows-x86_64-exe-standard": target,
                "windows-x86_64-msi-standard": {**target, "primary": "https://download.yan.life/rustdesk/stable/current.msi"},
            },
        }
        now = int(time.time())
        db = sqlite3.connect(self.db)
        db.execute("INSERT INTO device_deployments(id,uuid,pk,uid,payload,updated_at) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, public_key, 1, "{}", now))
        db.execute("INSERT INTO device_reports(id,uuid,payload,last_seen,last_heartbeat,heartbeat_payload) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, json.dumps(current), now, now, "{}"))
        db.execute("INSERT INTO update_releases(version,build_seq,channel,manifest,published_at,active) VALUES(?,?,?,?,?,1)", ("1.5.1", catalog_build, "stable", json.dumps(manifest), now))
        db.commit()
        db.close()

        endpoint = f"/?s=/ops-x9/api/update/commands/{device_id}"
        _, check_command, _ = self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "check"}, {"X-CSRF-Token": csrf}, expected=(201,))
        _, install_command, _ = self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "install"}, {"X-CSRF-Token": csrf}, expected=(201,))
        for command in (check_command, install_command):
            self.assertEqual((command["target_version"], int(command["target_build_seq"])), ("1.5.0", target_build))

        db = sqlite3.connect(self.db)
        stored = db.execute(
            "SELECT action,target_version,target_build_seq,status FROM device_update_commands WHERE command_id IN (?,?) ORDER BY action",
            (check_command["command_id"], install_command["command_id"]),
        ).fetchall()
        self.assertEqual(stored, [("check", "1.5.0", target_build, "pending"), ("install", "1.5.0", target_build, "pending")])
        db.close()

        request = {**current, "target_key": "windows-x86_64-exe-standard", "package_kind": "exe"}
        headers = {
            "Content-Type": "application/json",
            "X-RustDesk-Update-Command-ID": check_command["command_id"],
            **device_auth_headers("POST", "/rd/update/v1/check", device_id, device_uuid, request, check_command["command_id"]),
        }
        _, response, _ = self.client.json("POST", "/?s=/rd/update/v1/check", request, headers)
        self.assertTrue(response["update_available"])
        self.assertEqual((response["target_version"], response["target_build_seq"]), ("1.5.0", target_build))
        db = sqlite3.connect(self.db)
        db.execute("DELETE FROM update_releases WHERE version='1.5.1' AND build_seq=? AND channel='stable'", (catalog_build,))
        db.commit()
        db.close()

    def test_inherited_target_tie_prefers_latest_catalog_snapshot(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"catalog-tie-{suffix}", f"catalog-tie-uuid-{suffix}"
        target_version, target_build = "1.5.0", 2026100107
        current = {
            "client_id": device_id,
            "client_uuid": device_uuid,
            "product": "rustdesk-yan",
            "edition": "standard",
            "version": "1.5.0",
            "build_seq": 2026100106,
            "channel": "stable",
            "platform": "windows",
            "arch": "x86_64",
            "package_kind": "exe",
            "target_key": "windows-x86_64-exe-standard",
        }
        now = int(time.time())
        rows = []
        for catalog_build, tag in ((2026100203, "older-catalog"), (2026100204, "newer-catalog")):
            target = {
                "version": target_version,
                "build_number": "20261001.7",
                "build_seq": target_build,
                "source_commit": "d" * 40,
                "source_tag": tag,
                "primary": f"https://download.yan.life/rustdesk/stable/{tag}/windows.exe",
                "mirrors": [],
                "size": 12,
                "sha256": "b" * 64,
                "signature": __import__("base64").b64encode(b"t" * 64).decode(),
                "signature_key_id": "yan-release-2026",
            }
            manifest = {
                "schema": 2,
                "catalog_revision": catalog_build,
                "product": "rustdesk-yan",
                "edition": "multi",
                "version": "1.5.1",
                "build_number": str(catalog_build),
                "build_seq": catalog_build,
                "channel": "stable",
                "targets": {"windows-x86_64-exe-standard": target},
            }
            rows.append(("1.5.1", catalog_build, "stable", json.dumps(manifest), now + catalog_build, 1))
        db = sqlite3.connect(self.db)
        db.execute("INSERT INTO device_deployments(id,uuid,pk,uid,payload,updated_at) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, device_public_key(), 1, "{}", now))
        db.execute("INSERT INTO device_reports(id,uuid,payload,last_seen,last_heartbeat,heartbeat_payload) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, json.dumps(current), now, now, "{}"))
        db.executemany("INSERT INTO update_releases(version,build_seq,channel,manifest,published_at,active) VALUES(?,?,?,?,?,?)", rows)
        db.commit()
        db.close()

        _, response, _ = self.client.json("POST", "/?s=/rd/update/v1/check", current)
        self.assertEqual(response["manifest"]["source_tag"], "newer-catalog")
        self.assertIn("/newer-catalog/", response["manifest"]["targets"][current["target_key"]]["primary"])

        endpoint = f"/?s=/ops-x9/api/update/commands/{device_id}"
        _, command, _ = self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "install", "target_version": target_version, "target_build_seq": target_build}, {"X-CSRF-Token": csrf}, expected=(201,))
        headers = {
            "Content-Type": "application/json",
            "X-RustDesk-Update-Command-ID": command["command_id"],
            **device_auth_headers("POST", "/rd/update/v1/check", device_id, device_uuid, current, command["command_id"]),
        }
        _, command_response, _ = self.client.json("POST", "/?s=/rd/update/v1/check", current, headers)
        self.assertEqual(command_response["manifest"]["source_tag"], "newer-catalog")

        db = sqlite3.connect(self.db)
        db.execute("DELETE FROM device_update_commands WHERE device_id=? AND uuid=?", (device_id, device_uuid))
        db.execute("DELETE FROM device_reports WHERE id=? AND uuid=?", (device_id, device_uuid))
        db.execute("DELETE FROM device_deployments WHERE id=? AND uuid=?", (device_id, device_uuid))
        db.execute("DELETE FROM update_releases WHERE build_seq IN (?,?)", (2026100203, 2026100204))
        db.commit()
        db.close()

    def test_unresolved_check_command_uses_signed_client_target_without_guessing_install_package(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"unresolved-{suffix}", f"unresolved-uuid-{suffix}"
        public_key = device_public_key()
        target_build = 2026100202
        target = {
            "primary": "https://download.yan.life/rustdesk/stable/unresolved.exe",
            "mirrors": [],
            "size": 12,
            "sha256": "a" * 64,
            "signature": "c" * 88,
            "signature_key_id": "yan-release-2026",
        }
        manifest = {
            "product": "rustdesk-yan",
            "edition": "multi",
            "version": "1.5.0",
            "build_seq": target_build,
            "channel": "stable",
            "targets": {"windows-x86_64-exe-standard": target},
        }
        now = int(time.time())
        db = sqlite3.connect(self.db)
        db.execute("INSERT INTO device_deployments(id,uuid,pk,uid,payload,updated_at) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, public_key, 1, "{}", now))
        db.execute("INSERT INTO device_reports(id,uuid,payload,last_seen,last_heartbeat,heartbeat_payload) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, json.dumps({"id": device_id, "uuid": device_uuid}), now, now, "{}"))
        db.execute("INSERT INTO update_releases(version,build_seq,channel,manifest,published_at,active) VALUES(?,?,?,?,?,1)", ("1.5.0", target_build, "stable", json.dumps(manifest), now))
        db.commit()
        db.close()

        endpoint = f"/?s=/ops-x9/api/update/commands/{device_id}"
        _, command, _ = self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "check"}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.assertIsNone(command["target_version"])
        self.assertIsNone(command["target_build_seq"])
        self.assertEqual(self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "install"}, {"X-CSRF-Token": csrf}, expected=(409,))[0], 409)

        request = {
            "client_id": device_id,
            "client_uuid": device_uuid,
            "product": "rustdesk-yan",
            "edition": "standard",
            "version": "1.5.0",
            "build_seq": 2026100106,
            "channel": "stable",
            "platform": "windows",
            "arch": "x86_64",
            "target_key": "windows-x86_64-exe-standard",
            "package_kind": "exe",
        }
        unsigned = {**request, "target_key": "windows-x86_64-msi-standard", "package_kind": "msi"}
        self.client.json("POST", "/?s=/rd/update/v1/check", unsigned)
        db = sqlite3.connect(self.db)
        unsigned_payload = json.loads(db.execute("SELECT payload FROM device_reports WHERE id=? AND uuid=?", (device_id, device_uuid)).fetchone()[0])
        db.close()
        self.assertNotIn("target_key", unsigned_payload)
        self.assertNotIn("package_kind", unsigned_payload)
        headers = {
            "Content-Type": "application/json",
            "X-RustDesk-Update-Command-ID": command["command_id"],
            **device_auth_headers("POST", "/rd/update/v1/check", device_id, device_uuid, request, command["command_id"]),
        }
        _, response, _ = self.client.json("POST", "/?s=/rd/update/v1/check", request, headers)
        self.assertTrue(response["update_available"])
        self.assertEqual((response["target_version"], response["target_build_seq"]), ("1.5.0", target_build))
        db = sqlite3.connect(self.db)
        remembered = json.loads(db.execute("SELECT payload FROM device_reports WHERE id=? AND uuid=?", (device_id, device_uuid)).fetchone()[0])
        db.close()
        self.assertEqual((remembered["target_key"], remembered["package_kind"]), ("windows-x86_64-exe-standard", "exe"))
        _, install_command, _ = self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "install"}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.assertEqual((install_command["target_version"], install_command["target_build_seq"]), ("1.5.0", target_build))
        db = sqlite3.connect(self.db)
        db.execute("DELETE FROM update_releases WHERE version='1.5.0' AND build_seq=? AND channel='stable'", (target_build,))
        db.commit()
        db.close()

    def test_unknown_package_kind_does_not_lock_single_kind_release(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"single-kind-{suffix}", f"single-kind-uuid-{suffix}"
        product = f"single-kind-product-{suffix}"
        target_build = 2026100203
        current = {
            "client_id": device_id, "client_uuid": device_uuid, "product": product,
            "edition": "standard", "version": "1.5.0", "build_seq": 2026100106,
            "channel": "stable", "platform": "Windows", "arch": "x86_64",
        }
        target = {
            "primary": "https://download.yan.life/rustdesk/stable/single-kind.msi", "mirrors": [], "size": 12,
            "sha256": "a" * 64, "signature": "c" * 88, "signature_key_id": "yan-release-2026",
        }
        manifest = {
            "product": product, "edition": "multi", "version": "1.5.0", "build_seq": target_build,
            "channel": "stable", "targets": {"windows-x86_64-msi-standard": target},
        }
        now = int(time.time())
        db = sqlite3.connect(self.db)
        db.execute("INSERT INTO device_deployments(id,uuid,pk,uid,payload,updated_at) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, device_public_key(), 1, "{}", now))
        db.execute("INSERT INTO device_reports(id,uuid,payload,last_seen,last_heartbeat,heartbeat_payload) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, json.dumps(current), now, now, "{}"))
        db.execute("INSERT INTO update_releases(version,build_seq,channel,manifest,published_at,active) VALUES(?,?,?,?,?,1)", ("1.5.0", target_build, "stable", json.dumps(manifest), now))
        db.commit()
        db.close()

        endpoint = f"/?s=/ops-x9/api/update/commands/{device_id}"
        _, check_command, _ = self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "check"}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.assertIsNone(check_command["target_version"])
        self.assertIsNone(check_command["target_build_seq"])
        self.assertEqual(self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "install"}, {"X-CSRF-Token": csrf}, expected=(409,))[0], 409)

        db = sqlite3.connect(self.db)
        db.execute("DELETE FROM update_releases WHERE version='1.5.0' AND build_seq=? AND channel='stable'", (target_build,))
        db.commit()
        db.close()

    def test_update_command_auth_target_lock_monotonic_state_and_stream_fairness(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"secure-command-{suffix}", f"secure-command-uuid-{suffix}"
        public_key = device_public_key()
        check_payload = {
            "client_id": "RustDesk Yan", "client_uuid": device_uuid, "product": "rustdesk-yan",
            "edition": "custom", "version": "1.0.0", "build_seq": 1, "channel": "stable",
            "platform": "windows", "arch": "x86_64", "package_kind": "exe",
        }
        target = {"primary": "https://download.yan.life/rustdesk/stable/locked.exe", "mirrors": [], "size": 12, "sha256": "a" * 64, "signature": "c" * 88, "signature_key_id": "yan-release-2026"}
        newer = {**target, "primary": "https://download.yan.life/rustdesk/stable/newer.exe"}
        db = sqlite3.connect(self.db)
        db.execute("INSERT INTO device_deployments(id,uuid,pk,uid,payload,updated_at) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, public_key, 1, "{}", int(time.time())))
        db.execute("INSERT INTO device_reports(id,uuid,payload,last_seen,last_heartbeat,heartbeat_payload) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, json.dumps(check_payload), int(time.time()), int(time.time()), "{}"))
        db.execute("INSERT INTO update_releases(version,build_seq,channel,manifest,published_at,active) VALUES(?,?,?,?,?,1)", ("2.0.0", 20, "stable", json.dumps({"product": "rustdesk-yan", "edition": "custom", "version": "2.0.0", "build_seq": 20, "channel": "stable", "targets": {"windows-x86_64-exe-custom": target}}), int(time.time())))
        db.commit(); db.close()
        endpoint = f"/?s=/ops-x9/api/update/commands/{device_id}"
        _, first, _ = self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "install"}, {"X-CSRF-Token": csrf}, expected=(201,))
        _, second, _ = self.client.json("POST", endpoint, {"uuid": device_uuid, "action": "check"}, {"X-CSRF-Token": csrf}, expected=(201,))

        stream_path = f"/?s=/rd/update/v1/policy/stream&client_id=RustDesk%20Yan&client_uuid={device_uuid}"
        _, unsigned_stream, _ = self.client.request("GET", stream_path, headers={"Accept": "text/event-stream"}, expected=(200,))
        self.assertIn("event: update-policy\n", unsigned_stream)
        self.assertNotIn("event: update-command\n", unsigned_stream)
        stream_headers = {"Accept": "text/event-stream", **device_auth_headers("GET", "/rd/update/v1/policy/stream", device_id, device_uuid)}
        _, stream, _ = self.client.request("GET", stream_path, headers=stream_headers, expected=(200,))
        self.assertIn("event: update-policy\n", stream)
        self.assertIn(first["command_id"], stream)
        self.assertIn(second["command_id"], stream)

        db = sqlite3.connect(self.db)
        db.execute("INSERT INTO update_releases(version,build_seq,channel,manifest,published_at,active) VALUES(?,?,?,?,?,1)", ("3.0.0", 30, "stable", json.dumps({"product": "rustdesk-yan", "edition": "custom", "version": "3.0.0", "build_seq": 30, "channel": "stable", "targets": {"windows-x86_64-exe-custom": newer}}), int(time.time())))
        db.execute("INSERT INTO device_update_policies(id,uuid,mode,channel,target_version,target_build_seq,auto_install,enable_check_update,allow_auto_update,policy_revision,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id,uuid) DO UPDATE SET target_version=excluded.target_version,target_build_seq=excluded.target_build_seq", (device_id, device_uuid, "auto_install", "stable", "3.0.0", 30, 1, 1, 1, 7, int(time.time())))
        db.commit(); db.close()
        check_headers = {"Content-Type": "application/json", "X-RustDesk-Update-Command-ID": first["command_id"], **device_auth_headers("POST", "/rd/update/v1/check", device_id, device_uuid, check_payload, first["command_id"])}
        invalid_signature_headers = {**check_headers, "X-RustDesk-Device-Nonce": uuid.uuid4().hex, "X-RustDesk-Device-Signature": "A" * 86 + "=="}
        self.client.json("POST", "/?s=/rd/update/v1/check", check_payload, invalid_signature_headers, expected=(401,))
        expired_headers = {"Content-Type": "application/json", "X-RustDesk-Update-Command-ID": first["command_id"], **device_auth_headers("POST", "/rd/update/v1/check", device_id, device_uuid, check_payload, first["command_id"], timestamp=int(time.time()) - 301)}
        self.client.json("POST", "/?s=/rd/update/v1/check", check_payload, expired_headers, expected=(401,))
        _, ordinary, _ = self.client.json("POST", "/?s=/rd/update/v1/check", check_payload)
        self.assertEqual((ordinary["target_version"], ordinary["target_build_seq"]), ("3.0.0", 30))
        _, locked, _ = self.client.json("POST", "/?s=/rd/update/v1/check", check_payload, check_headers)
        self.assertEqual((locked["target_version"], locked["target_build_seq"]), ("2.0.0", 20))
        self.assertEqual(locked["manifest"]["targets"]["windows-x86_64-exe-custom"]["primary"], target["primary"])
        self.assertEqual(self.client.json("POST", "/?s=/rd/update/v1/check", check_payload, check_headers, expected=(409,))[0], 409)

        completed = {"client_id": "RustDesk Yan", "client_uuid": device_uuid, "command_id": first["command_id"], "command_action": "install", "status": "completed"}
        completed_headers = {"Content-Type": "application/json", **device_auth_headers("POST", "/rd/update/v1/events", device_id, device_uuid, completed, first["command_id"])}
        self.client.json("POST", "/?s=/rd/update/v1/events", completed, completed_headers, expected=(201,))
        late = {**completed, "status": "started"}
        late_headers = {"Content-Type": "application/json", **device_auth_headers("POST", "/rd/update/v1/events", device_id, device_uuid, late, first["command_id"])}
        self.client.json("POST", "/?s=/rd/update/v1/events", late, late_headers, expected=(201,))
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT status FROM device_update_commands WHERE command_id=?", (first["command_id"],)).fetchone(), ("completed",))
        db.execute("UPDATE device_update_commands SET status='accepted',expires_at=? WHERE command_id=?", (int(time.time()) - 1, second["command_id"]))
        db.commit(); db.close()
        accepted_headers = {"Content-Type": "application/json", "X-RustDesk-Update-Command-ID": second["command_id"], **device_auth_headers("POST", "/rd/update/v1/check", device_id, device_uuid, check_payload, second["command_id"])}
        self.client.json("POST", "/?s=/rd/update/v1/check", check_payload, accepted_headers, expected=(200,))
        fresh_stream_headers = {"Accept": "text/event-stream", **device_auth_headers("GET", "/rd/update/v1/policy/stream", device_id, device_uuid)}
        self.client.request("GET", stream_path, headers=fresh_stream_headers, expected=(200,))
        db = sqlite3.connect(self.db)
        db.execute("UPDATE device_update_commands SET status='deferred' WHERE command_id=?", (second["command_id"],))
        db.commit(); db.close()
        deferred_headers = {"Content-Type": "application/json", "X-RustDesk-Update-Command-ID": second["command_id"], **device_auth_headers("POST", "/rd/update/v1/check", device_id, device_uuid, check_payload, second["command_id"])}
        self.client.json("POST", "/?s=/rd/update/v1/check", check_payload, deferred_headers, expected=(200,))
        terminal_after_expiry = {"client_id": device_id, "client_uuid": device_uuid, "command_id": second["command_id"], "command_action": "check", "status": "completed"}
        self.client.json("POST", "/?s=/rd/update/v1/events", terminal_after_expiry, {"Content-Type": "application/json", **device_auth_headers("POST", "/rd/update/v1/events", device_id, device_uuid, terminal_after_expiry, second["command_id"])}, expected=(201,))
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT status FROM device_update_commands WHERE command_id=?", (second["command_id"],)).fetchone(), ("completed",))
        db.execute("DELETE FROM update_releases WHERE build_seq IN (20,30) AND channel='stable'")
        db.commit()
        db.close()

    def test_one_shot_update_command_reaches_waiting_sse_within_seconds(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"command-live-{suffix}", f"command-live-uuid-{suffix}"
        self.client.json("POST", "/?s=/api/heartbeat", {"id": device_id, "uuid": device_uuid, "conns": []})
        db = sqlite3.connect(self.db)
        db.execute("INSERT INTO device_deployments(id,uuid,pk,uid,payload,updated_at) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, device_public_key(), 1, "{}", int(time.time())))
        db.commit(); db.close()
        _, created, _ = self.client.json("POST", f"/?s=/ops-x9/api/update/commands/{device_id}", {"uuid": device_uuid, "action": "check"}, {"X-CSRF-Token": csrf}, expected=(201,))
        started = time.monotonic()
        _, stream, _ = self.client.request("GET", f"/?s=/rd/update/v1/policy/stream&client_id={device_id}&client_uuid={device_uuid}&after_revision=0", headers={"Accept": "text/event-stream", **device_auth_headers("GET", "/rd/update/v1/policy/stream", device_id, device_uuid)}, expected=(200,))
        self.assertLess(time.monotonic() - started, 1)
        self.assertIn("event: update-command\n", stream)
        self.assertIn(created["command_id"], stream)

    def test_stream_broker_auth_and_batch_snapshot_preserve_policy_command_semantics(self):
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"broker-{suffix}", f"broker-uuid-{suffix}"
        public_key = device_public_key()
        now = int(time.time())
        db = sqlite3.connect(self.db)
        db.execute("INSERT INTO device_deployments(id,uuid,pk,uid,payload,updated_at) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, public_key, 1, "{}", now))
        db.execute("INSERT INTO device_reports(id,uuid,payload,last_seen,last_heartbeat,heartbeat_payload) VALUES(?,?,?,?,?,?)", (device_id, device_uuid, "{}", now, now, "{}"))
        db.execute("INSERT INTO device_update_policies(id,uuid,mode,channel,target_version,target_build_seq,auto_install,enable_check_update,allow_auto_update,enable_scheduled_update,scheduled_update_interval_hours,policy_revision,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)", (device_id, device_uuid, "notify", "stable", None, None, 0, 1, 0, 1, 12, 4, now))
        pending_id, expired_id = uuid.uuid4().hex, uuid.uuid4().hex
        command_values = (device_id, device_uuid, "check", "stable", None, None, "pending", None, now, now + 60, now, 1)
        db.execute("INSERT INTO device_update_commands(command_id,device_id,uuid,action,channel,target_version,target_build_seq,status,last_error,created_at,expires_at,updated_at,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)", (pending_id, *command_values))
        expired_values = (device_id, device_uuid, "check", "stable", None, None, "pending", None, now - 120, now - 1, now - 120, 1)
        db.execute("INSERT INTO device_update_commands(command_id,device_id,uuid,action,channel,target_version,target_build_seq,status,last_error,created_at,expires_at,updated_at,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)", (expired_id, *expired_values))
        db.commit()
        db.close()

        auth_path = f"/?s=/_rustdesk-stream-internal/auth&client_id={device_id}&client_uuid={device_uuid}&after_revision=3"
        auth_request = urllib.request.Request(
            self.url + auth_path,
            headers={"Accept": "text/event-stream", **device_auth_headers("GET", "/rd/update/v1/policy/stream", device_id, device_uuid)},
        )
        with urllib.request.urlopen(auth_request, timeout=3) as response:
            self.assertEqual(response.status, 200)
            auth = json.loads(response.read())
            self.assertEqual(auth, {"client_id": device_id, "client_uuid": device_uuid, "authenticated": True})

        _, snapshot, _ = self.client.json("POST", "/?s=/_rustdesk-stream-internal/snapshot", {
            "streams": [
                {"connection_id": "authenticated", "client_id": device_id, "client_uuid": device_uuid, "after_revision": 3, "authenticated": True, "channel": "stable"},
                {"connection_id": "unsigned", "client_id": device_id, "client_uuid": device_uuid, "after_revision": 3, "authenticated": False, "channel": "stable"},
                {"connection_id": "current", "client_id": device_id, "client_uuid": device_uuid, "after_revision": 4, "authenticated": False, "channel": "stable"},
            ]
        })
        authenticated = snapshot["streams"]["authenticated"]
        unsigned = snapshot["streams"]["unsigned"]
        self.assertEqual([event["type"] for event in authenticated], ["update-policy", "update-command"])
        self.assertEqual(authenticated[0]["id"], "4")
        self.assertEqual(authenticated[0]["data"]["scheduled_update_interval_hours"], 12)
        self.assertEqual(authenticated[1]["id"], pending_id)
        self.assertEqual([event["type"] for event in unsigned], ["update-policy"])
        self.assertEqual(snapshot["streams"]["current"], [])
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT status FROM device_update_commands WHERE command_id=?", (expired_id,)).fetchone(), ("expired",))
        self.assertEqual(db.execute("SELECT COUNT(*) FROM device_update_nonces WHERE device_id=? AND uuid=?", (device_id, device_uuid)).fetchone()[0], 1)
        db.close()

    def test_heartbeat_client_enrolls_update_key_and_receives_commands(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"command-tofu-{suffix}", f"command-tofu-uuid-{suffix}"
        self.client.json("POST", "/?s=/api/heartbeat", {"id": device_id, "uuid": device_uuid, "conns": []})
        _, created, _ = self.client.json("POST", f"/?s=/ops-x9/api/update/commands/{device_id}", {
            "uuid": device_uuid, "action": "check",
        }, {"X-CSRF-Token": csrf}, expected=(201,))

        stream_path = f"/?s=/rd/update/v1/policy/stream&client_id={device_id}&client_uuid={device_uuid}&after_revision=0"
        headers = {"Accept": "text/event-stream", **device_auth_headers("GET", "/rd/update/v1/policy/stream", device_id, device_uuid)}
        _, stream, _ = self.client.request("GET", stream_path, headers=headers, expected=(200,))
        self.assertIn("event: update-command\n", stream)
        self.assertIn(created["command_id"], stream)

        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute(
            "SELECT device_id,uuid,public_key,first_seen_at,last_seen_at FROM device_update_keys WHERE device_id=? AND uuid=?",
            (device_id, device_uuid),
        ).fetchone()[:3], (device_id, device_uuid, device_public_key()))
        self.assertEqual(db.execute("SELECT COUNT(*) FROM device_deployments WHERE id=? AND uuid=?", (device_id, device_uuid)).fetchone()[0], 0)
        db.close()

        other_seed = "22" * 32
        rejected = {"Accept": "text/event-stream", **device_auth_headers("GET", "/rd/update/v1/policy/stream", device_id, device_uuid, seed_hex=other_seed)}
        self.assertEqual(self.client.request("GET", stream_path, headers=rejected, expected=(401,))[0], 401)

        self.client.json("DELETE", f"/?s=/ops-x9/api/devices/{device_id}?uuid={device_uuid}", {}, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute(
            "SELECT COUNT(*) FROM device_update_keys WHERE device_id=? AND uuid=?", (device_id, device_uuid)
        ).fetchone()[0], 0)
        db.close()

    def test_stale_heartbeat_cannot_enroll_update_key(self):
        suffix = uuid.uuid4().hex[:10]
        device_id, device_uuid = f"command-stale-{suffix}", f"command-stale-uuid-{suffix}"
        self.client.json("POST", "/?s=/api/heartbeat", {"id": device_id, "uuid": device_uuid, "conns": []})
        db = sqlite3.connect(self.db)
        db.execute("UPDATE device_reports SET last_seen=?,last_heartbeat=? WHERE id=? AND uuid=?", (int(time.time()) - 91, int(time.time()) - 91, device_id, device_uuid))
        db.commit(); db.close()
        stream_path = f"/?s=/rd/update/v1/policy/stream&client_id={device_id}&client_uuid={device_uuid}"
        headers = {"Accept": "text/event-stream", **device_auth_headers("GET", "/rd/update/v1/policy/stream", device_id, device_uuid)}
        self.assertEqual(self.client.request("GET", stream_path, headers=headers, expected=(401,))[0], 401)

    def test_06_admin_session_csrf_login_and_user_lifecycle(self):
        status, session, _ = self.client.json("GET", "/?s=/ops-x9/api/session")
        self.assertEqual(status, 200)
        csrf = session.get("csrf")
        self.assertTrue(csrf)
        status, login, _ = self.client.json("POST", "/?s=/ops-x9/api/login", {"username": "admin", "password": "admin123"}, {"X-CSRF-Token": csrf}, expected=(200,))
        self.assertEqual(status, 200)
        csrf = login["csrf"]
        _, listing, _ = self.client.json("GET", "/?s=/ops-x9/api/users&page=1&pageSize=100")
        names = {u.get("username") for u in listing.get("data", listing.get("items", []))}
        self.assertIn("legacy", names)
        status, _, _ = self.client.json("POST", "/?s=/ops-x9/api/users", {"username": "managed", "password": "managed123"}, expected=(403,))
        self.assertEqual(status, 403)
        _, created, _ = self.client.json("POST", "/?s=/ops-x9/api/users", {"username": "managed", "password": "1", "enabled": True}, {"X-CSRF-Token": csrf}, expected=(200, 201))
        user_id = created.get("id") or created.get("data", {}).get("id")
        self.assertTrue(user_id)
        status, _, _ = self.client.json("POST", "/?s=/ops-x9/api/users", {"username": "managed", "password": "managed123"}, {"X-CSRF-Token": csrf}, expected=(409,))
        self.assertEqual(status, 409)
        self.client.json("PATCH", f"/?s=/ops-x9/api/users/{user_id}", {"username": "managed-renamed", "enabled": False}, {"X-CSRF-Token": csrf})
        status, _, _ = self.client.json("POST", "/?s=/api/login", {"username": "managed-renamed", "password": "1", "id": "managed-id", "uuid": "managed-uuid"}, expected=(401,))
        self.assertEqual(status, 401)
        self.client.json("PATCH", f"/?s=/ops-x9/api/users/{user_id}", {"enabled": True}, {"X-CSRF-Token": csrf})
        _, managed_login, _ = self.client.json("POST", "/?s=/api/login", {"username": "managed-renamed", "password": "1", "id": "managed-id", "uuid": "managed-uuid"})
        managed_auth = {"Authorization": "Bearer " + managed_login["access_token"]}
        self.client.json("PATCH", f"/?s=/ops-x9/api/users/{user_id}", {"password": "managed456"}, {"X-CSRF-Token": csrf})
        self.client.json("POST", "/?s=/api/currentUser", {"id": "managed-id", "uuid": "managed-uuid"}, managed_auth, expected=(401,))
        self.client.json("DELETE", f"/?s=/ops-x9/api/users/{user_id}", None, {"X-CSRF-Token": csrf}, expected=(200, 204))

    def test_07_admin_password_revokes_session_and_client_tokens(self):
        status, session, _ = self.client.json("GET", "/?s=/ops-x9/api/session")
        self.assertEqual(status, 200)
        csrf = session["csrf"]
        _, login, _ = self.client.json("POST", "/?s=/ops-x9/api/login", {"username": "admin", "password": "admin123"}, {"X-CSRF-Token": csrf})
        csrf = login["csrf"]
        _, client_login, _ = self.client.json("POST", "/?s=/api/login", {"username": "admin", "password": "admin123", "id": "admin-id", "uuid": "admin-uuid"})
        client_auth = {"Authorization": "Bearer " + client_login["access_token"]}
        self.client.json("PATCH", "/?s=/ops-x9/api/me/password", {"old_password": "admin123", "password": "admin456"}, {"X-CSRF-Token": csrf})
        _, after, _ = self.client.json("GET", "/?s=/ops-x9/api/session")
        self.assertIsNone(after.get("user"))
        self.client.json("POST", "/?s=/api/currentUser", {"id": "admin-id", "uuid": "admin-uuid"}, client_auth, expected=(401,))

    def test_08_soft_delete_keeps_peer_rows(self):
        status, session, _ = self.client.json("GET", "/?s=/ops-x9/api/session")
        self.assertEqual(status, 200)
        csrf = session["csrf"]
        _, login, _ = self.client.json("POST", "/?s=/ops-x9/api/login", {"username": "admin", "password": "admin456"}, {"X-CSRF-Token": csrf})
        csrf = login["csrf"]
        _, created, _ = self.client.json("POST", "/?s=/ops-x9/api/users", {"username": "keep-peer", "password": "keeppeer123"}, {"X-CSRF-Token": csrf}, expected=(200, 201))
        user_id = int(created["id"])
        db = sqlite3.connect(self.db)
        db.execute("INSERT INTO rustdesk_peers(uid,id,username,hostname,alias,platform,tags,hash) VALUES (?,?,?,?,?,?,?,?)", (user_id, "keep-id", "u", "h", "a", "linux", "", ""))
        db.commit()
        self.client.json("DELETE", f"/?s=/ops-x9/api/users/{user_id}", None, {"X-CSRF-Token": csrf}, expected=(200, 204))
        self.assertEqual(db.execute("SELECT COUNT(*) FROM rustdesk_peers WHERE uid=?", (user_id,)).fetchone()[0], 1)
        db.close()

    def test_09_audit_big_nonce_is_idempotent(self):
        nonce = "922337203685477580812345678901234567890"
        payload = {"id": "audit-id", "uuid": "audit-uuid", "nonce": nonce, "note": "first"}
        self.client.json("POST", "/?s=/api/audit/conn", payload)
        self.client.json("POST", "/?s=/api/audit/conn", {**payload, "note": "duplicate"})
        db = sqlite3.connect(self.db)
        count = db.execute("SELECT COUNT(*) FROM audit_events WHERE device_id=? AND uuid=? AND nonce=?", ("audit-id", "audit-uuid", nonce)).fetchone()[0]
        db.close()
        self.assertEqual(count, 1)

    def test_10_current_client_api_surface(self):
        auth = self.rust_login()
        # New RustDesk clients probe these endpoints before selecting the address-book mode.
        _, settings, _ = self.client.json("POST", "/?s=/api/ab/settings", {}, auth)
        self.assertIn("max_peer_one_ab", settings)
        _, personal, _ = self.client.json("POST", "/?s=/api/ab/personal", {}, auth)
        guid = personal.get("guid")
        self.assertRegex(guid or "", r"^[0-9a-f-]{36}$")
        _, profiles, _ = self.client.json("POST", "/?s=/api/ab/shared/profiles&current=1&pageSize=100", {}, auth)
        self.assertIn("total", profiles)
        _, peers, _ = self.client.json("POST", f"/?s=/api/ab/peers&ab={guid}&current=1&pageSize=100", {}, auth)
        self.assertIn("data", peers)
        _, tags, _ = self.client.json("POST", f"/?s=/api/ab/tags/{guid}", {}, auth)
        self.assertIsInstance(tags, list)

        peer = {"id": "new-api-peer", "alias": "new", "tags": ["ops"], "note": "n"}
        self.client.json("POST", f"/?s=/api/ab/peer/add/{guid}", peer, auth)
        self.client.json("PUT", f"/?s=/api/ab/peer/update/{guid}", {"id": peer["id"], "alias": "renamed"}, auth)
        _, peers2, _ = self.client.json("POST", f"/?s=/api/ab/peers&ab={guid}&current=1&pageSize=100", {}, auth)
        self.assertEqual(peers2["data"][0]["alias"], "renamed")
        self.client.json("POST", f"/?s=/api/ab/tag/add/{guid}", {"name": "ops", "color": 123}, auth)
        self.client.json("PUT", f"/?s=/api/ab/tag/rename/{guid}", {"old": "ops", "new": "prod"}, auth)
        self.client.json("DELETE", f"/?s=/api/ab/peer/{guid}", [peer["id"]], auth)
        self.client.json("DELETE", f"/?s=/api/ab/tag/{guid}", ["prod"], auth)

        _, groups, _ = self.client.json("GET", "/?s=/api/device-group/accessible&current=1&pageSize=100", None, auth)
        self.assertIn("data", groups)
        _, version, ctype = self.client.request("POST", "/?s=/api/sysinfo_ver", {}, expected=(200,))
        self.assertTrue(ctype.startswith("text/plain"))
        self.assertTrue(version)

    def test_11_deploy_and_assign_contracts(self):
        auth = self.rust_login()
        headers = {**auth}
        _, deploy, _ = self.client.json("POST", "/?s=/api/devices/deploy", {"id": "deploy-id", "uuid": "deploy-uuid", "pk": "pk"}, headers)
        self.assertEqual(deploy.get("result"), "OK")
        status, body, _ = self.client.request("POST", "/?s=/api/devices/cli", {"id": "deploy-id", "uuid": "deploy-uuid", "device_name": "Managed"}, headers, expected=(200,))
        self.assertIn(status, (200, 201))

    def test_12_audit_note_and_switch_grant_are_idempotent(self):
        auth = self.rust_login()
        self.client.json("PUT", "/?s=/api/audit", {"guid": "g-1", "note": "hello"}, auth)
        self.client.json("POST", "/?s=/api/switch-grant", {"id": "id", "switch_code_verifier": "v", "timestamp": str(int(time.time())), "signature": "s"})

    def test_13_record_upload_and_optional_oidc_contract(self):
        status, body, _ = self.client.request("POST", "/?s=/api/record&op=new&filename=e2e.webm&id=session-1", b"frame-1", expected=(200,))
        self.assertTrue(body.get("ok"))
        status, body, _ = self.client.request("POST", "/?s=/api/record&op=part&filename=e2e.webm&id=session-1", b"frame-2", expected=(200,))
        self.assertTrue(body.get("ok"))
        self.client.json("POST", "/?s=/api/oidc/auth", {}, expected=(404,))
        self.client.json("GET", "/?s=/api/oidc/auth-query&code=x&id=i&uuid=u", None, expected=(404,))

    def test_14_audit_variants_and_method_guards(self):
        for endpoint in ("/api/audit/file", "/api/audit/alarm"):
            self.client.json("POST", f"/?s={endpoint}", {"id": "device", "uuid": "u", "nonce": "n-" + endpoint.rsplit('/', 1)[-1], "info": {"ok": True}})
        self.client.request("GET", "/?s=/api/login", expected=(405,))
        self.client.request("POST", "/?s=/api/login-options", {}, expected=(405,))

    def test_15_admin_client_management(self):
        csrf = self.admin_csrf()
        _, devices, _ = self.client.json("GET", "/?s=/ops-x9/api/devices&page=1&pageSize=100")
        self.assertTrue(any(d["id"] == "deploy-id" for d in devices["data"]))
        self.client.json("DELETE", "/?s=/ops-x9/api/devices/deploy-id", None, {"X-CSRF-Token": csrf})
        _, devices, _ = self.client.json("GET", "/?s=/ops-x9/api/devices&page=1&pageSize=100")
        self.assertFalse(any(d["id"] == "deploy-id" for d in devices["data"]))

    def test_16_custom_admin_path_is_the_only_admin_entry(self):
        for path in ("/", "/admin", "/admin/devices", "/unknown-page", "/?s=/admin/api/session"):
            status, body, ctype = self.client.request("GET", path, expected=(200,))
            self.assertEqual(status, 200)
            self.assertIn("text/html", ctype)
            self.assertIn("RustDesk API", body)
        _, login_page, ctype = self.client.request("GET", "/ops-x9", expected=(200,))
        self.assertIn("text/html", ctype)
        self.assertIn("RustDesk 用户管理", login_page)
        self.assertNotIn("__ADMIN_PATH__", login_page)
        _, devices_page, _ = self.client.request("GET", "/ops-x9/devices", expected=(200,))
        self.assertIn("/ops-x9/api/devices", devices_page)
        self.client.json("GET", "/api/not-found", expected=(404,))

    def test_17_heartbeat_inventory_and_address_book_alias_sync(self):
        device_id = "heartbeat-only"
        device_uuid = "heartbeat-uuid"
        _, heartbeat, _ = self.client.json("POST", "/?s=/api/heartbeat", {
            "id": device_id, "uuid": device_uuid, "ver": 7, "conns": [], "modified_at": 0,
        })
        self.assertTrue(heartbeat.get("sysinfo"))
        db = sqlite3.connect(self.db)
        row = db.execute("SELECT id,uuid,last_seen,last_heartbeat,heartbeat_payload FROM device_reports WHERE id=? AND uuid=?", (device_id, device_uuid)).fetchone()
        self.assertEqual(row[:2], (device_id, device_uuid))
        self.assertGreater(row[2], 0)
        self.assertGreater(row[3], 0)
        self.assertEqual(json.loads(row[4])["ver"], 7)
        db.close()

        self.client.request("POST", "/?s=/api/sysinfo", {
            "id": device_id, "uuid": device_uuid, "hostname": "heartbeat-host",
            "username": "operator", "os": "linux", "version": "1.4.6",
        }, expected=(200,))
        csrf = self.admin_csrf()
        _, devices, _ = self.client.json("GET", "/?s=/ops-x9/api/devices&q=heartbeat-host&page=1&pageSize=20")
        device = next(row for row in devices["data"] if row["id"] == device_id)
        self.assertEqual(device["presence"], "online")
        self.assertIsNone(device["alias"])
        self.assertFalse(device["deployed"])

        self.client.json("PATCH", f"/?s=/ops-x9/api/devices/{device_id}/alias", {"alias": "机房入口"}, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        profile_alias = db.execute("SELECT json_extract(p.payload,'$.alias') FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=1 AND a.personal=1 AND p.id=?", (device_id,)).fetchone()[0]
        legacy_alias = db.execute("SELECT alias FROM rustdesk_peers WHERE uid=1 AND id=?", (device_id,)).fetchone()[0]
        legacy_payload = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=1").fetchone()[0])
        db.close()
        self.assertEqual(profile_alias, "机房入口")
        self.assertEqual(legacy_alias, "机房入口")
        self.assertEqual(next(p["alias"] for p in legacy_payload["peers"] if p["id"] == device_id), "机房入口")

        auth = self.rust_admin_login("admin-book")
        _, personal, _ = self.client.json("POST", "/?s=/api/ab/personal", {}, auth)
        _, peers, _ = self.client.json("POST", f"/?s=/api/ab/peers&ab={personal['guid']}&current=1&pageSize=100", {}, auth)
        self.assertEqual(next(p["alias"] for p in peers["data"] if p["id"] == device_id), "机房入口")

    def test_18_presence_boundaries_summary_and_filters(self):
        runtime = Path(self.runtime or os.environ.get("FRANKENPHP", DEFAULT_RUNTIME))
        code = f'require {json.dumps(str(SQLITE_DIR / "lib.php"))}; echo json_encode([device_presence(80,100),device_presence(79,100),device_presence(10,100),device_presence(9,100),device_presence(0,100)]);'
        command = [str(runtime), "php-cli", "-r", code] if runtime.name == "frankenphp" else [str(runtime), "-r", code]
        exact = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, check=True, timeout=10)
        self.assertEqual(json.loads(exact.stdout), ["online", "recent", "recent", "offline", "unreported"])
        csrf = self.admin_csrf()
        fixtures = {
            "presence-online-20": 19,
            "presence-recent-low": 22,
            "presence-recent-90": 89,
            "presence-offline": 92,
        }
        for device_id in fixtures:
            self.client.json("POST", "/?s=/api/heartbeat", {
                "id": device_id, "uuid": device_id + "-uuid", "ver": 8, "conns": [],
            })
            self.client.request("POST", "/?s=/api/sysinfo", {
                "id": device_id, "uuid": device_id + "-uuid",
                "hostname": device_id + "-host", "username": "boundary",
                "os": "linux", "version": "1.4.6",
            }, expected=(200,))
        db = sqlite3.connect(self.db)
        now = int(time.time())
        for device_id, age in fixtures.items():
            db.execute("UPDATE device_reports SET last_heartbeat=? WHERE id=?", (now - age, device_id))
        db.commit()
        db.close()
        self.client.json("PATCH", "/?s=/ops-x9/api/devices/presence-online-20/alias", {"alias": "boundary-label"}, {"X-CSRF-Token": csrf})

        _, listing, _ = self.client.json("GET", "/?s=/ops-x9/api/devices?page=1&pageSize=200")
        indexed = {row["id"]: row for row in listing["data"]}
        self.assertEqual(indexed["presence-online-20"]["presence"], "online")
        self.assertEqual(indexed["presence-recent-low"]["presence"], "recent")
        self.assertEqual(indexed["presence-recent-90"]["presence"], "recent")
        self.assertEqual(indexed["presence-offline"]["presence"], "offline")
        self.assertEqual(set(listing["summary"]), {"total", "online", "recent", "offline", "unreported", "labelled"})
        self.assertEqual(listing["summary"]["total"], listing["total"])
        for presence in ("online", "recent", "offline", "unreported"):
            _, filtered, _ = self.client.json("GET", f"/?s=/ops-x9/api/devices?presence={presence}&page=1&pageSize=200")
            self.assertTrue(all(row["presence"] == presence for row in filtered["data"]))
        _, labelled, _ = self.client.json("GET", "/?s=/ops-x9/api/devices?labelled=1&page=1&pageSize=200")
        self.assertTrue(any(row["id"] == "presence-online-20" for row in labelled["data"]))
        self.assertTrue(all(isinstance(row.get("alias"), str) and row["alias"] != "" for row in labelled["data"]))

    def test_19_alias_requires_authentication_csrf_and_valid_input(self):
        endpoint = "/?s=/ops-x9/api/devices/heartbeat-only/alias"
        anonymous = HttpClient(self.url)
        anonymous.json("PATCH", endpoint, {"alias": "blocked"}, expected=(401,))
        self.client.json("POST", "/?s=/api/heartbeat", {
            "id": "heartbeat-only", "uuid": "heartbeat-uuid", "ver": 7, "conns": [],
        })
        self.client.request("POST", "/?s=/api/sysinfo", {
            "id": "heartbeat-only", "uuid": "heartbeat-uuid", "hostname": "heartbeat-host",
            "username": "operator", "os": "linux", "version": "1.4.6",
        }, expected=(200,))
        csrf = self.admin_csrf()
        self.client.json("PATCH", endpoint, {"alias": "blocked"}, expected=(403,))
        self.client.json("PATCH", endpoint, {"alias": ["invalid"]}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("PATCH", endpoint, {"alias": "x" * 256}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("PATCH", endpoint, {"alias": "before-clear"}, {"X-CSRF-Token": csrf})

        db = sqlite3.connect(self.db)
        profile = db.execute("SELECT p.guid,p.payload FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=1 AND a.personal=1 AND p.id='heartbeat-only'").fetchone()
        profile_payload = json.loads(profile[1])
        profile_payload["future_field"] = {"preserve": True}
        db.execute("UPDATE ab_profile_peers SET payload=? WHERE guid=? AND id='heartbeat-only'", (json.dumps(profile_payload, ensure_ascii=False), profile[0]))
        book = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=1").fetchone()[0])
        book["future_top_level"] = {"preserve": True}
        db.execute("UPDATE address_books SET payload=? WHERE uid=1", (json.dumps(book, ensure_ascii=False),))
        db.commit()
        db.close()

        _, result, _ = self.client.json("PATCH", endpoint, {"alias": ""}, {"X-CSRF-Token": csrf})
        self.assertEqual(result, {"ok": True, "alias": "", "sync": "next_address_book_pull"})
        db = sqlite3.connect(self.db)
        profile_payload = json.loads(db.execute("SELECT payload FROM ab_profile_peers WHERE guid=? AND id='heartbeat-only'", (profile[0],)).fetchone()[0])
        legacy_alias = db.execute("SELECT alias FROM rustdesk_peers WHERE uid=1 AND id='heartbeat-only'").fetchone()[0]
        book = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=1").fetchone()[0])
        db.close()
        self.assertEqual(profile_payload["alias"], "")
        self.assertEqual(profile_payload["future_field"], {"preserve": True})
        self.assertEqual(legacy_alias, "")
        self.assertEqual(book["future_top_level"], {"preserve": True})
        self.assertEqual(next(p["alias"] for p in book["peers"] if p["id"] == "heartbeat-only"), "")

        auth = self.rust_admin_login("admin-book-clear")
        _, personal, _ = self.client.json("POST", "/?s=/api/ab/personal", {}, auth)
        _, peers, _ = self.client.json("POST", f"/?s=/api/ab/peers&ab={personal['guid']}&current=1&pageSize=100", {}, auth)
        self.assertEqual(next(p["alias"] for p in peers["data"] if p["id"] == "heartbeat-only"), "")

    def test_20_admin_address_book_merges_scoped_stores_with_profile_priority(self):
        csrf = self.admin_csrf()
        admin_auth = self.rust_admin_login("admin-book-merge")
        self.client.json("POST", "/?s=/api/ab/personal", {}, admin_auth)
        db = sqlite3.connect(self.db)
        admin_profile = db.execute("SELECT guid FROM ab_profiles WHERE uid=1 AND personal=1").fetchone()[0]
        legacy_profile = db.execute("SELECT guid FROM ab_profiles WHERE uid=2 AND personal=1").fetchone()
        if legacy_profile is None:
            legacy_profile = str(uuid.uuid4())
            db.execute(
                "INSERT INTO ab_profiles(guid,uid,name,owner,note,rule,personal,created_at) VALUES (?,?,?,?,?,3,1,?)",
                (legacy_profile, 2, "legacy personal", "legacy", "", int(time.time())),
            )
        else:
            legacy_profile = legacy_profile[0]
        db.execute(
            "INSERT OR REPLACE INTO rustdesk_peers(uid,id,username,hostname,alias,platform,tags,hash) VALUES (1,'admin-legacy-only','u','legacy-host','legacy-only','linux','legacy-tag','h')"
        )
        db.execute(
            "INSERT OR REPLACE INTO rustdesk_peers(uid,id,username,hostname,alias,platform,tags,hash) VALUES (1,'admin-merged','legacy-user','legacy-host','legacy-alias','linux','legacy-tag','legacy-hash')"
        )
        db.execute(
            "INSERT OR REPLACE INTO rustdesk_peers(uid,id,username,hostname,alias,platform,tags,hash) VALUES (1,'83077683','numeric-user','numeric-host','numeric-id','linux','','numeric-hash')"
        )
        db.execute(
            "INSERT OR REPLACE INTO ab_profile_peers(guid,id,payload,updated_at) VALUES (?,?,?,?)",
            (admin_profile, "admin-profile-only", json.dumps({"id": "admin-profile-only", "alias": "profile-only", "tags": ["profile-tag"], "future": {"keep": 1}}), int(time.time())),
        )
        db.execute(
            "INSERT OR REPLACE INTO ab_profile_peers(guid,id,payload,updated_at) VALUES (?,?,?,?)",
            (admin_profile, "admin-merged", json.dumps({"id": "admin-merged", "alias": "profile-wins", "hostname": "profile-host", "tags": ["profile-tag"], "future": {"keep": 2}}), int(time.time())),
        )
        db.execute(
            "INSERT OR REPLACE INTO ab_profile_peers(guid,id,payload,updated_at) VALUES (?,?,?,?)",
            (legacy_profile, "other-user-only", json.dumps({"id": "other-user-only", "alias": "must-not-leak"}), int(time.time())),
        )
        db.commit()
        db.close()

        _, listing, _ = self.client.json("GET", "/?s=/ops-x9/api/address-book&page=1&pageSize=200")
        indexed = {peer["id"]: peer for peer in listing["data"]}
        self.assertIn("admin-legacy-only", indexed)
        self.assertIn("83077683", indexed)
        self.assertIsInstance(indexed["83077683"]["id"], str)
        self.assertIn("admin-profile-only", indexed)
        self.assertEqual(indexed["admin-merged"]["alias"], "profile-wins")
        self.assertEqual(indexed["admin-merged"]["hostname"], "profile-host")
        self.assertNotIn("other-user-only", indexed)
        self.assertEqual(set(listing["summary"]), {"total", "favorites", "labelled", "tags"})
        self.assertGreaterEqual(listing["summary"]["total"], 3)
        self.assertEqual(listing["summary"]["favorites"], 0)
        self.assertIn("profile-tag", {tag["name"] for tag in listing["tags"]})
        _, client_book, _ = self.client.json("GET", "/?s=/api/ab", None, admin_auth)
        client_peers = {peer["id"] for peer in json.loads(client_book["data"])["peers"]}
        self.assertIn("admin-profile-only", client_peers)
        db = sqlite3.connect(self.db)
        db.execute("INSERT OR REPLACE INTO device_reports(id,uuid,payload,last_seen,last_heartbeat,heartbeat_payload,runtime_payload,network_payload) VALUES (?,?,?,?,?,?,?,?)", ("admin-merged", "merge-uuid", json.dumps({"hostname":"live-host","platform":"windows","version":"1.5.0","distribution":"desktop"}), int(time.time()), int(time.time()), json.dumps({"ver":10}), "{}", json.dumps({"public_ip":"8.8.8.8"})))
        db.commit(); db.close()
        _, enriched, _ = self.client.json("GET", "/?s=/ops-x9/api/address-book?q=admin-merged&page=1&pageSize=20")
        self.assertEqual((enriched["data"][0]["version"], enriched["data"][0]["distribution"], enriched["data"][0]["public_ip"]), ("1.5.0", "desktop", "8.8.8.8"))

    def test_21_admin_address_book_peer_crud_syncs_three_stores_and_client_apis(self):
        csrf = self.admin_csrf()
        peer_id = "admin-book-crud"
        payload = {
            "id": peer_id,
            "alias": "入口机器",
            "hostname": "entry-host",
            "username": "operator",
            "platform": "linux",
            "tags": ["ops", "ops-prod"],
            "note": "future payload stays",
            "future_field": {"preserve": True},
        }
        self.client.json("POST", "/?s=/ops-x9/api/address-book/peers", payload, {"X-CSRF-Token": csrf}, expected=(201,))

        db = sqlite3.connect(self.db)
        profile_raw = db.execute(
            "SELECT p.payload FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=1 AND a.personal=1 AND p.id=?",
            (peer_id,),
        ).fetchone()[0]
        legacy_row = db.execute(
            "SELECT alias,hostname,username,platform,tags FROM rustdesk_peers WHERE uid=1 AND id=?", (peer_id,)
        ).fetchone()
        exact_book = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=1").fetchone()[0])
        self.assertEqual(json.loads(profile_raw)["future_field"], {"preserve": True})
        self.assertEqual(legacy_row, ("入口机器", "entry-host", "operator", "linux", "ops,ops-prod"))
        self.assertEqual(next(p for p in exact_book["peers"] if p["id"] == peer_id)["note"], "future payload stays")
        self.assertEqual(db.execute("SELECT COUNT(*) FROM device_reports WHERE id=?", (peer_id,)).fetchone()[0], 0)
        db.close()

        self.client.json(
            "PATCH", f"/?s=/ops-x9/api/address-book/peers/{peer_id}",
            {"alias": "入口机器-更新", "hostname": "entry-host-2"}, {"X-CSRF-Token": csrf},
        )
        admin_auth = self.rust_admin_login("admin-book-readback")
        _, legacy_book, _ = self.client.json("GET", "/?s=/api/ab", None, admin_auth)
        legacy_peer = next(p for p in json.loads(legacy_book["data"])["peers"] if p["id"] == peer_id)
        self.assertEqual((legacy_peer["alias"], legacy_peer["hostname"]), ("入口机器-更新", "entry-host-2"))
        self.assertEqual(legacy_peer["future_field"], {"preserve": True})
        _, personal, _ = self.client.json("POST", "/?s=/api/ab/personal", {}, admin_auth)
        _, current_peers, _ = self.client.json(
            "POST", f"/?s=/api/ab/peers&ab={personal['guid']}&current=1&pageSize=200", {}, admin_auth
        )
        current_peer = next(p for p in current_peers["data"] if p["id"] == peer_id)
        self.assertEqual(current_peer["alias"], "入口机器-更新")
        self.assertEqual(current_peer["future_field"], {"preserve": True})

        self.client.json("POST", "/?s=/api/heartbeat", {"id": peer_id, "uuid": "book-device-uuid", "ver": 10, "conns": []})
        self.client.json("DELETE", f"/?s=/ops-x9/api/address-book/peers/{peer_id}", None, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        counts = db.execute(
            "SELECT "
            "(SELECT COUNT(*) FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=1 AND a.personal=1 AND p.id=?),"
            "(SELECT COUNT(*) FROM rustdesk_peers WHERE uid=1 AND id=?),"
            "(SELECT COUNT(*) FROM device_reports WHERE id=?)",
            (peer_id, peer_id, peer_id),
        ).fetchone()
        deleted_book = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=1").fetchone()[0])
        db.close()
        self.assertEqual(counts, (0, 0, 1))
        self.assertFalse(any(p["id"] == peer_id for p in deleted_book["peers"]))

    def test_22_admin_address_book_tags_rename_exact_values_in_all_stores(self):
        csrf = self.admin_csrf()
        peer_id = "admin-tag-peer"
        self.client.json("POST", "/?s=/ops-x9/api/address-book/tags", {"name": "ops", "color": 4283215696}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("POST", "/?s=/ops-x9/api/address-book/tags", {"name": "ops-prod", "color": 4292030255}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json(
            "POST", "/?s=/ops-x9/api/address-book/peers",
            {"id": peer_id, "alias": "tag-peer", "tags": ["ops", "ops-prod"], "note": "ops must remain in free text"},
            {"X-CSRF-Token": csrf}, expected=(201,),
        )
        self.client.json(
            "PATCH", "/?s=/ops-x9/api/address-book/tags/ops",
            {"name": "core", "color": 4278255360}, {"X-CSRF-Token": csrf},
        )

        db = sqlite3.connect(self.db)
        profile_payload = json.loads(db.execute(
            "SELECT p.payload FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=1 AND a.personal=1 AND p.id=?",
            (peer_id,),
        ).fetchone()[0])
        legacy_tags = db.execute("SELECT tags FROM rustdesk_peers WHERE uid=1 AND id=?", (peer_id,)).fetchone()[0]
        exact_book = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=1").fetchone()[0])
        exact_peer = next(p for p in exact_book["peers"] if p["id"] == peer_id)
        tag_rows = db.execute(
            "SELECT name,color FROM ab_profile_tags WHERE guid=(SELECT guid FROM ab_profiles WHERE uid=1 AND personal=1) ORDER BY name"
        ).fetchall()
        db.close()
        self.assertEqual(set(profile_payload["tags"]), {"core", "ops-prod"})
        self.assertEqual(profile_payload["note"], "ops must remain in free text")
        self.assertEqual(set(legacy_tags.split(",")), {"core", "ops-prod"})
        self.assertEqual(set(exact_peer["tags"]), {"core", "ops-prod"})
        self.assertIn(("core", 4278255360), tag_rows)
        self.assertNotIn(("ops", 4283215696), tag_rows)

        self.client.json("DELETE", "/?s=/ops-x9/api/address-book/tags/core", None, {"X-CSRF-Token": csrf})
        _, listing, _ = self.client.json("GET", f"/?s=/ops-x9/api/address-book&q={peer_id}&page=1&pageSize=20")
        peer = next(row for row in listing["data"] if row["id"] == peer_id)
        self.assertEqual(peer["tags"], ["ops-prod"])
        self.assertEqual({tag["name"] for tag in listing["tags"]} & {"core", "ops-prod"}, {"ops-prod"})

    def test_23_admin_address_book_favorites_are_scoped_idempotent_and_deleted_with_peer(self):
        csrf = self.admin_csrf()
        peer_id = "admin-favorite-peer"
        self.client.json("POST", "/?s=/ops-x9/api/address-book/peers", {"id": peer_id, "alias": "favorite"}, {"X-CSRF-Token": csrf}, expected=(201,))
        endpoint = f"/?s=/ops-x9/api/address-book/favorites/{peer_id}"
        self.client.json("PATCH", endpoint, {"favorite": True}, {"X-CSRF-Token": csrf})
        self.client.json("PATCH", endpoint, {"favorite": True}, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        db.execute("INSERT OR IGNORE INTO admin_peer_favorites(uid,id,created_at) VALUES (2,?,?)", (peer_id, int(time.time())))
        db.commit()
        self.assertEqual(db.execute("SELECT COUNT(*) FROM admin_peer_favorites WHERE uid=1 AND id=?", (peer_id,)).fetchone()[0], 1)
        db.close()
        _, listing, _ = self.client.json("GET", f"/?s=/ops-x9/api/address-book?q={peer_id}&page=1&pageSize=20")
        self.assertTrue(next(row for row in listing["data"] if row["id"] == peer_id)["favorite"])
        self.assertEqual(listing["summary"]["favorites"], 1)

        self.client.json("PATCH", endpoint, {"favorite": False}, {"X-CSRF-Token": csrf})
        self.client.json("PATCH", endpoint, {"favorite": False}, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM admin_peer_favorites WHERE uid=1 AND id=?", (peer_id,)).fetchone()[0], 0)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM admin_peer_favorites WHERE uid=2 AND id=?", (peer_id,)).fetchone()[0], 1)
        db.close()

        self.client.json("PATCH", endpoint, {"favorite": True}, {"X-CSRF-Token": csrf})
        self.client.json("DELETE", f"/?s=/ops-x9/api/address-book/peers/{peer_id}", None, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM admin_peer_favorites WHERE uid=1 AND id=?", (peer_id,)).fetchone()[0], 0)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM admin_peer_favorites WHERE uid=2 AND id=?", (peer_id,)).fetchone()[0], 1)
        db.close()

    def test_24_admin_address_book_requires_session_csrf_and_valid_payloads(self):
        anonymous = HttpClient(self.url)
        anonymous.json("GET", "/?s=/ops-x9/api/address-book", expected=(401,))
        csrf = self.admin_csrf()
        self.client.json("POST", "/?s=/ops-x9/api/address-book/peers", {"id": "blocked"}, expected=(403,))
        self.client.json("POST", "/?s=/ops-x9/api/address-book/tags", {"name": "blocked"}, expected=(403,))
        self.client.json("PATCH", "/?s=/ops-x9/api/address-book/favorites/blocked", {"favorite": True}, expected=(403,))
        self.client.json("POST", "/?s=/ops-x9/api/address-book/peers", {"id": "", "alias": "bad"}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("POST", "/?s=/ops-x9/api/address-book/peers", {"id": "x" * 129}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("POST", "/?s=/ops-x9/api/address-book/tags", {"name": ""}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("PATCH", "/?s=/ops-x9/api/address-book/favorites/missing", {"favorite": "yes"}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("PATCH", "/?s=/ops-x9/api/address-book/favorites/missing", {"favorite": True}, {"X-CSRF-Token": csrf}, expected=(404,))

    def test_25_admin_address_book_paginates_beyond_two_hundred_peers(self):
        self.admin_csrf()
        admin_auth = self.rust_admin_login("admin-book-pages")
        self.client.json("POST", "/?s=/api/ab/personal", {}, admin_auth)
        db = sqlite3.connect(self.db)
        guid = db.execute("SELECT guid FROM ab_profiles WHERE uid=1 AND personal=1").fetchone()[0]
        now = int(time.time())
        for index in range(205):
            peer_id = f"admin-page-{index:03d}"
            payload = json.dumps({"id": peer_id, "alias": f"page-{index:03d}", "tags": []})
            db.execute(
                "INSERT OR REPLACE INTO ab_profile_peers(guid,id,payload,updated_at) VALUES (?,?,?,?)",
                (guid, peer_id, payload, now + index),
            )
        db.commit()
        db.close()
        _, first, _ = self.client.json("GET", "/?s=/ops-x9/api/address-book?q=admin-page-&page=1&pageSize=200")
        _, second, _ = self.client.json("GET", "/?s=/ops-x9/api/address-book?q=admin-page-&page=2&pageSize=200")
        self.assertEqual(first["total"], 205)
        self.assertEqual(len(first["data"]), 200)
        self.assertEqual(len(second["data"]), 5)
        self.assertEqual(len({row["id"] for row in first["data"] + second["data"]}), 205)

    def test_25b_cross_user_address_book_crud_batch_counts_and_scope(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:8]
        _, source_created, _ = self.client.json(
            "POST", "/?s=/ops-x9/api/users",
            {"username": f"book-source-{suffix}", "password": "1", "enabled": True, "address_book_scope": "self"},
            {"X-CSRF-Token": csrf}, expected=(201,),
        )
        _, target_created, _ = self.client.json(
            "POST", "/?s=/ops-x9/api/users",
            {"username": f"book-target-{suffix}", "password": "1", "enabled": True, "address_book_scope": "self"},
            {"X-CSRF-Token": csrf}, expected=(201,),
        )
        source_uid, target_uid = int(source_created["id"]), int(target_created["id"])
        self.client.json("PATCH", f"/?s=/ops-x9/api/users/{source_uid}",
            {"is_admin": True, "address_book_scope": "self"}, {"X-CSRF-Token": csrf})
        online_id, offline_id, fresh_id = f"book-online-{suffix}", f"book-offline-{suffix}", f"book-fresh-{suffix}"
        self.client.json("POST", "/?s=/api/heartbeat", {"id": online_id, "uuid": online_id + "-uuid", "conns": []})
        source_query = f"&user_id={source_uid}"

        self.client.json("POST", "/?s=/ops-x9/api/address-book/tags" + source_query,
            {"name": "old", "color": 4282668390}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("POST", "/?s=/ops-x9/api/address-book/tags" + source_query,
            {"name": "managed", "color": 4283215696}, {"X-CSRF-Token": csrf}, expected=(201,))

        self.client.json("POST", "/?s=/ops-x9/api/address-book/peers" + source_query,
            {"id": online_id, "alias": "在线入口", "tags": ["ops"], "note": "source", "future": {"keep": 1}},
            {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("POST", "/?s=/ops-x9/api/address-book/peers" + source_query,
            {"id": offline_id, "alias": "离线入口", "tags": ["old"], "future": {"keep": 2}},
            {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("POST", "/?s=/ops-x9/api/address-book/peers" + source_query,
            {"id": fresh_id, "alias": "新复制入口", "tags": ["fresh-tag"], "future": {"keep": 3}},
            {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("POST", f"/?s=/ops-x9/api/address-book/peers&user_id={target_uid}",
            {"id": online_id, "alias": "目标在线别名", "tags": ["target-online"], "note": "target online", "future": {"target": 1}},
            {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("POST", f"/?s=/ops-x9/api/address-book/peers&user_id={target_uid}",
            {"id": offline_id, "alias": "目标离线别名", "tags": ["target-offline"], "note": "target offline", "future": {"target": 2}},
            {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("PATCH", f"/?s=/ops-x9/api/address-book/favorites/{online_id}&user_id={target_uid}",
            {"favorite": True}, {"X-CSRF-Token": csrf})
        self.client.json("PATCH", f"/?s=/ops-x9/api/address-book/peers/{online_id}" + source_query,
            {"alias": "在线入口-更新"}, {"X-CSRF-Token": csrf})
        self.client.json("PATCH", f"/?s=/ops-x9/api/address-book/favorites/{online_id}" + source_query,
            {"favorite": True}, {"X-CSRF-Token": csrf})
        self.client.json("POST", "/?s=/ops-x9/api/address-book/tags" + source_query,
            {"name": "temp-tag", "color": 4283215696}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("PATCH", "/?s=/ops-x9/api/address-book/tags/temp-tag" + source_query,
            {"name": "renamed-tag", "color": 4292030255}, {"X-CSRF-Token": csrf})
        self.client.json("DELETE", "/?s=/ops-x9/api/address-book/tags/renamed-tag" + source_query,
            None, {"X-CSRF-Token": csrf})
        for payload in (
            {"action": "add_tags", "peer_ids": [online_id, offline_id], "tags": ["managed"]},
            {"action": "remove_tags", "peer_ids": [offline_id], "tags": ["old"]},
            {"action": "copy", "peer_ids": [online_id], "target_user_id": target_uid},
            {"action": "move", "peer_ids": [offline_id], "target_user_id": target_uid},
            {"action": "copy", "peer_ids": [fresh_id], "target_user_id": target_uid},
        ):
            self.client.json("POST", "/?s=/ops-x9/api/address-book/batch" + source_query, payload, {"X-CSRF-Token": csrf})

        _, source_listing, _ = self.client.json("GET", f"/?s=/ops-x9/api/address-book&user_id={source_uid}&page=1&pageSize=20")
        _, target_listing, _ = self.client.json("GET", f"/?s=/ops-x9/api/address-book&user_id={target_uid}&page=1&pageSize=20")
        self.assertEqual({row["id"] for row in source_listing["data"]}, {online_id, fresh_id})
        self.assertEqual({row["id"] for row in target_listing["data"]}, {online_id, offline_id, fresh_id})
        copied = next(row for row in target_listing["data"] if row["id"] == online_id)
        moved = next(row for row in target_listing["data"] if row["id"] == offline_id)
        self.assertEqual(copied["future"], {"target": 1})
        self.assertEqual(copied["alias"], "目标在线别名")
        self.assertEqual(copied["tags"], ["target-online"])
        self.assertTrue(copied["favorite"])
        self.assertEqual(moved["future"], {"target": 2})
        self.assertEqual(moved["alias"], "目标离线别名")
        self.assertEqual(moved["tags"], ["target-offline"])
        self.assertEqual(next(row for row in target_listing["data"] if row["id"] == fresh_id)["future"], {"keep": 3})

        _, users, _ = self.client.json("GET", "/?s=/ops-x9/api/users&q=book-&page=1&pageSize=50")
        indexed_users = {row["id"]: row for row in users["data"]}
        self.assertEqual((indexed_users[source_uid]["address_book_count"], indexed_users[source_uid]["address_book_online_count"]), (2, 1))
        self.assertEqual((indexed_users[target_uid]["address_book_count"], indexed_users[target_uid]["address_book_online_count"]), (3, 1))

        db = sqlite3.connect(self.db)
        source_guid = db.execute("SELECT guid FROM ab_profiles WHERE uid=? AND personal=1", (source_uid,)).fetchone()[0]
        target_guid = db.execute("SELECT guid FROM ab_profiles WHERE uid=? AND personal=1", (target_uid,)).fetchone()[0]
        source_profile = json.loads(db.execute("SELECT payload FROM ab_profile_peers WHERE guid=? AND id=?", (source_guid, online_id)).fetchone()[0])
        target_profile = json.loads(db.execute("SELECT payload FROM ab_profile_peers WHERE guid=? AND id=?", (target_guid, online_id)).fetchone()[0])
        source_book = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=?", (source_uid,)).fetchone()[0])
        target_book = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=?", (target_uid,)).fetchone()[0])
        self.assertEqual(source_profile["future"], {"keep": 1})
        self.assertEqual(target_profile["future"], {"target": 1})
        self.assertEqual(db.execute("SELECT alias,tags FROM rustdesk_peers WHERE uid=? AND id=?", (source_uid, online_id)).fetchone(), ("在线入口-更新", "ops,managed"))
        self.assertEqual({peer["id"] for peer in source_book["peers"]}, {online_id, fresh_id})
        self.assertEqual({peer["id"] for peer in target_book["peers"]}, {online_id, offline_id, fresh_id})
        self.assertEqual(db.execute("SELECT COUNT(*) FROM admin_peer_favorites WHERE uid=? AND id=?", (source_uid, online_id)).fetchone()[0], 1)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM admin_peer_favorites WHERE uid=? AND id=?", (target_uid, online_id)).fetchone()[0], 1)
        self.assertIn(("managed", 4283215696), set(db.execute("SELECT name,color FROM ab_profile_tags WHERE guid=?", (source_guid,))))
        self.assertIn("managed", {row[0] for row in db.execute("SELECT tag FROM rustdesk_tags WHERE uid=?", (source_uid,))})
        self.assertIn("managed", set(source_book["tags"]))
        self.assertIn(("old", 4282668390), set(db.execute("SELECT name,color FROM ab_profile_tags WHERE guid=?", (source_guid,))))
        self.assertIn("old", {row[0] for row in db.execute("SELECT tag FROM rustdesk_tags WHERE uid=?", (source_uid,))})
        self.assertIn("old", set(source_book["tags"]))
        self.assertIn("fresh-tag", {row[0] for row in db.execute("SELECT name FROM ab_profile_tags WHERE guid=?", (target_guid,))})
        self.assertIn("fresh-tag", {row[0] for row in db.execute("SELECT tag FROM rustdesk_tags WHERE uid=?", (target_uid,))})
        self.assertIn("fresh-tag", set(target_book["tags"]))
        self.assertEqual(db.execute("SELECT COUNT(*) FROM rustdesk_tags WHERE uid=? AND tag IN ('temp-tag','renamed-tag')", (source_uid,)).fetchone()[0], 0)
        db.close()

        scoped = HttpClient(self.url)
        _, scoped_session, _ = scoped.json("GET", "/?s=/ops-x9/api/session")
        _, scoped_login, _ = scoped.json("POST", "/?s=/ops-x9/api/login",
            {"username": f"book-source-{suffix}", "password": "1"}, {"X-CSRF-Token": scoped_session["csrf"]})
        scoped.json("GET", f"/?s=/ops-x9/api/address-book&user_id={target_uid}", expected=(403,))
        scoped.json("POST", f"/?s=/ops-x9/api/address-book/batch&user_id={target_uid}",
            {"action": "remove", "peer_ids": [online_id]}, {"X-CSRF-Token": scoped_login["csrf"]}, expected=(403,))
        scoped.json("PATCH", f"/?s=/ops-x9/api/users/{source_uid}",
            {"address_book_scope": "all"}, {"X-CSRF-Token": scoped_login["csrf"]}, expected=(403,))
        self.client.json("DELETE", f"/?s=/ops-x9/api/users/{source_uid}", None, {"X-CSRF-Token": csrf})
        self.client.json("DELETE", f"/?s=/ops-x9/api/users/{target_uid}", None, {"X-CSRF-Token": csrf})

    def test_26_device_inventory_uses_id_and_uuid_as_identity(self):
        csrf = self.admin_csrf()
        device_id = "shared-device-id"
        for uuid_value, hostname in (("uuid-a", "host-a"), ("uuid-b", "host-b"), ("CaseUUID", "host-upper"), ("caseuuid", "host-lower")):
            self.client.json("POST", "/?s=/api/heartbeat", {"id": device_id, "uuid": uuid_value, "ver": 11, "conns": []})
            self.client.request("POST", "/?s=/api/sysinfo", {"id": device_id, "uuid": uuid_value, "hostname": hostname, "version": "1.5.0"})
        _, listing, _ = self.client.json("GET", f"/?s=/ops-x9/api/devices&q={device_id}&page=1&pageSize=20")
        self.assertEqual({(row["id"], row["uuid"], row["hostname"]) for row in listing["data"]}, {
            (device_id, "uuid-a", "host-a"), (device_id, "uuid-b", "host-b"),
            (device_id, "CaseUUID", "host-upper"), (device_id, "caseuuid", "host-lower"),
        })
        self.client.json("DELETE", f"/?s=/ops-x9/api/devices/{device_id}&uuid=missing", {}, {"X-CSRF-Token": csrf}, expected=(404,))
        self.client.json("DELETE", f"/?s=/ops-x9/api/devices/{device_id}&uuid=uuid-a", {}, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT id,uuid,json_extract(payload,'$.hostname') FROM device_reports WHERE id=? ORDER BY uuid", (device_id,)).fetchall(), [
            (device_id, "CaseUUID", "host-upper"), (device_id, "caseuuid", "host-lower"), (device_id, "uuid-b", "host-b"),
        ])
        db.close()

    def test_26a_batch_device_removal_is_atomic_and_uses_composite_identity(self):
        csrf = self.admin_csrf()
        suffix = uuid.uuid4().hex[:10]
        removable = {"id": f"batch-remove-{suffix}", "uuid": "uuid-remove"}
        sibling = {"id": removable["id"], "uuid": "uuid-keep"}
        second = {"id": f"batch-remove-second-{suffix}", "uuid": "uuid-second"}
        auth = self.rust_login()
        for device in [removable, sibling, second]:
            self.client.json("POST", "/?s=/api/heartbeat", {**device, "conns": []})
            self.client.json("POST", "/?s=/api/sysinfo", {**device, "hostname": device["uuid"]})
            self.client.json("POST", "/?s=/api/devices/deploy", {**device, "pk": f"pk-{device['uuid']}"}, auth)

        self.client.json("DELETE", "/?s=/ops-x9/api/devices", {
            "devices": [removable, second],
        }, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        for table in ["device_reports", "device_deployments"]:
            self.assertEqual(db.execute(f"SELECT COUNT(*) FROM {table} WHERE id=? AND uuid=?", (removable["id"], removable["uuid"])).fetchone()[0], 0)
            self.assertEqual(db.execute(f"SELECT COUNT(*) FROM {table} WHERE id=? AND uuid=?", (second["id"], second["uuid"])).fetchone()[0], 0)
            self.assertEqual(db.execute(f"SELECT COUNT(*) FROM {table} WHERE id=? AND uuid=?", (sibling["id"], sibling["uuid"])).fetchone()[0], 1)
        self.assertEqual(db.execute("SELECT payload FROM device_reports WHERE id=? AND uuid=?", (sibling["id"], sibling["uuid"])).fetchone()[0].find("uuid-keep") >= 0, True)
        db.close()

        blocked = {"id": f"batch-remove-blocked-{suffix}", "uuid": "uuid-blocked"}
        free = {"id": f"batch-remove-free-{suffix}", "uuid": "uuid-free"}
        for device in [blocked, free]:
            self.client.json("POST", "/?s=/api/heartbeat", {**device, "conns": []})
        self.client.json("POST", "/?s=/ops-x9/api/address-book/peers", {"id": blocked["id"], "alias": "保留", "tags": []}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("DELETE", "/?s=/ops-x9/api/devices", {"devices": [blocked, free]}, {"X-CSRF-Token": csrf}, expected=(409,))
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM device_reports WHERE (id=? AND uuid=?) OR (id=? AND uuid=?)", (blocked["id"], blocked["uuid"], free["id"], free["uuid"])).fetchone()[0], 2)
        db.close()

    def test_27_admin_can_assign_one_or_many_devices_to_selected_address_book_users(self):
        csrf = self.admin_csrf()
        device_id = "assignable-device"
        uuid_value = "assignable-uuid"
        self.client.json("POST", "/?s=/api/heartbeat", {"id": device_id, "uuid": uuid_value, "ver": 11, "conns": []})
        self.client.json("POST", "/?s=/api/sysinfo", {"id": device_id, "uuid": uuid_value, "hostname": "assign-host", "platform": "windows"})
        _, options, _ = self.client.json("GET", "/?s=/ops-x9/api/address-book/assignment-options")
        self.assertEqual({user["id"] for user in options["users"]}, {1, 2})
        self.client.json("POST", "/?s=/ops-x9/api/devices/address-book", {
            "devices": [{"id": device_id, "uuid": uuid_value}], "user_ids": [1, 2], "mode": "add",
        }, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        assigned = db.execute("SELECT uid,id FROM rustdesk_peers WHERE id=? ORDER BY uid", (device_id,)).fetchall()
        profile_count = db.execute("SELECT COUNT(*) FROM ab_profile_peers WHERE id=?", (device_id,)).fetchone()[0]
        db.close()
        self.assertEqual(assigned, [(1, device_id), (2, device_id)])
        self.assertEqual(profile_count, 2)
        _, listing, _ = self.client.json("GET", "/?s=/ops-x9/api/devices?q=assignable-device&page=1&pageSize=20")
        self.assertEqual(sorted(next(row for row in listing["data"] if row["id"] == device_id)["address_book_user_ids"]), [1, 2])

        suffix = uuid.uuid4().hex[:8]
        _, scoped_created, _ = self.client.json("POST", "/?s=/ops-x9/api/users", {
            "username": f"assignment-self-{suffix}", "password": "1", "enabled": True, "address_book_scope": "self",
        }, {"X-CSRF-Token": csrf}, expected=(201,))
        scoped_uid = int(scoped_created["id"])
        self.client.json("PATCH", f"/?s=/ops-x9/api/users/{scoped_uid}", {
            "is_admin": True, "enabled": True, "address_book_scope": "self",
        }, {"X-CSRF-Token": csrf})
        self.client.json("POST", "/?s=/ops-x9/api/devices/address-book", {
            "devices": [{"id": device_id, "uuid": uuid_value}], "user_ids": [scoped_uid], "mode": "add",
        }, {"X-CSRF-Token": csrf})
        scoped = HttpClient(self.url)
        _, scoped_session, _ = scoped.json("GET", "/?s=/ops-x9/api/session")
        _, scoped_login, _ = scoped.json("POST", "/?s=/ops-x9/api/login",
            {"username": f"assignment-self-{suffix}", "password": "1"}, {"X-CSRF-Token": scoped_session["csrf"]})
        _, scoped_options, _ = scoped.json("GET", "/?s=/ops-x9/api/address-book/assignment-options")
        self.assertEqual(scoped_options["users"], [{"id": scoped_uid, "username": f"assignment-self-{suffix}", "is_admin": True}])
        self.assertEqual(scoped_options["assignments"].get(device_id), [scoped_uid])
        _, scoped_listing, _ = scoped.json("GET", f"/?s=/ops-x9/api/devices?q={device_id}&page=1&pageSize=20")
        self.assertEqual(next(row for row in scoped_listing["data"] if row["id"] == device_id)["address_book_user_ids"], [scoped_uid])
        scoped.json("POST", "/?s=/ops-x9/api/devices/address-book", {
            "devices": [{"id": device_id, "uuid": uuid_value}], "user_ids": [], "mode": "replace",
        }, {"X-CSRF-Token": scoped_login["csrf"]})
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT uid FROM rustdesk_peers WHERE id=? ORDER BY uid", (device_id,)).fetchall(), [(1,), (2,)])
        db.close()
        scoped.json("POST", "/?s=/ops-x9/api/devices/address-book", {
            "devices": [{"id": device_id, "uuid": uuid_value}], "user_ids": [scoped_uid], "mode": "replace",
        }, {"X-CSRF-Token": scoped_login["csrf"]})
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT uid FROM rustdesk_peers WHERE id=? ORDER BY uid", (device_id,)).fetchall(), [(1,), (2,), (scoped_uid,)])
        db.close()
        scoped.json("POST", "/?s=/ops-x9/api/devices/address-book", {
            "devices": [{"id": device_id, "uuid": uuid_value}], "user_ids": [], "mode": "replace",
        }, {"X-CSRF-Token": scoped_login["csrf"]})
        self.client.json("DELETE", f"/?s=/ops-x9/api/users/{scoped_uid}", None, {"X-CSRF-Token": csrf})
        self.client.json("POST", "/?s=/ops-x9/api/devices/address-book", {
            "devices": [{"id": device_id, "uuid": uuid_value}], "user_ids": [2], "mode": "remove",
        }, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT uid FROM rustdesk_peers WHERE id=?", (device_id,)).fetchall(), [(1,)])
        self.assertEqual(db.execute("SELECT COUNT(*) FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=2 AND a.personal=1 AND p.id=?", (device_id,)).fetchone()[0], 0)
        book = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=2").fetchone()[0])
        self.assertNotIn(device_id, {peer.get("id") for peer in book.get("peers", [])})
        db.close()
        user_two_auth = self.rust_login("legacy", "legacy123", "assignable-readback-user")
        _, client_book, _ = self.client.json("GET", "/?s=/api/ab", None, user_two_auth)
        self.assertNotIn(device_id, {peer.get("id") for peer in json.loads(client_book["data"]).get("peers", [])})
        self.client.json("POST", "/?s=/ops-x9/api/devices/address-book", {
            "devices": [{"id": device_id, "uuid": uuid_value}], "user_ids": [], "mode": "replace",
        }, {"X-CSRF-Token": csrf})
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM rustdesk_peers WHERE id=?", (device_id,)).fetchone()[0], 0)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM ab_profile_peers WHERE id=?", (device_id,)).fetchone()[0], 0)
        db.close()

    def test_assignment_remove_preview_and_apply_clears_all_address_book_stores(self):
        csrf = self.admin_csrf()
        device_id, uuid_value = "remove-all-stores", "remove-all-stores-uuid"
        self.client.json("POST", "/?s=/api/heartbeat", {"id": device_id, "uuid": uuid_value, "ver": 11, "conns": []})
        self.client.json("POST", "/?s=/api/sysinfo", {"id": device_id, "uuid": uuid_value, "hostname": "remove-host"})
        self.client.json("POST", "/?s=/ops-x9/api/devices/address-book", {"devices":[{"id":device_id,"uuid":uuid_value}],"user_ids":[1],"mode":"add"}, {"X-CSRF-Token":csrf})
        before_updated_at = sqlite3.connect(self.db).execute("SELECT updated_at FROM address_books WHERE uid=1").fetchone()[0]
        _, preview, _ = self.client.json("POST", "/?s=/ops-x9/api/devices/address-book/preview", {"devices":[{"id":device_id,"uuid":uuid_value}],"user_ids":[1],"mode":"remove"}, {"X-CSRF-Token":csrf})
        self.assertEqual(len(preview["plan"]["remove"]), 1)
        self.client.json("POST", "/?s=/ops-x9/api/devices/address-book", {"devices":[{"id":device_id,"uuid":uuid_value}],"user_ids":[1],"mode":"remove"}, {"X-CSRF-Token":csrf})
        db = sqlite3.connect(self.db)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM rustdesk_peers WHERE uid=1 AND id=?", (device_id,)).fetchone()[0], 0)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=1 AND a.personal=1 AND p.id=?", (device_id,)).fetchone()[0], 0)
        book = json.loads(db.execute("SELECT payload FROM address_books WHERE uid=1").fetchone()[0])
        self.assertNotIn(device_id, {peer.get("id") for peer in book.get("peers", [])})
        self.assertGreaterEqual(db.execute("SELECT updated_at FROM address_books WHERE uid=1").fetchone()[0], before_updated_at)
        db.close()

    def test_27_sysinfo_recursively_preserves_unknown_json_and_refreshes_ip_geo_together(self):
        device_id = "nested-json-device"
        uuid_value = "nested-json-uuid"
        self.client.request("POST", "/?s=/api/sysinfo", {
            "id": device_id, "uuid": uuid_value, "hostname": "first-host",
            "network": {"private_ips": ["10.0.0.8"], "future": {"keep": 1, "replace": "old"}},
            "future": {"nested": {"keep": True, "replace": "old"}},
        }, {"X-Real-IP": "81.2.69.160"})
        db = sqlite3.connect(self.db)
        stale_network = {"public_ip": "81.2.69.160", "private_ips": ["10.0.0.8"], "future": {"keep": 1}, "geo": {"city": "London"}}
        db.execute("UPDATE device_reports SET network_payload=? WHERE id=? AND uuid=?", (json.dumps(stale_network), device_id, uuid_value))
        db.commit()
        db.close()

        self.client.request("POST", "/?s=/api/sysinfo", {
            "id": device_id, "uuid": uuid_value, "hostname": "second-host",
            "network": {"private_ips": ["10.0.0.9"], "future": {"replace": "new"}},
            "future": {"nested": {"replace": "new"}},
        }, {"X-Real-IP": "8.8.8.8"})
        db = sqlite3.connect(self.db)
        payload_text, network_text = db.execute("SELECT payload,network_payload FROM device_reports WHERE id=? AND uuid=?", (device_id, uuid_value)).fetchone()
        db.close()
        payload = json.loads(payload_text)
        network = json.loads(network_text)
        self.assertEqual(payload["future"]["nested"], {"keep": True, "replace": "new"})
        self.assertEqual(payload["network"]["future"], {"keep": 1, "replace": "new"})
        self.assertEqual(network["future"], {"keep": 1})
        self.assertEqual(network["private_ips"], ["10.0.0.9"])
        self.assertEqual(network["public_ip"], "8.8.8.8")
        self.assertNotIn("geo", network)

    @unittest.skipUnless(os.environ.get("RUSTDESK_GEOIP_DATABASE"), "GeoLite database not configured")
    def test_28_geolite_city_is_persisted_from_forwarded_public_ip(self):
        device_id = "geo-device"
        headers = {"X-Real-IP": "81.2.69.160"}
        self.client.json("POST", "/?s=/api/heartbeat", {"id": device_id, "uuid": "geo-uuid", "ver": 11, "conns": []}, headers)
        db = sqlite3.connect(self.db)
        network = json.loads(db.execute("SELECT network_payload FROM device_reports WHERE id=? AND uuid=?", (device_id, "geo-uuid")).fetchone()[0])
        db.close()
        self.assertEqual(network["public_ip"], "81.2.69.160")

        self.assertEqual(network["geo"]["country_code"], "GB")
        self.assertTrue(network["geo"].get("region") or network["geo"].get("city"))
        self.assertEqual(network["geo"]["timezone"], "Europe/London")

        network.pop("geo", None)
        db = sqlite3.connect(self.db)
        db.execute("UPDATE device_reports SET network_payload=? WHERE id=? AND uuid=?", (json.dumps(network), device_id, "geo-uuid"))
        db.commit()
        db.close()
        self.admin_csrf()
        _, listing, _ = self.client.json("GET", f"/?s=/ops-x9/api/devices&q={device_id}&page=1&pageSize=20")
        device = next(row for row in listing["data"] if row["id"] == device_id)
        self.assertEqual(device["geo"]["country_code"], "GB")
        self.assertEqual(device["geo"]["timezone"], "Europe/London")

    def test_29_private_proxy_address_is_not_persisted_as_public_ip(self):
        device_id = "proxy-chain-device"
        self.client.json(
            "POST",
            "/?s=/api/heartbeat",
            {"id": device_id, "uuid": "proxy-chain-uuid", "ver": 11, "conns": []},
            {"X-Real-IP": "9.9.9.9", "X-Forwarded-For": "8.8.8.8, 81.2.69.160"},
        )
        db = sqlite3.connect(self.db)
        network = json.loads(db.execute(
            "SELECT network_payload FROM device_reports WHERE id=? AND uuid=?",
            (device_id, "proxy-chain-uuid"),
        ).fetchone()[0])
        db.close()
        self.assertEqual(network["public_ip"], "81.2.69.160")

        self.client.json(
            "POST",
            "/?s=/api/heartbeat",
            {"id": "broken-proxy-chain", "uuid": "broken-proxy-uuid", "ver": 11, "conns": []},
            {"X-Real-IP": "81.2.69.160", "X-Forwarded-For": "8.8.8.8, invalid-hop"},
        )
        db = sqlite3.connect(self.db)
        broken = json.loads(db.execute("SELECT network_payload FROM device_reports WHERE id='broken-proxy-chain'").fetchone()[0])
        db.close()
        self.assertEqual(broken["public_ip"], "")

        db = sqlite3.connect(self.db)
        db.execute(
            "UPDATE device_reports SET network_payload=? WHERE id=? AND uuid=?",
            (json.dumps({"public_ip": "172.22.0.1", "geo": {"city": "stale"}}), device_id, "proxy-chain-uuid"),
        )
        db.commit()
        db.close()
        self.admin_csrf()
        _, listing, _ = self.client.json("GET", f"/?s=/ops-x9/api/devices&q={device_id}&page=1&pageSize=20")
        legacy = next(row for row in listing["data"] if row["id"] == device_id)
        self.assertEqual(legacy["public_ip"], "")
        self.assertEqual(legacy["geo"], [])

    def test_30_special_ranges_and_ipv6_proxy_rules_are_classified_correctly(self):
        runtime = Path(self.runtime or os.environ.get("FRANKENPHP", DEFAULT_RUNTIME))
        php = "require %s; echo json_encode([ip_matches_proxy_rule('2001:0db8:0:0:0:0:0:1','2001:db8::1'),is_public_ip('8.8.8.8'),is_public_ip('2606:4700:4700::1111'),is_public_ip('192.0.0.9'),is_public_ip('192.0.0.10'),is_public_ip('100.64.0.1'),is_public_ip('192.0.2.1'),is_public_ip('192.88.99.1'),is_public_ip('198.51.100.1'),is_public_ip('203.0.113.1'),is_public_ip('224.0.0.1'),is_public_ip('100:0:0:1::1'),is_public_ip('2001:5::1'),is_public_ip('2001:100::1'),is_public_ip('2001:2::1'),is_public_ip('2001:db8::1'),is_public_ip('3fff::1'),is_public_ip('5f00::1'),is_public_ip('ff02::1'),forwarded_public_ip('10.0.0.2','9.9.9.9','8.8.8.8',[]),forwarded_public_ip('127.0.0.1','9.9.9.9','8.8.8.8, 81.2.69.160',['127.0.0.0/8'])]);" % json.dumps(str(SQLITE_DIR / "lib.php"))
        command = [str(runtime), "php-cli", "-r", php] if runtime.name == "frankenphp" else [str(runtime), "-r", php]
        result = json.loads(subprocess.check_output(command, cwd=ROOT, text=True))
        self.assertEqual(result, [True, True, True, True, True, False, False, False, False, False, False, False, False, False, False, False, False, False, False, '', '81.2.69.160'])

        special_addresses = ["100.64.0.1", "192.0.2.1", "192.88.99.1", "198.18.0.1", "198.51.100.1", "203.0.113.1", "224.0.0.1", "100:0:0:1::1", "2001:5::1", "2001:100::1", "2001:2::1", "2001:db8::1", "3fff::1", "5f00::1", "ff02::1"]
        for index, address in enumerate(special_addresses):
            self.client.json(
                "POST",
                "/?s=/api/heartbeat",
                {"id": f"special-ip-{index:02d}", "uuid": f"special-uuid-{index:02d}", "ver": 11, "conns": []},
                {"X-Forwarded-For": address},
            )
        db = sqlite3.connect(self.db)
        persisted = [json.loads(row[0]).get("public_ip") for row in db.execute("SELECT network_payload FROM device_reports WHERE id LIKE 'special-ip-%' ORDER BY id")]
        db.close()
        self.assertEqual(persisted, [""] * len(special_addresses))

        self.client.json(
            "POST",
            "/?s=/api/heartbeat",
            {"id": "public-ipv6", "uuid": "public-ipv6-uuid", "ver": 11, "conns": []},
            {"X-Forwarded-For": "2606:4700:4700::1111"},
        )
        db = sqlite3.connect(self.db)
        public_ipv6 = json.loads(db.execute("SELECT network_payload FROM device_reports WHERE id='public-ipv6'").fetchone()[0])["public_ip"]
        db.close()
        self.assertEqual(public_ipv6, "2606:4700:4700::1111")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--frankenphp", help="Path to FrankenPHP runtime")
    args, extra = parser.parse_known_args()
    if args.frankenphp:
        IntegrationTest.runtime = args.frankenphp
    unittest.main(argv=[sys.argv[0], *extra])


if __name__ == "__main__":
    main()
