#!/usr/bin/env bash
# Exercise root-only hosting gateway pin migration without touching /opt,
# systemd, the router, or any real agent policy.
set -euo pipefail
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
root="$tmp/agent-bin"
mkdir -p "$root/hosting-bundles" "$tmp/fake"
chmod 0755 "$root" "$root/hosting-bundles"
a="$(printf 'a%.0s' {1..40})"
b="$(printf 'b%.0s' {1..40})"
c="$(printf 'c%.0s' {1..40})"
for rev in "$a" "$b"; do
  mkdir -p "$root/hosting-bundles/$rev/Project"
  chmod 0755 "$root/hosting-bundles/$rev" "$root/hosting-bundles/$rev/Project"
  for file in devmcp-hosting-root devmcp-hosting-gateway; do
    printf '#!/usr/bin/env php\n' > "$root/hosting-bundles/$rev/$file"
    chmod 0755 "$root/hosting-bundles/$rev/$file"
  done
done
cat > "$tmp/fake/id" <<'STUB'
#!/usr/bin/env bash
if [[ "${1:-}" == -u ]]; then echo 0; else /usr/bin/id "$@"; fi
STUB
cat > "$tmp/fake/stat" <<'STUB'
#!/usr/bin/env bash
# Simulate root ownership only inside the disposable sandbox.
if [[ "${1:-}" == -c && "${2:-}" == %u ]]; then
  echo 0
else
  exec /usr/bin/stat "$@"
fi
STUB
chmod +x "$tmp/fake/"*
export PATH="$tmp/fake:$PATH"
sed "s|root=/opt/devmcp-agent/bin|root=$root|" \
  deploy/distributed/promote-hosting-active.sh > "$tmp/promote.sh"
bash -n "$tmp/promote.sh"
pin="$root/hosting-active"

# A preflight never publishes a pointer.
bash "$tmp/promote.sh" check "$a" > /dev/null
[[ ! -e "$pin" && ! -L "$pin" ]]
bash "$tmp/promote.sh" promote "$a" > /dev/null
[[ "$(readlink "$pin")" == "hosting-bundles/$a" ]]
# Safe same-revision idempotence; even a preflight for another SHA is inert.
bash "$tmp/promote.sh" promote "$a" > /dev/null
bash "$tmp/promote.sh" check "$b" > /dev/null
[[ "$(readlink "$pin")" == "hosting-bundles/$a" ]]

reject() {
  if bash "$tmp/promote.sh" "$1" "$2" > /dev/null 2>&1; then
    echo 'FAIL: invalid privileged hosting promotion accepted' >&2
    exit 1
  fi
}
reject promote "$c"  # unprepared target
[[ "$(readlink "$pin")" == "hosting-bundles/$a" ]]
# A trusted previous immutable SHA may be upgraded by root.
bash "$tmp/promote.sh" promote "$b" > /dev/null
[[ "$(readlink "$pin")" == "hosting-bundles/$b" ]]

rm "$pin"
ln -s ../../etc/shadow "$pin"
reject check "$a"
reject promote "$a"
[[ "$(readlink "$pin")" == ../../etc/shadow ]]
rm "$pin"
printf 'occupied' > "$pin"
reject promote "$a"
[[ "$(cat "$pin")" == occupied ]]
rm "$pin"
ln -s "hosting-bundles/$b" "$pin"
chmod 0777 "$root/hosting-bundles/$b"
reject promote "$a"  # do not rotate away from a tampered old bundle
[[ "$(readlink "$pin")" == "hosting-bundles/$b" ]]
chmod 0755 "$root/hosting-bundles/$b"
chmod 0644 "$root/hosting-bundles/$a/devmcp-hosting-root"
reject promote "$a"  # new privileged executable must have approved mode
[[ "$(readlink "$pin")" == "hosting-bundles/$b" ]]
reject dangerous "$a"
reject promote 'not-a-git-revision'
echo 'PASS: explicit root gateway promotion, atomic pin, tamper refusal and no implicit opt-in'
