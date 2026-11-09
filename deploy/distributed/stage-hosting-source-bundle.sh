#!/usr/bin/env bash
# Publish a fixed, INERT, root-owned hosting source bundle at the exact Git SHA.
# No commands are exposed to MCP, no services are installed or started, and
# the remote agent is not granted write/execute permissions.
set -euo pipefail
umask 077
[[ "$(id -u)" == 0 && "$#" == 3 ]] || { echo 'Root and checkout/bin/revision required' >&2; exit 2; }
checkout="$1"
bin="$2"
revision="$3"
[[ "$revision" =~ ^[0-9a-f]{40}$ && -d "$checkout/.git" && ! -L "$checkout"
    && -d "$bin" && ! -L "$bin" ]] || { echo 'Invalid hosting bundle input' >&2; exit 1; }
[[ "$(git -C "$checkout" rev-parse HEAD)" == "$revision" ]] || {
  echo 'Hosting bundle checkout revision mismatch' >&2; exit 1;
}
classes=(
  RemoteHostingActivationContract
  RemoteHostingActivationJournal
  RemoteHostingActivationRecoveryEvidence
  RemoteHostingBindEvidence
  RemoteHostingGitEvidence
  RemoteHostingGitRefresh
  RemoteHostingInitialActivation
  RemoteHostingGitProof
  RemoteHostingGitTreeEvidence
  RemoteHostingLocalPolicy
  RemoteHostingLocalPreparation
  RemoteHostingPortEvidence
  RemoteHostingPeerEvidence
  RemoteHostingPendingWatchdog
  RemoteHostingReleaseFilesystemEvidence
  RemoteHostingReleasePlan
  RemoteHostingReleaseStager
  RemoteHostingReleaseSwap
  RemoteHostingSystemdControl
  RemoteHostingUnitDraft
  RemoteHostingUnitInstaller
  RemoteHostingWorkspaceEvidence
  RemoteHostingZeroTierMembership
  RemoteHostingZeroTierNetworkPin
)
entrypoint="$checkout/bin/devmcp-hosting-root"
gateway="$checkout/bin/devmcp-hosting-gateway"
bundle_root="$bin/hosting-bundles"
destination="$bundle_root/$revision"

trusted_directory() {
  [[ -d "$1" && ! -L "$1" && "$(stat -c %u "$1")" == 0
    && "$(stat -c %a "$1")" == 755 ]]
}
if [[ ! -e "$bundle_root" && ! -L "$bundle_root" ]]; then
  install -d -m 0755 -o root -g root "$bundle_root"
fi
trusted_directory "$bundle_root" || { echo 'Untrusted hosting bundle parent' >&2; exit 1; }
# Both ancestors must be real directories, never Git-tracked symlinks that
# could redirect even a fixed filename outside the private checkout.
[[ -d "$checkout/src" && ! -L "$checkout/src"
   && -d "$checkout/src/Project" && ! -L "$checkout/src/Project" ]] || {
  echo 'Untrusted hosting source directories' >&2; exit 1;
}
# The standalone CLI ships with the classes: remote agent bundles do not have Composer.
[[ -f "$entrypoint" && ! -L "$entrypoint" ]] || {
  echo 'Missing or linked hosting executor' >&2; exit 1;
}
[[ -f "$gateway" && ! -L "$gateway" ]] || { echo "Missing hosting gateway" >&2; exit 1; }
php -l "$entrypoint" >/dev/null
php -l "$gateway" >/dev/null
# Fully validate all immutable Git source files before any write.
for name in "${classes[@]}"; do
  source_path="$checkout/src/Project/$name.php"
  [[ -f "$source_path" && ! -L "$source_path" ]] || {
    echo "Missing or linked hosting source: $name" >&2; exit 1;
  }
  php -l "$source_path" >/dev/null
done

verify_bundle() {
  trusted_directory "$destination" && trusted_directory "$destination/Project" || {
    echo 'Untrusted hosting bundle directory' >&2; return 1;
  }
  # Exact inventory only: no unreviewed extra PHP files, links or subtrees.
  local count
  count="$(find "$destination" -mindepth 1 -print | wc -l)"
  [[ "$count" -eq $(("${#classes[@]}" + 3)) ]] || {
    echo 'Unexpected hosting bundle inventory' >&2; return 1;
  }
  [[ -f "$destination/devmcp-hosting-root" && ! -L "$destination/devmcp-hosting-root"
    && "$(stat -c %u "$destination/devmcp-hosting-root")" == 0
    && "$(stat -c %a "$destination/devmcp-hosting-root")" == 755
    && "$(stat -c %h "$destination/devmcp-hosting-root")" == 1 ]] || {
    echo 'Untrusted hosting executor' >&2; return 1;
  }
  [[ -f "$destination/devmcp-hosting-gateway" && ! -L "$destination/devmcp-hosting-gateway"
    && "$(stat -c %u "$destination/devmcp-hosting-gateway")" == 0
    && "$(stat -c %a "$destination/devmcp-hosting-gateway")" == 755
    && "$(stat -c %h "$destination/devmcp-hosting-gateway")" == 1 ]] || {
    echo "Untrusted hosting gateway" >&2; return 1;
  }
  cmp -s -- "$gateway" "$destination/devmcp-hosting-gateway" || {
    echo "Hosting gateway drift" >&2; return 1;
  }
  cmp -s -- "$entrypoint" "$destination/devmcp-hosting-root" || {
    echo 'Hosting executor drift' >&2; return 1;
  }
  for name in "${classes[@]}"; do
    local file="$destination/Project/$name.php"
    [[ -f "$file" && ! -L "$file" && "$(stat -c %u "$file")" == 0
      && "$(stat -c %a "$file")" == 644
      && "$(stat -c %h "$file")" == 1
      && -s "$file" ]] || { echo "Untrusted hosting class: $name" >&2; return 1; }
    cmp -s -- "$checkout/src/Project/$name.php" "$file" || {
      echo "Hosting bundle drift: $name" >&2; return 1;
    }
  done
}
if [[ -e "$destination" || -L "$destination" ]]; then
  verify_bundle
  echo "Hosting source bundle already verified: $revision"
  exit 0
fi

stage="$(mktemp -d "$bundle_root/.stage-XXXXXXXX")"
cleanup() {
  if [[ -n "$stage" && -d "$stage" && ! -L "$stage" ]]; then
    rm -rf -- "$stage"
  fi
}
trap cleanup EXIT
chmod 0755 "$stage"
mkdir -m 0755 "$stage/Project"
install -m 0755 -o root -g root "$entrypoint" "$stage/devmcp-hosting-root"
install -m 0755 -o root -g root "$gateway" "$stage/devmcp-hosting-gateway"
for name in "${classes[@]}"; do
  install -m 0644 -o root -g root "$checkout/src/Project/$name.php" "$stage/Project/$name.php"
done
# -n refuses to replace another release. Its content is verified afterwards.
mv -T -n -- "$stage" "$destination"
verify_bundle
echo "PASS: inert hosting source bundle installed at $revision (no activation)"
