import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class DockerEntrypointTest(unittest.TestCase):
    def run_entrypoint(self, seed: Path, database: Path):
        with tempfile.TemporaryDirectory() as temp:
            bin_dir = Path(temp) / "bin"
            bin_dir.mkdir()
            php_fpm = bin_dir / "php-fpm"
            php_fpm.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
            php_fpm.chmod(0o755)
            chown = bin_dir / "chown"
            chown.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
            chown.chmod(0o755)
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{bin_dir}:{env['PATH']}",
                    "RUSTDESK_DATA_DIR": str(database.parent),
                    "RUSTDESK_GEOIP_DATABASE": str(database),
                    "RUSTDESK_GEOIP_SEED": str(seed),
                }
            )
            return subprocess.run(
                ["sh", str(ROOT / "config/docker-entrypoint.sh"), "true"],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                timeout=10,
            )

    def test_first_start_copies_bundled_geoip_into_data_directory(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            seed = root / "seed.mmdb"
            database = root / "data" / "GeoLite2-City.mmdb"
            seed.write_bytes(b"bundled-mmdb")

            result = self.run_entrypoint(seed, database)

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(database.read_bytes(), b"bundled-mmdb")

    def test_restart_keeps_existing_persistent_geoip(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            seed = root / "seed.mmdb"
            database = root / "data" / "GeoLite2-City.mmdb"
            seed.write_bytes(b"new-bundled-mmdb")
            database.parent.mkdir()
            database.write_bytes(b"existing-mmdb")

            result = self.run_entrypoint(seed, database)

            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(database.read_bytes(), b"existing-mmdb")


if __name__ == "__main__":
    unittest.main()
