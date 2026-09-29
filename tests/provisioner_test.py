#!/usr/bin/env python3
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

from installer.provisioner import DockerProvisioner, ProvisionError, validate_request


class ProvisionerTest(unittest.TestCase):
    def test_request_validation(self):
        self.assertEqual(validate_request({"project_name": "rustdesk-api", "api_container": "a" * 12}), ("rustdesk-api", "a" * 12))
        for data in ({}, {"project_name": "Bad_Name", "api_container": "a" * 12}, {"project_name": "ok", "api_container": "not-container"}):
            with self.subTest(data=data), self.assertRaises(ProvisionError):
                validate_request(data)

    def test_network_is_derived_from_api_container(self):
        payload = [{"NetworkSettings": {"Networks": {"bridge": {}, "rustdesk-net": {}}}}]
        runner = Mock(return_value=subprocess.CompletedProcess([], 0, json.dumps(payload), ""))
        with tempfile.TemporaryDirectory() as temp:
            provisioner = DockerProvisioner(Path(temp), runner)
            self.assertEqual(provisioner.inspect_network("a" * 12), "rustdesk-net")

    def test_completed_project_removes_secret_state_only(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "rustdesk-api.json"
            path.write_text('{"mysql_password":"secret"}')
            DockerProvisioner(Path(temp)).complete("rustdesk-api")
            self.assertFalse(path.exists())

    def test_mysql_uses_configured_accelerated_image(self):
        payload = [{"NetworkSettings": {"Networks": {"rustdesk-net": {}}}}]
        calls = []

        def runner(command, **kwargs):
            calls.append(command)
            if command[:2] == ["docker", "inspect"]:
                return subprocess.CompletedProcess(command, 0, json.dumps(payload), "")
            if command[:3] == ["docker", "exec", "rustdesk-api-mysql"]:
                return subprocess.CompletedProcess(command, 0, "mysqld is alive", "")
            return subprocess.CompletedProcess(command, 0, "ok", "")

        with tempfile.TemporaryDirectory() as temp:
            provisioner = DockerProvisioner(Path(temp), runner, "docker.1ms.run/mysql:8.4")
            provisioner.create_mysql("rustdesk-api", "a" * 12)
        run = next(command for command in calls if command[:2] == ["docker", "run"])
        self.assertEqual(run[-1], "docker.1ms.run/mysql:8.4")


if __name__ == "__main__":
    unittest.main()
