#!/usr/bin/env python3
"""Loopback-only PHP hosting demo smoke: never touches caseRouter or ZeroTier."""
import json
from pathlib import Path
import socket
import subprocess
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


ROOT = Path(__file__).resolve().parents[2]
PUBLIC = ROOT / "examples" / "remote-hosting-php" / "public"


def get(url, method="GET"):
    with urlopen(Request(url, method=method), timeout=2) as response:
        return response.status, response.headers, response.read()


def assert_status(url, method, expected):
    try:
        status, _, _ = get(url, method)
    except HTTPError as exc:
        status = exc.code
    assert status == expected, f"{method} {url}: expected {expected}, got {status}"


def main():
    assert PUBLIC.is_dir() and not PUBLIC.is_symlink()
    assert {p.name for p in PUBLIC.iterdir()} == {"index.php"}, "Unexpected published file"
    assert not (PUBLIC / "index.php").is_symlink()

    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    base = f"http://127.0.0.1:{port}"
    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(PUBLIC)],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        for _ in range(40):
            if server.poll() is not None:
                raise AssertionError("PHP demo exited before readiness")
            try:
                status, headers, body = get(base + "/healthz")
                break
            except URLError:
                time.sleep(0.1)
        else:
            raise AssertionError("PHP demo did not start on loopback")

        assert status == 200
        assert headers.get_content_type() == "application/json"
        assert json.loads(body) == {"ok": True, "runtime": "php"}

        status, headers, body = get(base + "/")
        assert status == 200
        assert headers.get_content_type() == "text/html"
        assert b"devMcp" in body
        assert "default-src 'none'" in headers["Content-Security-Policy"]
        assert headers["X-Content-Type-Options"] == "nosniff"

        assert_status(base + "/unknown", "GET", 404)
        assert_status(base + "/healthz", "POST", 405)
        assert_status(base + "/healthz", "HEAD", 200)
        print("PASS: isolated PHP fixture, loopback HTTP 200/404/405, JSON health, CSP")
    finally:
        server.terminate()
        try:
            server.wait(timeout=3)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=3)


if __name__ == "__main__":
    main()
