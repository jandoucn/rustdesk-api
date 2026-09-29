import hashlib
import os
import subprocess
import tarfile
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class OfflineBundleTest(unittest.TestCase):
    def test_export_pulls_latest_images_and_builds_verified_bundle(self):
        with tempfile.TemporaryDirectory() as temp:
            temp_path = Path(temp)
            bin_dir = temp_path / "bin"
            bin_dir.mkdir()
            docker_log = temp_path / "docker.log"
            fake_docker = bin_dir / "docker"
            fake_docker.write_text(
                """#!/usr/bin/env bash
set -eu
printf '%s\\n' "$*" >> "$DOCKER_TEST_LOG"
case " $* " in
  *" login "*) cat >/dev/null ;;
  *" save "*) printf 'fake-docker-image-archive' ;;
  *" image inspect "*)
    printf 'image=ghcr.io/jandoucn/test:latest id=sha256:test os=linux architecture=amd64 digests=ghcr.io/jandoucn/test@sha256:test\\n'
    ;;
esac
""",
                encoding="utf-8",
            )
            fake_docker.chmod(0o755)

            output = temp_path / "offline.tar"
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{bin_dir}:{env['PATH']}",
                    "DOCKER_TEST_LOG": str(docker_log),
                    "GHCR_TOKEN": "test-token",
                }
            )
            subprocess.run(
                [str(ROOT / "installer/export-offline-bundle.sh"), str(output)],
                cwd=ROOT,
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )

            commands = docker_log.read_text(encoding="utf-8")
            self.assertIn(
                "pull --platform linux/amd64 ghcr.io/jandoucn/rustdesk-api:latest",
                commands,
            )
            self.assertIn(
                "pull --platform linux/amd64 ghcr.io/jandoucn/rustdesk-api-provisioner:latest",
                commands,
            )

            expected = hashlib.sha256(output.read_bytes()).hexdigest()
            checksum = output.with_suffix(".tar.sha256").read_text(encoding="utf-8")
            self.assertEqual(
                checksum,
                f"{expected}  {output.name}\n",
            )

            with tarfile.open(output) as archive:
                names = {name.removeprefix("./") for name in archive.getnames()}
                self.assertTrue(
                    {
                        "images.tar.gz",
                        "install.sh",
                        "install-offline-bundle.sh",
                        "manifest.txt",
                        "SHA256SUMS",
                    }.issubset(names)
                )


if __name__ == "__main__":
    unittest.main()
