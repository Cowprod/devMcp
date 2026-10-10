#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this installer as root." >&2
    exit 2
fi

VENV=/opt/devmcp-tools/platformio
VERSION=6.2.0

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y python3-venv ca-certificates

install -d -o root -g root -m 0755 /opt/devmcp-tools
if [ ! -x "$VENV/bin/python" ]; then
    python3 -m venv "$VENV"
fi

"$VENV/bin/python" -m pip install --disable-pip-version-check --upgrade "platformio==$VERSION"
"$VENV/bin/pio" --version
