#!/usr/bin/env python3
"""Offline/loopback-only smoke for the inert static hosting example.

This does not contact, provision or modify caseRouter, ZeroTier or systemd.
"""
from functools import partial
from html.parser import HTMLParser
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
from urllib.error import HTTPError
from urllib.request import urlopen


ROOT = Path(__file__).resolve().parents[2]
PUBLIC = ROOT / "examples" / "remote-hosting-static" / "public"


class AssetInspector(HTMLParser):
    def __init__(self):
        super().__init__()
        self.external_refs = []
        self.scripts = 0

    def handle_starttag(self, tag, attrs):
        if tag == "script":
            self.scripts += 1
        for name, value in attrs:
            if name in {"href", "src", "action"} and value:
                if not value.startswith(("#", "/")):
                    self.external_refs.append(value)


class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


def main():
    assert PUBLIC.is_dir() and not PUBLIC.is_symlink()
    assert {p.name for p in PUBLIC.iterdir()} == {"index.html"}, "Unexpected published file"
    assert not (PUBLIC / "index.html").is_symlink()
    html = (PUBLIC / "index.html").read_text(encoding="utf-8")
    checker = AssetInspector()
    checker.feed(html)
    assert checker.scripts == 0 and not checker.external_refs, "Unexpected active/external content"
    assert "default-src 'none'" in html, "Missing offline CSP"
    assert "devMcp" in html

    handler = partial(QuietHandler, directory=str(PUBLIC))
    server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
    thread = Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        base = "http://127.0.0.1:" + str(server.server_port)
        with urlopen(base + "/", timeout=3) as response:
            assert response.status == 200
            assert response.headers.get_content_type() == "text/html"
            assert response.read().decode("utf-8") == html
        try:
            urlopen(base + "/missing", timeout=3)
        except HTTPError as exc:
            assert exc.code == 404
        else:
            raise AssertionError("Missing static file must return 404")
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)
    print("PASS: isolated static fixture, no external assets, loopback HTTP 200/404")


if __name__ == "__main__":
    main()
