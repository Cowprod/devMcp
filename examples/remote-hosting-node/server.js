"use strict";

// Standalone lab-only HTTP/WebSocket demo, no npm modules or filesystem I/O.
// Listen exclusively on the locally approved HOST/PORT supplied by systemd.
const crypto = require("node:crypto");
const http = require("node:http");
const net = require("node:net");

const host = process.env.HOST;
const portText = process.env.PORT || "";
const port = Number(portText);
if (net.isIP(host) !== 4 || host === "0.0.0.0" ||
    !/^[0-9]+$/.test(portText) || !Number.isInteger(port) ||
    port < 12000 || port > 19999) {
  throw new Error("HOST must be a specific IPv4 and PORT must be 12000..19999");
}

const page = Buffer.from(
  '<!doctype html><html lang="fr"><head><meta charset="utf-8">' +
  '<meta name="viewport" content="width=device-width,initial-scale=1">' +
  '<title>devMcp — Node/WebSocket</title></head><body>' +
  '<h1>devMcp — démonstrateur Node.js/WebSocket</h1>' +
  '<p>GET /healthz ; WebSocket /ws (écho de messages courts).</p>' +
  '</body></html>', "utf8"
);
const securityHeaders = {
  "Cache-Control": "no-store",
  "X-Content-Type-Options": "nosniff",
  "Content-Security-Policy": "default-src 'none'; base-uri 'none'; form-action 'none'"
};

const server = http.createServer((request, response) => {
  const method = request.method;
  if (method !== "GET" && method !== "HEAD") {
    response.writeHead(405, { ...securityHeaders, "Allow": "GET, HEAD" });
    response.end();
    return;
  }
  if (request.url === "/healthz") {
    response.writeHead(200, {
      ...securityHeaders, "Content-Type": "application/json; charset=utf-8"
    });
    response.end('{"ok":true,"runtime":"node","websocket":true}');
  } else if (request.url === "/") {
    response.writeHead(200, {
      ...securityHeaders, "Content-Type": "text/html; charset=utf-8"
    });
    response.end(page);
  } else {
    response.writeHead(404, securityHeaders);
    response.end();
  }
});

server.maxConnections = 32;
server.requestTimeout = 10000;
server.headersTimeout = 5000;
server.keepAliveTimeout = 1000;

function rejectUpgrade(socket, code = 400) {
  if (!socket.destroyed) {
    const reason = code === 503 ? "Service Unavailable" : "Bad Request";
    socket.end(`HTTP/1.1 ${code} ${reason}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n`);
  }
}

function sendFrame(socket, opcode, payload) {
  if (!socket.destroyed) {
    socket.write(Buffer.concat([Buffer.from([0x80 | opcode, payload.length]), payload]));
  }
}

function closeWebSocket(socket, code) {
  if (!socket.destroyed) {
    socket.end(Buffer.from([0x88, 0x02, code >> 8, code & 255]));
  }
}

let peers = 0;
server.on("upgrade", (request, socket, head) => {
  const connection = String(request.headers.connection || "")
    .toLowerCase().split(",").map((part) => part.trim());
  const key = request.headers["sec-websocket-key"];
  if (request.method !== "GET" || request.url !== "/ws" ||
      String(request.headers.upgrade || "").toLowerCase() !== "websocket" ||
      !connection.includes("upgrade") ||
      request.headers["sec-websocket-version"] !== "13" ||
      typeof key !== "string" || !/^[A-Za-z0-9+/]{22}==$/.test(key) ||
      Buffer.from(key, "base64").length !== 16) {
    rejectUpgrade(socket);
    return;
  }
  if (peers >= 16) {
    rejectUpgrade(socket, 503);
    return;
  }

  peers += 1;
  socket.once("close", () => { peers -= 1; });
  socket.setTimeout(15000, () => socket.end());
  const accept = crypto.createHash("sha1")
    .update(key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").digest("base64");
  socket.write(
    "HTTP/1.1 101 Switching Protocols\r\n" +
    "Upgrade: websocket\r\nConnection: Upgrade\r\n" +
    `Sec-WebSocket-Accept: ${accept}\r\n\r\n`
  );

  // Intentional limitation of this demo: single, masked, <=125-byte frames.
  // Reject fragmentation, RSV extensions, unmasked frames and large lengths.
  let pending = Buffer.alloc(0);
  let open = true;
  const fail = (code) => {
    if (open) {
      open = false;
      closeWebSocket(socket, code);
    }
  };
  const receive = (chunk) => {
    if (!open) return;
    if (pending.length + chunk.length > 4096) {
      fail(1009);
      return;
    }
    pending = Buffer.concat([pending, chunk]);
    while (pending.length >= 2) {
      const first = pending[0];
      const second = pending[1];
      const opcode = first & 0x0f;
      const length = second & 0x7f;
      if ((first & 0xf0) !== 0x80 || (second & 0x80) === 0) {
        fail(1002);
        return;
      }
      if (length > 125) {
        fail(1009);
        return;
      }
      if (pending.length < 6 + length) return;
      const mask = pending.subarray(2, 6);
      const payload = Buffer.alloc(length);
      for (let i = 0; i < length; i += 1) {
        payload[i] = pending[6 + i] ^ mask[i % 4];
      }
      pending = pending.subarray(6 + length);

      if (opcode === 0x1) {
        try {
          new TextDecoder("utf-8", { fatal: true }).decode(payload);
        } catch {
          fail(1007);
          return;
        }
        sendFrame(socket, 0x1, payload);
      } else if (opcode === 0x9) {
        sendFrame(socket, 0xA, payload);
      } else if (opcode === 0x8) {
        fail(1000);
        return;
      } else {
        fail(1003);
        return;
      }
    }
  };
  socket.on("data", receive);
  if (head.length) receive(head);
});

server.listen(port, host);
