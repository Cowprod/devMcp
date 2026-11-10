#!/usr/bin/env python3
"""Contract-only test: real Python signing -> real PHP receipt verifier.

Network I/O is deliberately mocked: this proves serialization, exact grants
and key-file safety, NOT a successful ZeroTier or caseRouter deployment.
"""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
PROBE_PATH = ROOT / "deploy/distributed/hosting-peer-probe.py"
spec = importlib.util.spec_from_file_location("hosting_peer_probe", PROBE_PATH)
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)

KEY = bytes(range(32))
DEPLOYMENT = "a" * 32
MACHINE = "b" * 32
REVISION = "c" * 40
NONCE = "d" * 32
TARGET = "192.168.194.189"
PEER = "192.168.194.188"

PHP_CHECK = r"""
require $argv[1];
$proof = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$plan = [
    'deployment_id' => str_repeat('a', 32),
    'machine_id' => str_repeat('b', 32),
    'project_id' => $argv[3],
    'revision' => str_repeat('c', 40),
    'runtime' => $argv[4],
    'bind_ipv4' => '192.168.194.189',
    'port' => (int) $argv[5],
];
\Cowprod\DevMcp\Project\RemoteHostingPeerEvidence::verifyClaim(
    $plan, str_repeat('d', 32), $proof, '192.168.194.188',
    hex2bin($argv[2]), time()
);
echo "PASS\n";
"""


class PeerProbeContract(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.key_path = Path(self.temp.name) / "peer.key"
        self.key_path.write_bytes(KEY)
        self.key_path.chmod(0o600)

    def args(self, runtime="static", project=None, port=None):
        expected_project, expected_port = probe.LAB_BINDINGS[runtime]
        return [
            str(PROBE_PATH), "--runtime", runtime,
            "--target-ip", TARGET, "--peer-ip", PEER,
            "--port", str(expected_port if port is None else port),
            "--operation-id", NONCE, "--deployment-id", DEPLOYMENT,
            "--machine-id", MACHINE, "--project-id",
            expected_project if project is None else project,
            "--revision", REVISION, "--key-file", str(self.key_path),
        ]

    def invoke(self, runtime="static", project=None, port=None, side_effect=None):
        stdout = io.StringIO()
        with (mock.patch.object(sys, "argv", self.args(runtime, project, port)),
              mock.patch.object(probe, "probe", side_effect=side_effect, return_value={}) as network,
              contextlib.redirect_stdout(stdout)):
            result = probe.main()
        return result, json.loads(stdout.getvalue()), network

    def php_verify(self, receipt, runtime):
        project, port = probe.LAB_BINDINGS[runtime]
        return subprocess.run(
            [
                "php", "-r", PHP_CHECK,
                str(ROOT / "src/Project/RemoteHostingPeerEvidence.php"),
                KEY.hex(), project, runtime, str(port),
            ],
            input=json.dumps(receipt), text=True, capture_output=True,
            timeout=5, check=False,
        )

    def test_key_open_checks_the_actual_inode(self):
        self.assertEqual(KEY, probe.read_signing_key(str(self.key_path)))
        self.key_path.chmod(0o644)
        with self.assertRaises(ValueError):
            probe.read_signing_key(str(self.key_path))
        self.key_path.chmod(0o600)

        linked = Path(self.temp.name) / "linked.key"
        linked.symlink_to(self.key_path)
        with self.assertRaises(OSError):
            probe.read_signing_key(str(linked))

        hardlink = Path(self.temp.name) / "hardlink.key"
        os.link(self.key_path, hardlink)
        with self.assertRaises(ValueError):
            probe.read_signing_key(str(self.key_path))
        hardlink.unlink()

        fifo = Path(self.temp.name) / "fifo.key"
        os.mkfifo(fifo, mode=0o600)
        with self.assertRaises((OSError, ValueError)):
            probe.read_signing_key(str(fifo))

    def test_zerotier_route_is_required_for_signed_network_observation(self):
        valid_route = [{"dev": "ztabcd1234", "prefsrc": PEER}]
        valid_address = [{
            "ifname": "ztabcd1234",
            "addr_info": [{"family": "inet", "local": PEER}],
        }]

        def mocked_ip(route, address):
            return [
                mock.Mock(stdout=json.dumps(route)),
                mock.Mock(stdout=json.dumps(address)),
            ]

        with mock.patch.object(
            probe.subprocess, "run",
            side_effect=mocked_ip(valid_route, valid_address),
        ) as ip:
            probe.assert_zerotier_route(TARGET, PEER)
            self.assertEqual(2, ip.call_count)
            self.assertEqual(
                ["/usr/sbin/ip", "-j", "-4", "route", "get", TARGET, "from", PEER],
                ip.call_args_list[0].args[0],
            )
            self.assertEqual(
                ["/usr/sbin/ip", "-j", "-4", "addr", "show", "dev", "ztabcd1234"],
                ip.call_args_list[1].args[0],
            )
            self.assertTrue(all(not call.kwargs.get("shell", False)
                                for call in ip.call_args_list))

        # Two private addresses on ordinary Ethernet do not prove ZeroTier.
        with (mock.patch.object(probe.subprocess, "run",
                                return_value=mock.Mock(
                                    stdout=json.dumps([{"dev": "eth0"}]))) as ip,
              self.assertRaises(RuntimeError)):
            probe.assert_zerotier_route(TARGET, PEER)
        ip.assert_called_once()

        # A zt* route with a different address cannot impersonate this peer.
        different_address = [{
            "ifname": "ztabcd1234",
            "addr_info": [{"family": "inet", "local": "192.168.194.187"}],
        }]
        with (mock.patch.object(probe.subprocess, "run",
                                side_effect=mocked_ip(valid_route, different_address)),
              self.assertRaises(RuntimeError)):
            probe.assert_zerotier_route(TARGET, PEER)

        # A missing iproute2 command must fail closed, not permit signing.
        with (mock.patch.object(probe.subprocess, "run",
                                side_effect=FileNotFoundError()),
              self.assertRaises(RuntimeError)):
            probe.assert_zerotier_route(TARGET, PEER)

    def test_wrong_route_rejected_before_any_health_request(self):
        with (mock.patch.object(probe, "assert_zerotier_route",
                                side_effect=RuntimeError("LAN only")) as route,
              mock.patch.object(probe.socket, "socket") as network,
              self.assertRaises(RuntimeError)):
            probe.probe("static", TARGET, 17601, PEER)
        route.assert_called_once_with(TARGET, PEER)
        network.assert_not_called()

    def test_python_signatures_are_accepted_by_real_php_for_each_lab(self):
        for runtime in probe.LAB_BINDINGS:
            with self.subTest(runtime=runtime):
                status, receipt, network = self.invoke(runtime)
                self.assertEqual(0, status)
                network.assert_called_once()
                self.assertEqual(probe.LAB_BINDINGS[runtime][1], receipt["port"])
                self.assertNotIn("deployment_committed", receipt)
                self.assertEqual(64, len(receipt["signature"]))
                verification = self.php_verify(receipt, runtime)
                self.assertEqual(
                    0, verification.returncode,
                    f"PHP contract mismatch for {runtime}: {verification.stderr}",
                )
                receipt["operation_id"] = "e" * 32
                self.assertNotEqual(0, self.php_verify(receipt, runtime).returncode)

    def test_mismatched_grant_refused_before_network_or_signing(self):
        for runtime, (project, port) in probe.LAB_BINDINGS.items():
            with self.subTest(runtime=runtime):
                for invalid_project, invalid_port in [
                    (project + "-other", port),
                    (project, port + 1),
                ]:
                    with (mock.patch.object(sys, "argv",
                                            self.args(runtime, invalid_project, invalid_port)),
                          mock.patch.object(probe, "probe") as network,
                          contextlib.redirect_stderr(io.StringIO()),
                          self.assertRaises(SystemExit) as exit_error):
                        probe.main()
                    self.assertEqual(2, exit_error.exception.code)
                    network.assert_not_called()

    def test_failed_network_observation_never_signs(self):
        code, failure, network = self.invoke(side_effect=RuntimeError("unreachable"))
        self.assertEqual(1, code)
        network.assert_called_once()
        self.assertEqual(False, failure["ok"])
        self.assertEqual(False, failure["deployment_committed"])
        self.assertNotIn("signature", failure)


if __name__ == "__main__":
    unittest.main()
