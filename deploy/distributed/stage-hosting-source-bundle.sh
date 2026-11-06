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
  RemoteHostingGitProof
  RemoteHostingGitTreeEvidence
  RemoteHostingLocalPolicy
  RemoteHostingPortEvidence
  RemoteHostingReleaseFilesystemEvidence
  RemoteHostingReleasePlan
  RemoteHostingReleaseStager
  RemoteHostingUnitDraft
  RemoteHostingUnitInstaller
  RemoteHostingWorkspaceEvidence
  RemoteHostingZeroTierMembership
  RemoteHostingZeroTierNetworkPin
)
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
  [[ "$count" -eq $(("${#classes[@]}" + 1)) ]] || {
    echo 'Unexpected hosting bundle inventory' >&2; return 1;
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
for name in "${classes[@]}"; do
  install -m 0644 -o root -g root "$checkout/src/Project/$name.php" "$stage/Project/$name.php"
done
# -n refuses to replace another release. Its content is verified afterwards.
mv -T -n -- "$stage" "$destination"
verify_bundle
echo "PASS: inert hosting source bundle installed at $revision (no activation)"
