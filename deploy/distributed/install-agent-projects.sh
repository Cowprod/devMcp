#!/usr/bin/env bash
# Install project creation/deployment with the enrolled agent, once per machine.
set -euo pipefail
umask 077
[[ "$(id -u)" == 0 && "$#" == 2 && "$2" =~ ^[a-f0-9]{40}$ ]] || {
  echo 'Usage: install-agent-projects.sh TRUSTED_CHECKOUT IMMUTABLE_SHA' >&2; exit 2;
}
checkout="$1"
revision="$2"
[[ "$(git -C "$checkout" rev-parse HEAD)" == "$revision" &&
   "$(git -C "$checkout" remote get-url origin)" == 'https://github.com/Cowprod/devMcp.git' ]] || {
  echo 'Project installer source mismatch' >&2; exit 1;
}
[[ -f /etc/devmcp/agent.php && ! -L /etc/devmcp/agent.php ]] || { echo 'Agent is not enrolled' >&2; exit 1; }
for executable in /usr/bin/php /usr/bin/python3 /usr/bin/node /usr/bin/systemctl /usr/bin/timeout; do
  [[ -x "$executable" ]] || { echo "Project runtime dependency missing: $executable" >&2; exit 1; }
done
root=/opt/devmcp-agent
install -d -m0700 -o root -g root /var/backups/devmcp-agent-projects
if [[ -f /etc/systemd/system/devmcp-agent.service && ! -e "/var/backups/devmcp-agent-projects/$revision.service" ]]; then
  cp -p /etc/systemd/system/devmcp-agent.service "/var/backups/devmcp-agent-projects/$revision.service"
fi
install -m0644 -o root -g root "$checkout/deploy/distributed/devmcp-agent.service" /etc/systemd/system/devmcp-agent.service
if [[ -f /etc/systemd/system/devmcp-agent-update.service && ! -e "/var/backups/devmcp-agent-projects/$revision-update.service" ]]; then
  cp -p /etc/systemd/system/devmcp-agent-update.service "/var/backups/devmcp-agent-projects/$revision-update.service"
fi
install -m0644 -o root -g root "$checkout/deploy/distributed/devmcp-agent-update.service" /etc/systemd/system/devmcp-agent-update.service
install -d -m 0755 -o root -g root "$root/project-bundles" /srv/devmcp-projects
bundle="$root/project-bundles/$revision"
if [[ ! -e "$bundle" ]]; then
  stage="$(mktemp -d "$root/project-bundles/.stage.XXXXXXXX")"
  trap 'rm -rf -- "$stage"' EXIT
  install -o root -g root -m0644 "$checkout/src/Project/DeploymentConfiguration.php" "$stage/DeploymentConfiguration.php"
  install -o root -g root -m0644 "$checkout/src/Project/RemoteProjectPackage.php" "$stage/RemoteProjectPackage.php"
  install -o root -g root -m0644 "$checkout/src/Project/RemoteProjectRuntime.php" "$stage/RemoteProjectRuntime.php"
  install -o root -g root -m0644 "$checkout/bin/devmcp-agent-project-broker.php" "$stage/devmcp-agent-project-broker.php"
  printf '%s\n' "$revision" > "$stage/revision"
  chmod 0644 "$stage/revision"
  chmod 0755 "$stage"
  for file in "$stage"/*.php; do php -l "$file" >/dev/null; done
  mv "$stage" "$bundle"
  trap - EXIT
else
  cmp "$checkout/src/Project/DeploymentConfiguration.php" "$bundle/DeploymentConfiguration.php"
  cmp "$checkout/src/Project/RemoteProjectPackage.php" "$bundle/RemoteProjectPackage.php"
  cmp "$checkout/src/Project/RemoteProjectRuntime.php" "$bundle/RemoteProjectRuntime.php"
  cmp "$checkout/bin/devmcp-agent-project-broker.php" "$bundle/devmcp-agent-project-broker.php"
fi
if [[ ! -e /etc/devmcp/agent-projects.php ]]; then
  php <<'PHP'
<?php
$c=require '/etc/devmcp/agent.php';
$ip=parse_url($c['central_url'],PHP_URL_HOST);
$p=proc_open(['/usr/sbin/ip','-j','-4','route','get',$ip],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
$routes=json_decode(stream_get_contents($pipes[1]),true,16,JSON_THROW_ON_ERROR);
fclose($pipes[1]);
if(proc_close($p)!==0 || !str_starts_with($routes[0]['dev']??'','zt') || !filter_var($routes[0]['prefsrc']??null,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new RuntimeException('ZeroTier source IPv4 unavailable');
$policy=['enabled'=>true,'machine_id'=>$c['machine_id'],'bind_ipv4'=>$routes[0]['prefsrc']];
$path='/etc/devmcp/agent-projects.php';
$f=fopen($path,'xb');
if(!is_resource($f))throw new RuntimeException('Project policy already exists');
fwrite($f,"<?php\nreturn ".var_export($policy,true).";\n");fclose($f);chmod($path,0644);
PHP
fi
php -r '$p=require $argv[1]; exit(is_array($p)&&($p["enabled"]??false)===true ? 0:1);' /etc/devmcp/agent-projects.php || {
  echo 'Project agent is explicitly disabled; policy left unchanged'; exit 0;
}
install -o root -g root -m0755 "$checkout/bin/remote-project-queue.php" "$root/bin/remote-project-queue.php"
install -o root -g root -m0644 "$checkout/deploy/distributed/devmcp-projects.socket" /etc/systemd/system/devmcp-projects.socket
install -o root -g root -m0644 "$checkout/deploy/distributed/devmcp-projects@.service" /etc/systemd/system/devmcp-projects@.service
temporary="$root/.project-active-$revision"
[[ ! -e "$temporary" && ! -L "$temporary" ]] || { echo 'Project pointer staging path occupied' >&2; exit 1; }
ln -s "project-bundles/$revision" "$temporary"
mv -Tf "$temporary" "$root/project-active"
systemctl daemon-reload
systemctl enable --now devmcp-projects.socket
systemctl is-active --quiet devmcp-projects.socket
echo "PASS: project-capable agent installed at $revision; no project pre-created"
