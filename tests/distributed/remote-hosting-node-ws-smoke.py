#!/usr/bin/env python3
"""Inert Node/WebSocket fixture smoke. Only binds 127.0.0.1 (never ZeroTier)."""
import base64
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


ROOT = Path(__file__).resolve().parents[2]
DEMO = ROOT / "examples" / "remote-hosting-node"
GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


def available_port():
    for port in range(17600, 17700):
        with socket.socket() as sock:
            try:
                sock.bind(("127.0.0.1", port))
            except OSError:
                continue
            return port
    raise AssertionError("No free test port in 17600..17699")


def read_exact(sock, length):
    data = bytearray()
    while len(data) < length:
        chunk = sock.recv(length - len(data))
        if not chunk:
            raise AssertionError("Socket closed before frame was complete")
        data.extend(chunk)
    return bytes(data)


def masked_frame(opcode, payload):
    assert len(payload) <= 125
    key = b"\x12\x34\x56\x78"
    masked = bytes(byte ^ key[index % 4] for index, byte in enumerate(payload))
    return bytes([0x80 | opcode, 0x80 | len(payload)]) + key + masked


def expect_ws_frame(sock, opcode, body):
    head = read_exact(sock, 2)
    assert head == bytes([0x80 | opcode, len(body)]), head
    assert read_exact(sock, len(body)) == body


def status(url, method):
    try:
        with urlopen(Request(url, method=method), timeout=2) as response:
            return response.status
    except HTTPError as exc:
        return exc.code


def websocket_test(host, port):
    key = base64.b64encode(b"0123456789abcdef").decode("ascii")
    with socket.create_connection((host, port), timeout=3) as sock:
        sock.settimeout(3)
        request = (
            f"GET /ws HTTP/1.1\r\nHost: {host}:{port}\r\n"
            "Upgrade: websocket\r\nConnection: keep-alive, Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n"
        ).encode("ascii")
        sock.sendall(request)
        response = bytearray()
        while b"\r\n\r\n" not in response:
            response.extend(sock.recv(1))
            assert len(response) < 2048
        expected = base64.b64encode(
            hashlib.sha1((key + GUID).encode("ascii")).digest()
        ).decode("ascii")
        assert b"HTTP/1.1 101 Switching Protocols" in response
        assert f"Sec-WebSocket-Accept: {expected}".encode("ascii") in response

        sock.sendall(masked_frame(1, b"hello devMcp"))
        expect_ws_frame(sock, 1, b"hello devMcp")
        sock.sendall(masked_frame(9, b"!"))
        expect_ws_frame(sock, 10, b"!")
        sock.sendall(masked_frame(8, b""))


def main():
    assert DEMO.is_dir() and not DEMO.is_symlink()
    assert {path.name for path in DEMO.iterdir() if path.is_file()} == {"server.js"}
    assert not (DEMO / "server.js").is_symlink()
    port = available_port()
    env = {**os.environ, "HOST": "127.0.0.1", "PORT": str(port)}

    invalid = subprocess.run(
        ["node", str(DEMO / "server.js")],
        env={**env, "HOST": "0.0.0.0"},
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=5,
        check=False,
    )
    assert invalid.returncode != 0, "Wildcard host must be refused"

    server = subprocess.Popen(
        ["node", str(DEMO / "server.js")],
        env=env, stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    try:
        base = f"http://127.0.0.1:{port}"
        for _ in range(40):
            if server.poll() is not None:
                raise AssertionError("Node demo exited before readiness")
            try:
                with urlopen(base + "/healthz", timeout=2) as response:
                    health = json.loads(response.read())
                    assert response.status == 200
                    break
            except URLError:
                time.sleep(0.1)
        else:
            raise AssertionError("Node demo did not start on loopback")

        assert health == {"ok": True, "runtime": "node", "websocket": True}
        with urlopen(base + "/", timeout=2) as response:
            assert response.status == 200
            assert b"Node.js/WebSocket" in response.read()
            assert "default-src 'none'" in response.headers["Content-Security-Policy"]
        assert status(base + "/missing", "GET") == 404
        assert status(base + "/healthz", "POST") == 405
        websocket_test("127.0.0.1", port)
        print("PASS: Node HTTP 200/404/405, wildcard rejection, WS handshake/echo/ping")
    finally:
        server.terminate()
        try:
            server.wait(timeout=3)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=3)


if __name__ == "__main__":
    main()
