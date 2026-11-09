#!/usr/bin/env bash
# Explicit local-root upgrade of an ALREADY authorized hosting gateway.
# The agent updater only stages inert bundles; it never calls this script.
# No application, router service or network setting is changed here.
set -euo pipefail
umask 077

if [[ "$(id -u)" != 0 || "$#" != 2 ]]; then
  echo 'Usage (root): promote-hosting-active.sh check|promote SHA' >&2
  exit 2
fi
mode="$1"
revision="$2"
if [[ "$mode" != check && "$mode" != promote ]] || [[ ! "$revision" =~ ^[a-f0-9]{40}$ ]]; then
  echo 'Invalid hosting gateway upgrade request' >&2
  exit 2
fi

root=/opt/devmcp-agent/bin
bundles="$root/hosting-bundles"
active="$root/hosting-active"
next="hosting-bundles/$revision"

trusted_dir() {
  [[ -d "$1" && ! -L "$1" && "$(stat -c %u "$1")" == 0
      && "$(stat -c %a "$1")" == 755 ]]
}
trusted_file() {
  [[ -f "$1" && ! -L "$1" && "$(stat -c %u "$1")" == 0
      && "$(stat -c %h "$1")" == 1 && "$(stat -c %a "$1")" == 755 ]]
}
trusted_bundle() {
  trusted_dir "$bundles/$1" && trusted_dir "$bundles/$1/Project" &&
    trusted_file "$bundles/$1/devmcp-hosting-root" &&
    trusted_file "$bundles/$1/devmcp-hosting-gateway"
}
if ! trusted_dir "$root" || ! trusted_dir "$bundles" || ! trusted_bundle "$revision"; then
  echo 'Target immutable hosting bundle is not trusted' >&2
  exit 1
fi

previous=
if [[ -e "$active" || -L "$active" ]]; then
  if [[ ! -L "$active" || "$(stat -c %u "$active")" != 0 ]]; then
    echo 'Hosting active pointer is not a root-owned symlink' >&2
    exit 1
  fi
  previous="$(readlink -- "$active")"
  # Never follow an arbitrary/absolute path, even during a privileged upgrade.
  if [[ ! "$previous" =~ ^hosting-bundles/[a-f0-9]{40}$ ]] ||
      ! trusted_bundle "${previous#hosting-bundles/}"; then
    echo 'Existing hosting gateway pin is not trusted' >&2
    exit 1
  fi
fi

if [[ "$mode" == check ]]; then
  echo 'PASS: hosting gateway pin can be promoted by local root'
  exit 0
fi
if [[ "$previous" == "$next" ]]; then
  echo "PASS: hosting gateway already pinned at $revision"
  exit 0
fi

# Atomic same-directory symlink rename. On any failure the prior gateway
# remains selected; no transient missing pointer is exposed to systemd.
stage="$(mktemp -d "$root/.hosting-pointer-XXXXXXXX")"
cleanup() {
  rm -f -- "$stage/link"
  rmdir -- "$stage" || true
}
trap cleanup EXIT
ln -s -- "$next" "$stage/link"
mv -Tf -- "$stage/link" "$active"
if [[ "$(readlink -- "$active")" != "$next" ]]; then
  echo 'Hosting gateway pin could not be verified' >&2
  exit 1
fi
echo "PASS: locally approved hosting gateway pinned at $revision"
