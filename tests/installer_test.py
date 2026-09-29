import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class InstallerTest(unittest.TestCase):
    def run_installer(self, existing: bool, proxy: str, geo_source: Path):
        with tempfile.TemporaryDirectory() as temp:
            temp_path = Path(temp)
            bin_dir = temp_path / "bin"
            bin_dir.mkdir()
            log = temp_path / "docker.log"
            fake_docker = bin_dir / "docker"
            fake_docker.write_text(
                """#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> "$DOCKER_TEST_LOG"
case "${1:-} ${2:-}" in
  "login ") cat >/dev/null; exit 0 ;;
  "info "|"pull "|"network inspect"|"volume inspect"|"start ") exit 0 ;;
  "inspect -f")
    case "$3" in
      *NetworkSettings.Ports*) [[ "$INSTALLER_EXISTING" == 1 ]] && printf '17991\n' || exit 1 ;;
      *Config.Env*) printf 'RUSTDESK_TRUSTED_PROXY_IPS=old-proxy\n' ;;
      *Mounts*) printf '/old/GeoLite2-City.mmdb\n' ;;
      *State.Running*) printf 'true\n' ;;
    esac
    exit 0
    ;;
  "container inspect") [[ "$INSTALLER_EXISTING" == 1 ]] && exit 0 || exit 1 ;;
  "network create"|"volume create"|"rm -f"|"run -d"|"ps --format") exit 0 ;;
esac
exit 0
""",
                encoding="utf-8",
            )
            fake_docker.chmod(0o755)
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{bin_dir}:{env['PATH']}",
                    "DOCKER_TEST_LOG": str(log),
                    "INSTALLER_EXISTING": "1" if existing else "0",
                    "RUSTDESK_INSTALLER_TEST_ALLOW_NON_ROOT": "1",
                    "REGISTRY_USERNAME": "tester",
                    "REGISTRY_PASSWORD": "secret",
                    "RUSTDESK_PORT": "17991",
                    "RUSTDESK_TRUSTED_PROXY_IPS": proxy,
                    "RUSTDESK_GEOIP_SOURCE": str(geo_source),
                }
            )
            result = subprocess.run(
                ["bash", str(ROOT / "installer/install.sh")],
                cwd=ROOT,
                env=env,
                text=True,
                encoding="utf-8",
                errors="replace",
                capture_output=True,
                timeout=10,
            )
            return result, log.read_text(encoding="utf-8")

    def test_fresh_install_passes_proxy_and_read_only_geo_mount(self):
        with tempfile.TemporaryDirectory() as temp:
            geo = Path(temp) / "GeoLite2-City.mmdb"
            geo.write_bytes(b"test-mmdb")
            result, commands = self.run_installer(False, "172.17.0.1", geo)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("RUSTDESK_TRUSTED_PROXY_IPS=172.17.0.1", commands)
        self.assertIn(f"{geo}:/var/www/geoip/GeoLite2-City.mmdb:ro", commands)

    def test_existing_container_rejects_silently_ignored_configuration(self):
        with tempfile.TemporaryDirectory() as temp:
            geo = Path(temp) / "GeoLite2-City.mmdb"
            geo.write_bytes(b"test-mmdb")
            result, commands = self.run_installer(True, "172.17.0.1", geo)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("docker rm -f rustdesk-api", result.stderr)
        self.assertNotIn("start rustdesk-api", commands)


if __name__ == "__main__":
    unittest.main()
