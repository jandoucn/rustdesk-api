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


if __name__ == "__main__":
    unittest.main()
