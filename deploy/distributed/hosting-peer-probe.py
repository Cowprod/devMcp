#!/usr/bin/env python3
"""Bounded, read-only health probe executed FROM a different ZeroTier peer.

Requires an independent Linux ZeroTier peer with iproute2 (/usr/sbin/ip).
This tool does not commit a deployment, supply local root authorization,
change routes, or run on caseRouter as part of its activation transaction.
It prints a machine-readable observation that an operator can corroborate.
"""
import argparse
import base64
import hashlib
import hmac
import ipaddress
import json
import os
import socket
import stat
import time
import re
import subprocess

GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
MARKERS = {
    "static": "devMcp — hébergement statique",
    "php": "devMcp — hébergement PHP",
    "node": "devMcp — démonstrateur Node.js/WebSocket",
}
# The signing credential is dedicated to these three bounded lab grants.
# Never sign a peer observation for a different project/port binding.
LAB_BINDINGS = {
    "static": ("lab-static", 17601),
    "php": ("lab-php", 17602),
    "node": ("lab-node", 17603),
}


def exact(sock, count):
    result = bytearray()
    while len(result) < count:
        chunk = sock.recv(count - len(result))
        if not chunk:
            raise RuntimeError("Truncated WebSocket response")
        result.extend(chunk)
    return bytes(result)


def websocket_echo(sock, host, port):
    key = base64.b64encode(os.urandom(16)).decode("ascii")
    request = (
        f"GET /ws HTTP/1.1\r\nHost: {host}:{port}\r\n"
        "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n"
    )
    sock.sendall(request.encode("ascii"))
    headers = bytearray()
    while not headers.endswith(b"\r\n\r\n"):
        headers.extend(exact(sock, 1))
        if len(headers) > 2048:
            raise RuntimeError("Oversized WebSocket headers")
    lines = headers.decode("ascii").split("\r\n")
    if lines[0] != "HTTP/1.1 101 Switching Protocols":
        raise RuntimeError("WebSocket upgrade rejected")
    fields = {}
    for line in lines[1:]:
        if ":" in line:
            name, value = line.split(":", 1)
            fields[name.strip().lower()] = value.strip()
    expected = base64.b64encode(
        hashlib.sha1((key + GUID).encode("ascii")).digest()
    ).decode("ascii")
    if (fields.get("sec-websocket-accept") != expected
            or fields.get("upgrade", "").lower() != "websocket"
            or "upgrade" not in [v.strip() for v in fields.get("connection", "").lower().split(",")]):
        raise RuntimeError("Untrusted WebSocket handshake")
    payload = b"devmcp-remote-probe-" + os.urandom(8).hex().encode("ascii")
    mask = os.urandom(4)
    frame = bytes([0x81, 0x80 | len(payload)]) + mask + bytes(
        byte ^ mask[index % 4] for index, byte in enumerate(payload)
    )
    sock.sendall(frame)
    if exact(sock, 2) != bytes([0x81, len(payload)]):
        raise RuntimeError("Unexpected WebSocket echo frame")
    if exact(sock, len(payload)) != payload:
        raise RuntimeError("WebSocket echo mismatch")



def assert_zerotier_route(target, peer):
    """Fail closed unless the independent Linux peer will use its zt* adapter.

    Two distinct RFC1918 addresses are NOT sufficient evidence of ZeroTier:
    a response from the ordinary LAN must never be HMAC-signed as a ZeroTier
    peer observation. These iproute2 commands are read-only; no routes change.
    """
    def ip_json(*args):
        try:
            response = subprocess.run(
                ["/usr/sbin/ip", "-j", "-4", *args], check=True,
                text=True, capture_output=True, timeout=3,
            )
            decoded = json.loads(response.stdout)
        except (OSError, subprocess.SubprocessError, ValueError) as exc:
            raise RuntimeError("ZeroTier route inspection unavailable") from exc
        if not isinstance(decoded, list):
            raise RuntimeError("Invalid ZeroTier route inspection response")
        return decoded

    routes = ip_json("route", "get", target, "from", peer)
    if len(routes) != 1 or not isinstance(routes[0], dict):
        raise RuntimeError("Ambiguous peer route")
    interface = routes[0].get("dev")
    if not isinstance(interface, str) or not re.fullmatch(r"zt[A-Za-z0-9]+", interface):
        raise RuntimeError("Peer route is not a ZeroTier interface")

    adapters = ip_json("addr", "show", "dev", interface)
    if len(adapters) != 1 or adapters[0].get("ifname") != interface:
        raise RuntimeError("ZeroTier adapter missing")
    addresses = adapters[0].get("addr_info", [])
    if not isinstance(addresses, list) or not any(
        isinstance(address, dict) and address.get("family") == "inet"
        and address.get("local") == peer for address in addresses
    ):
        raise RuntimeError("Peer source IPv4 is not assigned to the ZeroTier adapter")


def probe(runtime, target, port, peer):
    target_ip = ipaddress.ip_address(target)
    peer_ip = ipaddress.ip_address(peer)
    if (target_ip.version != 4 or peer_ip.version != 4
            or not target_ip.is_private or not peer_ip.is_private
            or target_ip.is_loopback or peer_ip.is_loopback
            or target_ip.is_link_local or peer_ip.is_link_local
            or target_ip == peer_ip):
        raise ValueError("Distinct private IPv4 ZeroTier addresses required")
    # A private LAN route must never be accepted as signed ZeroTier evidence.
    assert_zerotier_route(target, peer)
    # Bind to the explicit source address: the OS must actually own it.
    # This rejects a local loopback-only smoke test as a peer observation.
    source = (peer, 0)
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as connection:
        connection.settimeout(4)
        connection.bind(source)
        connection.connect((target, port))
        if connection.getsockname()[0] != peer:
            raise RuntimeError("Probe source IP differs from expected peer")
        connection.close()

    # urllib has no source_address option; force a bounded HTTPConnection.
    from http.client import HTTPConnection
    connection = HTTPConnection(target, port, timeout=4, source_address=source)
    try:
        path = "/" if runtime == "static" else "/healthz"
        connection.request("GET", path, headers={"Connection": "close"})
        response = connection.getresponse()
        body = response.read(8193)
        if response.status != 200 or len(body) > 8192:
            raise RuntimeError("Unexpected HTTP health status/body")
        if runtime == "static":
            if MARKERS[runtime] not in body.decode("utf-8"):
                raise RuntimeError("Static demo marker missing")
        else:
            expected = {"ok": True, "runtime": runtime}
            if runtime == "node":
                expected["websocket"] = True
            if json.loads(body) != expected:
                raise RuntimeError("Wrong HTTP health payload")
    finally:
        connection.close()

    if runtime == "node":
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
            sock.settimeout(4)
            sock.bind(source)
            sock.connect((target, port))
            websocket_echo(sock, target, port)
    return {
        "ok": True, "runtime": runtime, "target_ip": target,
        "source_peer_ip": peer, "port": port, "http_verified": True,
        "websocket_verified": runtime == "node",
        "deployment_committed": False,
        "note": "Observation only; not a signed authorization to commit",
    }


def read_signing_key(path):
    """Read the exact owner-only 32-byte key from the opened regular inode.

    O_NOFOLLOW rejects a symlink even if the path changes between lookup and
    open; O_NONBLOCK prevents a malicious FIFO from blocking before fstat.
    Never trust pre-open lstat evidence for signing material.
    """
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    try:
        metadata = os.fstat(fd)
        if (not stat.S_ISREG(metadata.st_mode)
                or stat.S_IMODE(metadata.st_mode) != 0o600
                or metadata.st_uid != os.geteuid()
                or metadata.st_nlink != 1
                or metadata.st_size != 32):
            raise ValueError("Untrusted peer signing key")
        key = os.read(fd, 33)
        if len(key) != 32:
            raise ValueError("Invalid peer signing key size")
        return key
    finally:
        os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime", choices=tuple(MARKERS), required=True)
    parser.add_argument("--target-ip", required=True)
    parser.add_argument("--peer-ip", required=True)
    parser.add_argument("--port", required=True, type=int)
    parser.add_argument("--operation-id", required=True)
    parser.add_argument("--deployment-id", required=True)
    parser.add_argument("--machine-id", required=True)
    parser.add_argument("--project-id", required=True)
    parser.add_argument("--revision", required=True)
    parser.add_argument("--key-file", required=True)
    args = parser.parse_args()
    expected_project, expected_port = LAB_BINDINGS[args.runtime]
    if args.port != expected_port or args.project_id != expected_project:
        parser.error("runtime/project/port must match the fixed caseRouter lab grant")
    for name, length in (("operation_id", 32), ("deployment_id", 32),
                         ("machine_id", 32), ("revision", 40)):
        if not re.fullmatch("[a-f0-9]{" + str(length) + "}",
                            getattr(args, name)):
            parser.error("invalid " + name)
    if not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,63}", args.project_id):
        parser.error("invalid project_id")
    try:
        key = read_signing_key(args.key_file)
        probe(args.runtime, args.target_ip, args.port, args.peer_ip)
        result = {
            "version": 1,
            "operation_id": args.operation_id,
            "deployment_id": args.deployment_id,
            "machine_id": args.machine_id,
            "project_id": args.project_id,
            "revision": args.revision,
            "runtime": args.runtime,
            "target_ip": args.target_ip,
            "source_peer_ip": args.peer_ip,
            "port": args.port,
            "observed_at": int(time.time()),
            "http_verified": True,
            "websocket_verified": args.runtime == "node",
        }
        canonical = json.dumps(result, sort_keys=True, separators=(",", ":"),
                               ensure_ascii=False).encode("utf-8")
        result["signature"] = hmac.new(key, canonical, hashlib.sha256).hexdigest()
    except (OSError, ValueError, RuntimeError, UnicodeError, json.JSONDecodeError) as exc:
        print(json.dumps({"ok": False, "error": type(exc).__name__,
                          "deployment_committed": False}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
