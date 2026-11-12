#!/usr/bin/env python3
"""Regression checks for the non-mutating case hosting preflight."""
from pathlib import Path

script = Path(__file__).resolve().parents[2] / "deploy/distributed/case-hosting-preflight.sh"
source = script.read_text(encoding="utf-8")
required = (
    "set -euo pipefail",
    "/etc/devmcp/agent.php",
    "/etc/devmcp/hosting-policy.json",
    "systemctl is-active zerotier-one.service",
    "systemctl list-units",
    "command -v",
    "PREFLIGHT COMPLETE",
)
for marker in required:
    assert marker in source, f"missing check: {marker}"
for forbidden in (
    "systemctl start ",
    "systemctl enable ",
    "systemctl restart ",
    "systemctl daemon-reload",
    "apt-get ",
    "iptables ",
    "nft ",
    "ip route add",
    "curl ",
    "wget ",
):
    assert forbidden not in source, f"mutating command in preflight: {forbidden}"
print("case hosting preflight static contract: PASS")
