#!/usr/bin/env python3
"""Regression tests for the binary file-to-MCP bridge (no external services)."""
import importlib.machinery
import importlib.util
import io
import json
import pathlib
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = pathlib.Path(__file__).resolve().parents[2] / "bin" / "devmcp-upload-file"
loader = importlib.machinery.SourceFileLoader("devmcp_upload_file", str(SCRIPT))
spec = importlib.util.spec_from_loader(loader.name, loader)
bridge = importlib.util.module_from_spec(spec)
loader.exec_module(bridge)


class Response:
    status = 200
    headers = {"Content-Type": "application/json", "Mcp-Session-Id": "session-1"}
    def __init__(self, data):
        self.data = data
    def __enter__(self):
        return self
    def __exit__(self, *_):
        return False
    def read(self, *_):
        return json.dumps(self.data).encode()


class BridgeTests(unittest.TestCase):
    def test_mcp_session_and_structured_content(self):
        calls = []
        def fake_open(req, timeout):
            body = json.loads(req.data)
            calls.append((body, dict(req.headers)))
            return Response({"jsonrpc": "2.0", "id": body["id"], "result": {
                "structuredContent": {"upload_id": "test-id"}, "content": []}})
        with patch.object(bridge.urllib.request, "urlopen", fake_open):
            client = bridge.McpClient("https://example.test/mcp", "secret")
            self.assertEqual(client.tool("workspace_upload", {"project": "demo"})["upload_id"], "test-id")
            client.tool("workspace_upload", {"project": "demo"})
        self.assertEqual(calls[0][0]["method"], "tools/call")
        self.assertEqual(calls[1][1]["Mcp-session-id"], "session-1")
        self.assertEqual(calls[0][1]["Authorization"], "Bearer secret")

    def test_mcp_error_is_not_success(self):
        def fake_open(req, timeout):
            body = json.loads(req.data)
            return Response({"jsonrpc": "2.0", "id": body["id"], "result": {
                "isError": True, "content": [{"type": "text", "text": "denied"}]}})
        with patch.object(bridge.urllib.request, "urlopen", fake_open):
            with self.assertRaises(RuntimeError):
                bridge.McpClient("https://example.test/mcp", None).tool("workspace_upload", {})

    def test_hash_of_binary_payload(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / "binary.dat"
            payload = bytes(range(256)) + b"\x00\xff"
            path.write_bytes(payload)
            import hashlib
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), hashlib.sha256(payload).hexdigest())


if __name__ == "__main__":
    unittest.main()
