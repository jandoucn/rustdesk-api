#!/usr/bin/env python3
from __future__ import annotations

import hmac
import json
import os
import re
import secrets
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any


NAME_RE = re.compile(r"^[a-z0-9][a-z0-9-]{1,62}$")
CONTAINER_RE = re.compile(r"^[a-f0-9]{12,64}$")


class ProvisionError(RuntimeError):
    pass


def validate_request(data: Any) -> tuple[str, str]:
    if not isinstance(data, dict):
        raise ProvisionError("请求必须为 JSON 对象")
    project = data.get("project_name", "rustdesk-api")
    container = data.get("api_container", "")
    if not isinstance(project, str) or not NAME_RE.fullmatch(project):
        raise ProvisionError("项目名称格式错误")
    if not isinstance(container, str) or not CONTAINER_RE.fullmatch(container):
        raise ProvisionError("API 容器标识格式错误")
    return project, container


def atomic_json(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    os.chmod(temporary, 0o600)
    os.replace(temporary, path)


class DockerProvisioner:
    def __init__(self, state_dir: Path, runner=subprocess.run):
        self.state_dir = state_dir
        self.runner = runner
        self.lock = threading.Lock()

    def run(self, command: list[str], timeout: int = 60) -> str:
        result = self.runner(command, text=True, capture_output=True, timeout=timeout)
        if result.returncode:
            raise ProvisionError((result.stderr or result.stdout or "Docker 操作失败").strip()[-1200:])
        return result.stdout.strip()

    def inspect_network(self, api_container: str) -> str:
        raw = self.run(["docker", "inspect", api_container])
        try:
            networks = json.loads(raw)[0]["NetworkSettings"]["Networks"]
            names = [name for name in networks if name not in {"bridge", "host", "none"}]
            return names[0] if names else next(iter(networks))
        except (KeyError, IndexError, StopIteration, TypeError, json.JSONDecodeError):
            raise ProvisionError("无法识别 API 容器网络")

    def create_mysql(self, project: str, api_container: str) -> dict[str, Any]:
        with self.lock:
            state_path = self.state_dir / f"{project}.json"
            if state_path.exists():
                state = json.loads(state_path.read_text(encoding="utf-8"))
                self.run(["docker", "inspect", state["mysql_host"]])
                return state
            network = self.inspect_network(api_container)
            container = f"{project}-mysql"
            volume = f"{project}-mysql-data"
            state = {
                "database": "mysql", "mysql_host": container, "mysql_port": 3306,
                "mysql_database": "rustdesk", "mysql_user": "rustdesk",
                "mysql_password": secrets.token_urlsafe(32), "mysql_root_password": secrets.token_urlsafe(32),
                "container": container, "volume": volume, "network": network,
            }
            atomic_json(state_path, state)
            self.run(["docker", "volume", "create", volume])
            self.run([
                "docker", "run", "-d", "--name", container, "--restart", "unless-stopped",
                "--network", network, "-e", "MYSQL_DATABASE=rustdesk", "-e", "MYSQL_USER=rustdesk",
                "-e", f"MYSQL_PASSWORD={state['mysql_password']}",
                "-e", f"MYSQL_ROOT_PASSWORD={state['mysql_root_password']}",
                "-v", f"{volume}:/var/lib/mysql", "mysql:8.4",
            ], timeout=180)
            deadline = time.time() + 180
            while time.time() < deadline:
                result = self.runner(["docker", "exec", container, "mysqladmin", "ping", "-h", "127.0.0.1",
                                      "-uroot", f"-p{state['mysql_root_password']}", "--silent"],
                                     text=True, capture_output=True, timeout=15)
                if result.returncode == 0:
                    return state
                time.sleep(2)
            raise ProvisionError("MySQL 容器健康检查超时")

    def complete(self, project: str) -> None:
        if not NAME_RE.fullmatch(project):
            raise ProvisionError("项目名称格式错误")
        (self.state_dir / f"{project}.json").unlink(missing_ok=True)


class ProvisionerHandler(BaseHTTPRequestHandler):
    server_version = "RustDeskProvisioner/1"
    provisioner: DockerProvisioner
    secret: str

    def respond(self, value: Any, status: int = 200) -> None:
        body = json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(body)

    def authorized(self) -> bool:
        supplied = self.headers.get("Authorization", "")
        return hmac.compare_digest(supplied, f"Bearer {self.secret}")

    def do_GET(self):
        if self.path == "/healthz":
            return self.respond({"ok": True})
        self.respond({"error": "接口不存在"}, 404)

    def do_POST(self):
        if not self.authorized():
            return self.respond({"error": "认证失败"}, 401)
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length < 2 or length > 8192:
                raise ProvisionError("请求大小错误")
            data = json.loads(self.rfile.read(length))
            if self.path == "/v1/mysql":
                project, container = validate_request(data)
                result = self.provisioner.create_mysql(project, container)
                safe = {key: value for key, value in result.items() if key not in {"mysql_root_password", "container", "volume", "network"}}
                return self.respond(safe, 201)
            if self.path == "/v1/complete":
                project = data.get("project_name", "rustdesk-api") if isinstance(data, dict) else ""
                self.provisioner.complete(project)
                self.respond({"ok": True})
                threading.Thread(target=self._self_remove, daemon=True).start()
                return
            return self.respond({"error": "接口不存在"}, 404)
        except (ProvisionError, json.JSONDecodeError) as error:
            return self.respond({"error": str(error)}, 422)

    @staticmethod
    def _self_remove() -> None:
        time.sleep(2)
        container = os.environ.get("HOSTNAME", "")
        if CONTAINER_RE.fullmatch(container):
            subprocess.Popen(["docker", "rm", "-f", container], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def log_message(self, format_string: str, *args: Any) -> None:
        print("provisioner:", format_string % args, flush=True)


def main() -> None:
    secret = os.environ.get("PROVISIONER_SECRET", "")
    if len(secret) < 32:
        raise SystemExit("PROVISIONER_SECRET must contain at least 32 characters")
    handler = ProvisionerHandler
    handler.secret = secret
    handler.provisioner = DockerProvisioner(Path(os.environ.get("PROVISIONER_STATE", "/state")))
    ThreadingHTTPServer(("0.0.0.0", 8080), handler).serve_forever()


if __name__ == "__main__":
    main()
