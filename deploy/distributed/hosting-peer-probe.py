#!/usr/bin/env python3
"""Bounded, read-only health probe executed FROM a different ZeroTier peer.

This tool does not commit a deployment, supply local root authorization,
change routes, or run on caseRouter as part of its activation transaction.
It prints a machine-readable observation that an operator can corroborate.
"""
import argparse
import base64
import hashlib
import ipaddress
import json
import os
import socket

GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
MARKERS = {
    "static": "devMcp — hébergement statique",
    "php": "devMcp — hébergement PHP",
    "node": "devMcp — démonstrateur Node.js/WebSocket",
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


def probe(runtime, target, port, peer):
    target_ip = ipaddress.ip_address(target)
    peer_ip = ipaddress.ip_address(peer)
    if (target_ip.version != 4 or peer_ip.version != 4
            or not target_ip.is_private or not peer_ip.is_private
            or target_ip.is_loopback or peer_ip.is_loopback
            or target_ip.is_link_local or peer_ip.is_link_local
            or target_ip == peer_ip):
        raise ValueError("Distinct private IPv4 ZeroTier addresses required")
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


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime", choices=tuple(MARKERS), required=True)
    parser.add_argument("--target-ip", required=True)
    parser.add_argument("--peer-ip", required=True)
    parser.add_argument("--port", required=True, type=int)
    args = parser.parse_args()
    if args.port < 12000 or args.port > 19999:
        parser.error("port must be 12000..19999")
    try:
        result = probe(args.runtime, args.target_ip, args.port, args.peer_ip)
    except (OSError, ValueError, RuntimeError, UnicodeError, json.JSONDecodeError) as exc:
        print(json.dumps({"ok": False, "error": type(exc).__name__,
                          "deployment_committed": False}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
