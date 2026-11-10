#!/usr/bin/env bash
# Explicit disposable Linux CI test: install the real broker and update it inside systemd's mount sandbox.
set -euo pipefail
[[ "$EUID" == 0 && "${DEVMCP_RUN_ROOT_TESTS:-}" == 1 ]] || exit 2
for path in /opt/devmcp-agent /etc/devmcp /var/backups/devmcp-agent-projects /var/lib/devmcp-agent-update; do
  [[ ! -e "$path" ]] || { echo "CI fixture path occupied: $path" >&2; exit 1; }
done
! getent group devmcp-agent >/dev/null || { echo 'CI fixture group occupied' >&2; exit 1; }
tmp="$(mktemp -d /opt/.devmcp-ci.XXXXXXXX)"
project="ci-broker-$(openssl rand -hex 5)"
cleanup() {
  systemctl stop devmcp-projects.socket || true
  systemctl reset-failed 'devmcp-projects@*' || true
  rm -f /etc/systemd/system/devmcp-projects.socket /etc/systemd/system/devmcp-projects@.service \
    /etc/systemd/system/devmcp-agent.service /etc/systemd/system/devmcp-agent-update.service
  rm -rf -- /opt/devmcp-agent /etc/devmcp /var/backups/devmcp-agent-projects /var/lib/devmcp-agent-update \
    "/srv/devmcp-projects/$project" "$tmp"
  groupdel devmcp-agent
  systemctl daemon-reload
}
trap cleanup EXIT
groupadd --system devmcp-agent
mkdir -p /opt/devmcp-agent/bin /etc/devmcp /var/lib/devmcp-agent-update
printf '%s\n' '<?php return []; ' > /etc/devmcp/agent.php
printf '%s\n' "<?php return ['enabled'=>true,'machine_id'=>'11111111111111111111111111111111','bind_ipv4'=>'127.0.0.1'];" > /etc/devmcp/agent-projects.php
revision="$(git -c safe.directory="$PWD" rev-parse HEAD)"
git -c safe.directory="$PWD" clone --quiet --no-hardlinks "$PWD" "$tmp/source"
git -C "$tmp/source" checkout --quiet --detach "$revision"
git -C "$tmp/source" remote set-url origin https://github.com/Cowprod/devMcp.git
bash "$tmp/source/deploy/distributed/install-agent-projects.sh" "$tmp/source" "$revision"
# Reinstall using exactly the writable paths of the installed updater unit.
paths="$(sed -n 's/^ReadWritePaths=//p' /etc/systemd/system/devmcp-agent-update.service)"
systemd-run --quiet --wait --collect --unit="devmcp-ci-installer-$project" \
  --property=ProtectSystem=strict --property=PrivateTmp=yes --property=ProtectHome=yes \
  --property="ReadWritePaths=$paths" \
  /bin/bash "$tmp/source/deploy/distributed/install-agent-projects.sh" "$tmp/source" "$revision"
php -r '
$project=$argv[1];$text="Created by the installed broker";$id=bin2hex(random_bytes(16));
$job=["id"=>$id,"machine_id"=>str_repeat("1",32),"project_id"=>$project,"operation"=>"create",
"package"=>["project_id"=>$project,"repository"=>"Cowprod/".$project,"revision"=>sha1($text),
"files"=>[["path"=>"README.md","mode"=>"100644","object"=>sha1("blob ".strlen($text)."\0".$text),"base64"=>base64_encode($text)]]]];
$s=stream_socket_client("unix:///run/devmcp-projects/agent.sock");stream_set_timeout($s,30);
fwrite($s,json_encode($job));stream_socket_shutdown($s,STREAM_SHUT_WR);
$result=json_decode(stream_get_contents($s),true,32,JSON_THROW_ON_ERROR);fclose($s);
if(($result["ok"]??false)!==true||($result["data"]["workspace_created"]??false)!==true)throw new RuntimeException(json_encode($result));
unlink("/srv/devmcp-projects/.results/".$id.".json");
echo "PASS: installed systemd broker creates absent workspace; updater mount sandbox supports reinstall\n";
' "$project"
