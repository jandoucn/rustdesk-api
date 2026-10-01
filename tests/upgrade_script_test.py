import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "upgrade-api.sh"


class UpgradeScriptTest(unittest.TestCase):
    def run_upgrade(self, health_status: str):
        with tempfile.TemporaryDirectory(prefix="rustdesk-upgrade-test-") as raw:
            temp = Path(raw)
            bin_dir = temp / "bin"
            bin_dir.mkdir()
            log = temp / "commands.log"
            (temp / "docker-compose.yaml").write_text("services: {api: {image: test}}\n", encoding="utf-8")
            docker = bin_dir / "docker"
            docker.write_text(
                textwrap.dedent(
                    """\
                    #!/usr/bin/env bash
                    set -eu
                    printf 'image=%s docker %s\\n' "${RUSTDESK_API_IMAGE:-}" "$*" >> "$COMMAND_LOG"
                    case "$*" in
                      "compose ps -q api") echo old-container ;;
                      "inspect old-container --format {{.Image}}") echo sha256:old ;;
                      "inspect old-container --format {{.Config.Image}}") echo registry/api:0.1.22 ;;
                      "inspect old-container --format "*Config.Env*) echo RUSTDESK_DB_DRIVER=sqlite ;;
                      "compose ps --status running -q api") echo new-container ;;
                      "compose exec -T api php -r "*) echo /yanolly/ ;;
                      "inspect new-container --format {{.Image}}") echo sha256:new ;;
                    esac
                    """
                ),
                encoding="utf-8",
            )
            curl = bin_dir / "curl"
            curl.write_text(
                "#!/usr/bin/env bash\nset -eu\nprintf 'curl %s\\n' \"$*\" >> \"$COMMAND_LOG\"\nprintf '%s' \"$HEALTH_STATUS\"\n",
                encoding="utf-8",
            )
            docker.chmod(0o755)
            curl.chmod(0o755)
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{bin_dir}:{env['PATH']}",
                    "COMMAND_LOG": str(log),
                    "HEALTH_STATUS": health_status,
                    "RUSTDESK_UPGRADE_HEALTH_TIMEOUT": "1",
                }
            )
            result = subprocess.run([str(SCRIPT), "v0.1.23"], cwd=temp, env=env, text=True, capture_output=True, timeout=10)
            return result, log.read_text(encoding="utf-8")

    def test_pulls_requested_tag_and_recreates_service(self):
        result, commands = self.run_upgrade("200")
        self.assertEqual(result.returncode, 0, result.stderr)
        image = "crpi-7xxhnenx29e9prnb.cn-hongkong.personal.cr.aliyuncs.com/ollydocker/rustdesk-api:0.1.23"
        self.assertIn(f"image={image} docker compose pull api", commands)
        self.assertIn(f"image={image} docker compose up -d --force-recreate api", commands)
        self.assertIn(image, result.stdout)
        self.assertNotIn("image=rustdesk-api-upgrade-rollback", commands)

    def test_rolls_back_when_health_check_fails(self):
        result, commands = self.run_upgrade("500")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("健康检查失败", result.stdout)
        self.assertIn("image=rustdesk-api-upgrade-rollback:", commands)
        self.assertIn("已恢复旧镜像", result.stderr)


if __name__ == "__main__":
    unittest.main()
