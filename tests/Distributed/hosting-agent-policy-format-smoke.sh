#!/usr/bin/env bash
# Verify the real installer generators in a disposable sandbox; no root,
# systemd, /etc/devmcp, network or deployment changes.
set -euo pipefail
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
installer=deploy/distributed/install-hosting-gateway.sh
# Extract only the two fixed, pure formatter functions from production code.
functions="$(sed -n '/^hosting_agent_policy_payload() {/,/^}/p; /^hosting_agent_policy_legacy_payload() {/,/^}/p' "$installer")"
[[ "$functions" == *"hosting_agent_policy_payload()"* && "$functions" == *"hosting_agent_policy_legacy_payload()"* ]]
eval "$functions"
machine_id=41cdba74a97e72028a2601c3bac7e328
hosting_agent_policy_payload "$machine_id" > "$tmp/current.php"
hosting_agent_policy_legacy_payload "$machine_id" > "$tmp/legacy.php"
php -l "$tmp/current.php" >/dev/null
# The PHP tag must be parsed, return exact values, and produce zero output.
php -r '
ob_start();
$p=require $argv[1];
$output=ob_get_clean();
exit($output === "" && is_array($p)
 && array_keys($p) === ["enabled", "machine_id"]
 && $p["enabled"] === true && $p["machine_id"] === $argv[2] ? 0 : 1);
' "$tmp/current.php" "$machine_id"
# The previously generated escaped template was NOT valid executable PHP.
if php -r '
ob_start(); $p=require $argv[1]; $output=ob_get_clean();
exit($output === "" && is_array($p) ? 0 : 1);
' "$tmp/legacy.php"; then
  echo 'FAIL: regression fixture no longer reproduces legacy malformed policy' >&2
  exit 1
fi
[[ -s "$tmp/legacy.php" ]] && ! cmp -s "$tmp/legacy.php" "$tmp/current.php"
# Installer must ONLY repair this known old byte-for-byte payload, not an
# arbitrary root-owned policy or changed machine identity.
grep -Fq 'cmp -s "$legacy_tmp" "$agent_policy"' "$installer"
grep -Fq 'Existing agent hosting policy differs; refusing overwrite' "$installer"
grep -Fq 'trusted_file "$agent_policy" 644' "$installer"
echo 'PASS: executable PHP opt-in, exact legacy recovery, unrelated grants refused'
