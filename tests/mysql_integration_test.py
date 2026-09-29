#!/usr/bin/env python3
"""HTTP CRUD plus direct SQL assertions against the MySQL container stack."""

import json
import os
import subprocess
import time
import unittest

from tests.integration_test import HttpClient


class MySQLIntegrationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.client = HttpClient(os.environ.get("RUSTDESK_TEST_URL", "http://127.0.0.1:17000"))
        cls.container = os.environ["RUSTDESK_MYSQL_CONTAINER"]
        cls.password = os.environ.get("RUSTDESK_MYSQL_PASSWORD", "testpass")

    @classmethod
    def sql(cls, statement):
        command = [
            "docker", "exec", cls.container, "env", f"MYSQL_PWD={cls.password}",
            "mysql", "--default-character-set=utf8mb4", "-N", "-B", "-urustdesk", "rustdesk", "-e", statement,
        ]
        env = {**os.environ, "HOME": "/tmp/codex-home", "DOCKER_HOST": "unix:///Users/olly/.docker/run/docker.sock"}
        return subprocess.check_output(command, env=env, text=True).strip().splitlines()

    def admin_login(self):
        _, session, _ = self.client.json("GET", "/ops-x9/api/session")
        _, login, _ = self.client.json("POST", "/ops-x9/api/login", {"username": "admin", "password": "admin123"}, {"X-CSRF-Token": session["csrf"]})
        return login["csrf"]

    def client_login(self, username="admin", password="admin123", device="mysql-client"):
        _, body, _ = self.client.json("POST", "/api/login", {"username": username, "password": password, "id": device, "uuid": device + "-uuid"})
        return {"Authorization": "Bearer " + body["access_token"]}

    def test_01_public_and_custom_admin_path(self):
        self.assertEqual(self.sql("SELECT value FROM app_meta WHERE `key`='schema_version'"), ["5"])
        self.assertEqual(
            self.sql("SELECT COUNT(*),COUNT(DISTINCT TABLE_COLLATION),MIN(TABLE_COLLATION) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"),
            ["18\t1\tutf8mb4_unicode_ci"],
        )
        for path in ("/", "/admin", "/unknown-page"):
            status, body, ctype = self.client.request("GET", path, expected=(200,))
            self.assertIn("text/html", ctype)
            self.assertIn("RustDesk", body)
        status, body, _ = self.client.request("GET", "/ops-x9", expected=(200,))
        self.assertIn("登录", body)
        status, _, _ = self.client.request("GET", "/admin/api/session", expected=(200,))
        self.assertEqual(status, 200)
        status, _, ctype = self.client.request("GET", "/api/not-found", expected=(404,))
        self.assertIn("application/json", ctype)

    def test_02_login_token_and_address_book_real_rows(self):
        auth = self.client_login()
        _, current, _ = self.client.json("POST", "/api/currentUser", {"id": "mysql-client", "uuid": "mysql-client-uuid"}, auth)
        self.assertEqual(current["name"], "admin")
        book = {"tags": ["prod", "逗号,标签"], "peers": [{"id": "peer-1", "alias": "主机", "username": "u", "hostname": "h", "platform": "linux", "tags": ["prod"], "hash": "h", "future": {"x": 1}}]}
        self.client.json("POST", "/api/ab", {"data": json.dumps(book, ensure_ascii=False)}, auth)
        rows = self.sql("SELECT HEX(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.peers[0].alias'))),JSON_LENGTH(JSON_EXTRACT(payload,'$.tags')) FROM address_books WHERE uid=1")
        self.assertEqual(rows, ["E4B8BBE69CBA\t2"])
        self.client.json("POST", "/api/logout", {}, auth)
        self.assertEqual(self.sql("SELECT COUNT(*) FROM rustdesk_token WHERE access_token<>''"), ["0"])

    def test_03_personal_address_book_crud_and_sql_values(self):
        auth = self.client_login(device="mysql-ab")
        _, personal, _ = self.client.json("POST", "/api/ab/personal", {}, auth)
        guid = personal["guid"]
        self.client.json("POST", f"/api/ab/peer/add/{guid}", {"id": "new-peer", "alias": "first", "tags": ["ops"]}, auth)
        self.client.json("PUT", f"/api/ab/peer/update/{guid}", {"id": "new-peer", "alias": "renamed"}, auth)
        self.client.json("POST", f"/api/ab/tag/add/{guid}", {"name": "ops", "color": 123}, auth)
        self.client.json("PUT", f"/api/ab/tag/rename/{guid}", {"old": "ops", "new": "prod"}, auth)
        peer = self.sql(f"SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$.alias')) FROM ab_profile_peers WHERE guid='{guid}' AND id='new-peer'")
        tag = self.sql(f"SELECT name,color FROM ab_profile_tags WHERE guid='{guid}'")
        self.assertEqual(peer, ["renamed"])
        self.assertEqual(tag, ["prod\t123"])
        self.client.json("DELETE", f"/api/ab/peer/{guid}", ["new-peer"], auth)
        self.client.json("DELETE", f"/api/ab/tag/{guid}", ["prod"], auth)
        self.assertEqual(self.sql(f"SELECT (SELECT COUNT(*) FROM ab_profile_peers WHERE guid='{guid}')+(SELECT COUNT(*) FROM ab_profile_tags WHERE guid='{guid}')"), ["0"])

    def test_04_device_audit_switch_and_record_rows(self):
        auth = self.client_login(device="mysql-device")
        self.client.json("POST", "/api/devices/deploy", {"id": "deploy-mysql", "uuid": "uuid-1", "pk": "pk-1"}, auth)
        self.client.json("POST", "/api/devices/cli", {"id": "deploy-mysql", "uuid": "uuid-1", "device_name": "Managed"}, auth)
        self.client.request("POST", "/api/sysinfo", {"id": "deploy-mysql", "uuid": "uuid-1", "hostname": "real-host", "os": "linux", "version": "1.4.6"}, expected=(200,))
        nonce = "92233720368547758081234567890"
        self.client.json("POST", "/api/audit/conn", {"id": "deploy-mysql", "uuid": "uuid-1", "nonce": nonce})
        self.client.json("POST", "/api/audit/conn", {"id": "deploy-mysql", "uuid": "uuid-1", "nonce": nonce})
        self.client.json("PUT", "/api/audit", {"guid": "mysql-note", "note": "hello"}, auth)
        now = str(int(time.time()))
        self.client.json("POST", "/api/switch-grant", {"id": "deploy-mysql", "switch_code_verifier": "v", "timestamp": now, "signature": "sig"})
        self.client.request("POST", "/api/record?op=new&filename=e2e.webm&id=mysql-session", b"frame-1", expected=(200,))
        self.client.request("POST", "/api/record?op=part&filename=e2e.webm&id=mysql-session", b"frame-2", expected=(200,))
        self.assertEqual(self.sql("SELECT id,uuid,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.device_name')) FROM device_deployments WHERE id='deploy-mysql'"), ["deploy-mysql\tuuid-1\tManaged"])
        self.assertEqual(self.sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$.hostname')) FROM device_reports WHERE id='deploy-mysql'"), ["real-host"])
        self.assertEqual(self.sql(f"SELECT COUNT(*) FROM audit_events WHERE nonce='{nonce}'"), ["1"])
        self.assertEqual(self.sql("SELECT note FROM audit_notes WHERE guid='mysql-note'"), ["hello"])
        self.assertEqual(self.sql("SELECT signature FROM switch_grants WHERE id='deploy-mysql' AND verifier='v'"), ["sig"])
        self.assertEqual(self.sql("SELECT COUNT(*),SUM(OCTET_LENGTH(payload)),SUM(chunk_size) FROM record_chunks WHERE upload_key='mysql-session'"), ["2\t14\t14"])

    def test_05_admin_user_crud_and_direct_sql(self):
        csrf = self.admin_login()
        _, created, _ = self.client.json("POST", "/ops-x9/api/users", {"username": "mysql-managed", "password": "1", "enabled": True}, {"X-CSRF-Token": csrf}, expected=(201,))
        uid = int(created["id"])
        row = self.sql(f"SELECT username,enabled,delete_time,(password LIKE '$2%') FROM rustdesk_users WHERE id={uid}")
        self.assertEqual(row, ["mysql-managed\t1\t0\t1"])
        self.client_login(username="mysql-managed", password="1", device="mysql-short-password")
        self.client.json("PATCH", f"/ops-x9/api/users/{uid}", {"username": "mysql-renamed", "enabled": False}, {"X-CSRF-Token": csrf})
        self.assertEqual(self.sql(f"SELECT username,enabled,auth_version FROM rustdesk_users WHERE id={uid}"), ["mysql-renamed\t0\t1"])
        self.client.json("DELETE", f"/ops-x9/api/users/{uid}", None, {"X-CSRF-Token": csrf})
        deleted = self.sql(f"SELECT (delete_time>0),auth_version FROM rustdesk_users WHERE id={uid}")
        self.assertEqual(deleted, ["1\t2"])

    def test_06_admin_device_query_search_and_delete(self):
        csrf = self.admin_login()
        _, listing, _ = self.client.json("GET", "/ops-x9/api/devices?q=real-host&page=1&pageSize=20")
        self.assertTrue(any(row["id"] == "deploy-mysql" for row in listing["data"]))
        self.client.json("DELETE", "/ops-x9/api/devices/deploy-mysql", None, {"X-CSRF-Token": csrf})
        self.assertEqual(self.sql("SELECT (SELECT COUNT(*) FROM device_deployments WHERE id='deploy-mysql')+(SELECT COUNT(*) FROM device_reports WHERE id='deploy-mysql')"), ["0"])

    def test_07_heartbeat_only_device_and_alias_sync(self):
        device_id = "mysql-heartbeat-only"
        self.client.json("POST", "/api/heartbeat", {"id": device_id, "uuid": "mysql-heartbeat-uuid", "ver": 9, "conns": []})
        self.assertEqual(self.sql(f"SELECT id,(last_heartbeat>0),JSON_UNQUOTE(JSON_EXTRACT(heartbeat_payload,'$.ver')) FROM device_reports WHERE id='{device_id}'"), [f"{device_id}\t1\t9"])
        self.client.request("POST", "/api/sysinfo", {"id": device_id, "uuid": "mysql-heartbeat-uuid", "hostname": "mysql-heartbeat-host", "username": "operator", "os": "linux", "version": "1.4.6"}, expected=(200,))
        csrf = self.admin_login()
        _, listing, _ = self.client.json("GET", "/ops-x9/api/devices?q=mysql-heartbeat-host&page=1&pageSize=20")
        row = next(item for item in listing["data"] if item["id"] == device_id)
        self.assertEqual(row["presence"], "online")
        self.assertFalse(row["deployed"])
        self.client.json("PATCH", f"/ops-x9/api/devices/{device_id}/alias", {"alias": "MySQL 机房"}, {"X-CSRF-Token": csrf})
        self.assertEqual(self.sql(f"SELECT JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.alias')) FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=1 AND a.personal=1 AND p.id='{device_id}'"), ["MySQL 机房"])
        self.assertEqual(self.sql(f"SELECT alias FROM rustdesk_peers WHERE uid=1 AND id='{device_id}'"), ["MySQL 机房"])

    def test_08_presence_boundaries_summary_and_filters(self):
        csrf = self.admin_login()
        fixtures = {
            "mysql-presence-online-20": 19,
            "mysql-presence-recent-low": 22,
            "mysql-presence-recent-90": 89,
            "mysql-presence-offline": 92,
        }
        for device_id in fixtures:
            self.client.json("POST", "/api/heartbeat", {
                "id": device_id, "uuid": device_id + "-uuid", "ver": 10, "conns": [],
            })
            self.client.request("POST", "/api/sysinfo", {
                "id": device_id, "uuid": device_id + "-uuid",
                "hostname": device_id + "-host", "username": "boundary",
                "os": "linux", "version": "1.4.6",
            }, expected=(200,))
        now = int(time.time())
        cases = " ".join(f"WHEN '{device_id}' THEN {now - age}" for device_id, age in fixtures.items())
        ids = ",".join(f"'{device_id}'" for device_id in fixtures)
        self.sql(f"UPDATE device_reports SET last_heartbeat=CASE id {cases} END WHERE id IN ({ids})")
        self.client.json("PATCH", "/ops-x9/api/devices/mysql-presence-online-20/alias", {"alias": "boundary-label"}, {"X-CSRF-Token": csrf})
        self.assertEqual(
            self.sql("SELECT COUNT(*),SUM(last_heartbeat>0),SUM(JSON_UNQUOTE(JSON_EXTRACT(heartbeat_payload,'$.ver'))='10') FROM device_reports WHERE id LIKE 'mysql-presence-%'"),
            ["4\t4\t4"],
        )

        _, listing, _ = self.client.json("GET", "/ops-x9/api/devices?page=1&pageSize=200")
        indexed = {row["id"]: row for row in listing["data"]}
        self.assertEqual(indexed["mysql-presence-online-20"]["presence"], "online")
        self.assertEqual(indexed["mysql-presence-recent-low"]["presence"], "recent")
        self.assertEqual(indexed["mysql-presence-recent-90"]["presence"], "recent")
        self.assertEqual(indexed["mysql-presence-offline"]["presence"], "offline")
        self.assertEqual(set(listing["summary"]), {"total", "online", "recent", "offline", "unreported", "labelled"})
        self.assertEqual(listing["summary"]["total"], listing["total"])
        for presence in ("online", "recent", "offline", "unreported"):
            _, filtered, _ = self.client.json("GET", f"/ops-x9/api/devices?presence={presence}&page=1&pageSize=200")
            self.assertTrue(all(row["presence"] == presence for row in filtered["data"]))
        _, labelled, _ = self.client.json("GET", "/ops-x9/api/devices?labelled=1&page=1&pageSize=200")
        self.assertTrue(any(row["id"] == "mysql-presence-online-20" for row in labelled["data"]))
        self.assertTrue(all(isinstance(row.get("alias"), str) and row["alias"] != "" for row in labelled["data"]))

    def test_09_alias_auth_validation_clear_and_client_readback(self):
        endpoint = "/ops-x9/api/devices/mysql-heartbeat-only/alias"
        anonymous = HttpClient(os.environ.get("RUSTDESK_TEST_URL", "http://127.0.0.1:17000"))
        anonymous.json("PATCH", endpoint, {"alias": "blocked"}, expected=(401,))
        self.client.json("POST", "/api/heartbeat", {"id": "mysql-heartbeat-only", "uuid": "mysql-heartbeat-uuid", "ver": 9, "conns": []})
        self.client.request("POST", "/api/sysinfo", {"id": "mysql-heartbeat-only", "uuid": "mysql-heartbeat-uuid", "hostname": "mysql-heartbeat-host", "username": "operator", "os": "linux", "version": "1.4.6"}, expected=(200,))
        csrf = self.admin_login()
        self.client.json("PATCH", endpoint, {"alias": "blocked"}, expected=(403,))
        self.client.json("PATCH", endpoint, {"alias": {"invalid": True}}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("PATCH", endpoint, {"alias": "x" * 256}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("PATCH", endpoint, {"alias": "before-clear"}, {"X-CSRF-Token": csrf})

        self.sql("UPDATE ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid SET p.payload=JSON_SET(p.payload,'$.future_field',JSON_OBJECT('preserve',true)) WHERE a.uid=1 AND a.personal=1 AND p.id='mysql-heartbeat-only'")
        self.sql("UPDATE address_books SET payload=JSON_SET(payload,'$.future_top_level',JSON_OBJECT('preserve',true)) WHERE uid=1")
        _, result, _ = self.client.json("PATCH", endpoint, {"alias": ""}, {"X-CSRF-Token": csrf})
        self.assertEqual(result, {"ok": True, "alias": "", "sync": "next_address_book_pull"})
        self.assertEqual(
            self.sql("SELECT LENGTH(JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.alias'))),JSON_EXTRACT(p.payload,'$.future_field.preserve') FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=1 AND a.personal=1 AND p.id='mysql-heartbeat-only'"),
            ["0\ttrue"],
        )
        self.assertEqual(self.sql("SELECT LENGTH(alias) FROM rustdesk_peers WHERE uid=1 AND id='mysql-heartbeat-only'"), ["0"])
        self.assertEqual(
            self.sql("SELECT LENGTH(j.alias),JSON_EXTRACT(b.payload,'$.future_top_level.preserve') FROM address_books b JOIN JSON_TABLE(b.payload,'$.peers[*]' COLUMNS(id VARCHAR(128) PATH '$.id',alias VARCHAR(255) PATH '$.alias')) j ON j.id='mysql-heartbeat-only' WHERE b.uid=1"),
            ["0\ttrue"],
        )

        auth = self.client_login(device="mysql-alias-readback")
        _, personal, _ = self.client.json("POST", "/api/ab/personal", {}, auth)
        _, peers, _ = self.client.json("POST", f"/api/ab/peers?ab={personal['guid']}&current=1&pageSize=100", {}, auth)
        self.assertEqual(next(peer["alias"] for peer in peers["data"] if peer["id"] == "mysql-heartbeat-only"), "")

    def test_10_admin_address_book_merges_scoped_stores_with_profile_priority(self):
        csrf = self.admin_login()
        admin_auth = self.client_login(device="mysql-admin-book-merge")
        _, personal, _ = self.client.json("POST", "/api/ab/personal", {}, admin_auth)
        guid = personal["guid"]
        self.sql(
            "INSERT INTO rustdesk_peers(uid,id,username,hostname,alias,platform,tags,hash) VALUES "
            "(1,'mysql-admin-legacy-only','u','legacy-host','legacy-only','linux','legacy-tag','h'),"
            "(1,'mysql-admin-merged','legacy-user','legacy-host','legacy-alias','linux','legacy-tag','legacy-hash') "
            "ON DUPLICATE KEY UPDATE alias=VALUES(alias),hostname=VALUES(hostname),tags=VALUES(tags)"
        )
        self.sql(
            f"INSERT INTO ab_profile_peers(guid,id,payload,updated_at) VALUES "
            f"('{guid}','mysql-admin-profile-only',JSON_OBJECT('id','mysql-admin-profile-only','alias','profile-only','tags',JSON_ARRAY('profile-tag'),'future',JSON_OBJECT('keep',1)),UNIX_TIMESTAMP()),"
            f"('{guid}','mysql-admin-merged',JSON_OBJECT('id','mysql-admin-merged','alias','profile-wins','hostname','profile-host','tags',JSON_ARRAY('profile-tag'),'future',JSON_OBJECT('keep',2)),UNIX_TIMESTAMP()) "
            "ON DUPLICATE KEY UPDATE payload=VALUES(payload),updated_at=VALUES(updated_at)"
        )
        _, created, _ = self.client.json(
            "POST", "/ops-x9/api/users", {"username": "mysql-book-other", "password": "x", "enabled": True},
            {"X-CSRF-Token": csrf}, expected=(201,),
        )
        other_uid = int(created["id"])
        other_auth = self.client_login("mysql-book-other", "x", "mysql-book-other-client")
        _, other_personal, _ = self.client.json("POST", "/api/ab/personal", {}, other_auth)
        self.client.json(
            "POST", f"/api/ab/peer/add/{other_personal['guid']}",
            {"id": "mysql-other-user-only", "alias": "must-not-leak"}, other_auth,
        )

        _, listing, _ = self.client.json("GET", "/ops-x9/api/address-book?page=1&pageSize=200")
        indexed = {peer["id"]: peer for peer in listing["data"]}
        self.assertIn("mysql-admin-legacy-only", indexed)
        self.assertIn("mysql-admin-profile-only", indexed)
        self.assertEqual(indexed["mysql-admin-merged"]["alias"], "profile-wins")
        self.assertEqual(indexed["mysql-admin-merged"]["hostname"], "profile-host")
        self.assertNotIn("mysql-other-user-only", indexed)
        self.assertEqual(set(listing["summary"]), {"total", "favorites", "labelled", "tags"})
        self.assertEqual(
            self.sql(f"SELECT a.uid,JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.alias')) FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE p.guid='{other_personal['guid']}' AND p.id='mysql-other-user-only'"),
            [f"{other_uid}\tmust-not-leak"],
        )

    def test_11_admin_address_book_peer_crud_syncs_three_stores_and_client_apis(self):
        csrf = self.admin_login()
        peer_id = "mysql-admin-book-crud"
        payload = {
            "id": peer_id, "alias": "MySQL 入口", "hostname": "mysql-entry", "username": "operator",
            "platform": "linux", "tags": ["ops", "ops-prod"], "note": "future payload stays",
            "future_field": {"preserve": True},
        }
        self.client.json("POST", "/ops-x9/api/address-book/peers", payload, {"X-CSRF-Token": csrf}, expected=(201,))
        self.assertEqual(
            self.sql(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.alias')),JSON_EXTRACT(p.payload,'$.future_field.preserve'),r.hostname,r.tags "
                "FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid JOIN rustdesk_peers r ON r.uid=a.uid AND r.id=p.id "
                f"WHERE a.uid=1 AND a.personal=1 AND p.id='{peer_id}'"
            ),
            ["MySQL 入口\ttrue\tmysql-entry\tops,ops-prod"],
        )
        self.assertEqual(
            self.sql(
                "SELECT JSON_UNQUOTE(j.alias),JSON_UNQUOTE(j.note) FROM address_books b JOIN JSON_TABLE(b.payload,'$.peers[*]' "
                "COLUMNS(id VARCHAR(128) PATH '$.id',alias JSON PATH '$.alias',note JSON PATH '$.note')) j "
                f"ON j.id='{peer_id}' WHERE b.uid=1"
            ),
            ["MySQL 入口\tfuture payload stays"],
        )
        self.assertEqual(self.sql(f"SELECT COUNT(*) FROM device_reports WHERE id='{peer_id}'"), ["0"])

        self.client.json(
            "PATCH", f"/ops-x9/api/address-book/peers/{peer_id}",
            {"alias": "MySQL 入口-更新", "hostname": "mysql-entry-2"}, {"X-CSRF-Token": csrf},
        )
        auth = self.client_login(device="mysql-admin-book-readback")
        _, old_book, _ = self.client.json("GET", "/api/ab", None, auth)
        old_peer = next(peer for peer in json.loads(old_book["data"])["peers"] if peer["id"] == peer_id)
        self.assertEqual((old_peer["alias"], old_peer["hostname"]), ("MySQL 入口-更新", "mysql-entry-2"))
        self.assertEqual(old_peer["future_field"], {"preserve": True})
        _, personal, _ = self.client.json("POST", "/api/ab/personal", {}, auth)
        _, new_book, _ = self.client.json("POST", f"/api/ab/peers?ab={personal['guid']}&current=1&pageSize=200", {}, auth)
        new_peer = next(peer for peer in new_book["data"] if peer["id"] == peer_id)
        self.assertEqual(new_peer["alias"], "MySQL 入口-更新")
        self.assertEqual(new_peer["future_field"], {"preserve": True})

        self.client.json("POST", "/api/heartbeat", {"id": peer_id, "uuid": "mysql-book-device-uuid", "ver": 10, "conns": []})
        self.client.json("DELETE", f"/ops-x9/api/address-book/peers/{peer_id}", None, {"X-CSRF-Token": csrf})
        self.assertEqual(
            self.sql(
                "SELECT "
                f"(SELECT COUNT(*) FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=1 AND a.personal=1 AND p.id='{peer_id}'),"
                f"(SELECT COUNT(*) FROM rustdesk_peers WHERE uid=1 AND id='{peer_id}'),"
                f"(SELECT COUNT(*) FROM device_reports WHERE id='{peer_id}')"
            ),
            ["0\t0\t1"],
        )

    def test_12_admin_address_book_tags_rename_exact_values_in_all_stores(self):
        csrf = self.admin_login()
        peer_id = "mysql-admin-tag-peer"
        self.client.json("POST", "/ops-x9/api/address-book/tags", {"name": "ops", "color": 4283215696}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json("POST", "/ops-x9/api/address-book/tags", {"name": "ops-prod", "color": 4292030255}, {"X-CSRF-Token": csrf}, expected=(201,))
        self.client.json(
            "POST", "/ops-x9/api/address-book/peers",
            {"id": peer_id, "alias": "tag-peer", "tags": ["ops", "ops-prod"], "note": "ops remains in free text"},
            {"X-CSRF-Token": csrf}, expected=(201,),
        )
        self.client.json("PATCH", "/ops-x9/api/address-book/tags/ops", {"name": "core", "color": 4278255360}, {"X-CSRF-Token": csrf})
        self.assertEqual(
            self.sql(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.tags[0]')),JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.tags[1]'))," \
                "JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.note')),r.tags FROM ab_profile_peers p "
                "JOIN ab_profiles a ON a.guid=p.guid JOIN rustdesk_peers r ON r.uid=a.uid AND r.id=p.id "
                f"WHERE a.uid=1 AND a.personal=1 AND p.id='{peer_id}'"
            ),
            ["core\tops-prod\tops remains in free text\tcore,ops-prod"],
        )
        self.assertEqual(
            self.sql("SELECT name,color FROM ab_profile_tags WHERE guid=(SELECT guid FROM ab_profiles WHERE uid=1 AND personal=1) AND name IN ('ops','core','ops-prod') ORDER BY name"),
            ["core\t4278255360", "ops-prod\t4292030255"],
        )
        self.client.json("DELETE", "/ops-x9/api/address-book/tags/core", None, {"X-CSRF-Token": csrf})
        self.assertEqual(
            self.sql(
                "SELECT JSON_LENGTH(JSON_EXTRACT(p.payload,'$.tags')),JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.tags[0]')) "
                "FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid "
                f"WHERE a.uid=1 AND a.personal=1 AND p.id='{peer_id}'"
            ),
            ["1\tops-prod"],
        )

    def test_13_admin_address_book_favorites_are_scoped_idempotent_and_deleted_with_peer(self):
        csrf = self.admin_login()
        peer_id = "mysql-admin-favorite-peer"
        self.client.json("POST", "/ops-x9/api/address-book/peers", {"id": peer_id, "alias": "favorite"}, {"X-CSRF-Token": csrf}, expected=(201,))
        endpoint = f"/ops-x9/api/address-book/favorites/{peer_id}"
        self.client.json("PATCH", endpoint, {"favorite": True}, {"X-CSRF-Token": csrf})
        self.client.json("PATCH", endpoint, {"favorite": True}, {"X-CSRF-Token": csrf})
        self.sql(f"INSERT IGNORE INTO admin_peer_favorites(uid,id,created_at) VALUES (2,'{peer_id}',UNIX_TIMESTAMP())")
        self.assertEqual(self.sql(f"SELECT uid,COUNT(*) FROM admin_peer_favorites WHERE id='{peer_id}' GROUP BY uid ORDER BY uid"), ["1\t1", "2\t1"])
        _, listing, _ = self.client.json("GET", f"/ops-x9/api/address-book?q={peer_id}&page=1&pageSize=20")
        self.assertTrue(next(row for row in listing["data"] if row["id"] == peer_id)["favorite"])
        self.assertEqual(listing["summary"]["favorites"], 1)
        self.client.json("PATCH", endpoint, {"favorite": False}, {"X-CSRF-Token": csrf})
        self.client.json("PATCH", endpoint, {"favorite": False}, {"X-CSRF-Token": csrf})
        self.assertEqual(self.sql(f"SELECT uid FROM admin_peer_favorites WHERE id='{peer_id}' ORDER BY uid"), ["2"])
        self.client.json("PATCH", endpoint, {"favorite": True}, {"X-CSRF-Token": csrf})
        self.client.json("DELETE", f"/ops-x9/api/address-book/peers/{peer_id}", None, {"X-CSRF-Token": csrf})
        self.assertEqual(self.sql(f"SELECT uid FROM admin_peer_favorites WHERE id='{peer_id}' ORDER BY uid"), ["2"])

    def test_14_admin_address_book_requires_session_csrf_and_valid_payloads(self):
        anonymous = HttpClient(os.environ.get("RUSTDESK_TEST_URL", "http://127.0.0.1:17000"))
        anonymous.json("GET", "/ops-x9/api/address-book", expected=(401,))
        csrf = self.admin_login()
        self.client.json("POST", "/ops-x9/api/address-book/peers", {"id": "blocked"}, expected=(403,))
        self.client.json("POST", "/ops-x9/api/address-book/tags", {"name": "blocked"}, expected=(403,))
        self.client.json("PATCH", "/ops-x9/api/address-book/favorites/blocked", {"favorite": True}, expected=(403,))
        self.client.json("POST", "/ops-x9/api/address-book/peers", {"id": ""}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("POST", "/ops-x9/api/address-book/tags", {"name": ""}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("PATCH", "/ops-x9/api/address-book/favorites/missing", {"favorite": "yes"}, {"X-CSRF-Token": csrf}, expected=(422,))
        self.client.json("PATCH", "/ops-x9/api/address-book/favorites/missing", {"favorite": True}, {"X-CSRF-Token": csrf}, expected=(404,))

    def test_15_admin_address_book_paginates_beyond_two_hundred_peers(self):
        self.admin_login()
        guid = self.sql("SELECT guid FROM ab_profiles WHERE uid=1 AND personal=1")[0]
        values = ",".join(
            f"('{guid}','mysql-admin-page-{index:03d}',JSON_OBJECT('id','mysql-admin-page-{index:03d}','alias','page-{index:03d}','tags',JSON_ARRAY()),UNIX_TIMESTAMP()+{index})"
            for index in range(205)
        )
        self.sql(
            "INSERT INTO ab_profile_peers(guid,id,payload,updated_at) VALUES " + values +
            " ON DUPLICATE KEY UPDATE payload=VALUES(payload),updated_at=VALUES(updated_at)"
        )
        _, first, _ = self.client.json("GET", "/ops-x9/api/address-book?q=mysql-admin-page-&page=1&pageSize=200")
        _, second, _ = self.client.json("GET", "/ops-x9/api/address-book?q=mysql-admin-page-&page=2&pageSize=200")
        self.assertEqual((first["total"], len(first["data"]), len(second["data"])), (205, 200, 5))
        self.assertEqual(len({row["id"] for row in first["data"] + second["data"]}), 205)
        self.assertEqual(self.sql("SELECT COUNT(*) FROM ab_profile_peers WHERE guid='" + guid + "' AND id LIKE 'mysql-admin-page-%'"), ["205"])


if __name__ == "__main__":
    unittest.main()
