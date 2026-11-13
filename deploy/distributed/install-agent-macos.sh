#!/bin/bash
# User LaunchAgent bootstrap, with dependency preparation before token redemption.
set -euo pipefail
umask 077
[[ "$(uname -s)" == Darwin && "$(uname -m)" == arm64 && "$EUID" != 0 ]] || { echo 'Requires an Apple Silicon macOS user (run without sudo)' >&2; exit 1; }
server= cert_hash= release= token_stdin=0 check_only=0
while (($#)); do
  case "$1" in
    --server) server="$2";shift 2;;
    --cert-sha256) cert_hash="$2";shift 2;;
    --release) release="$2";shift 2;;
    --token-stdin) token_stdin=1;shift;;
    --check-only) check_only=1;shift;;
    *) echo 'Unknown installation argument' >&2;exit 2;;
  esac
done
[[ "$server" =~ ^https://([0-9]{1,3}\.){3}[0-9]{1,3}(:[0-9]+)?$ ]] || { echo 'Literal central ZeroTier HTTPS IPv4 required' >&2;exit 2; }
account_home="$(/usr/bin/dscl . -read "/Users/$(id -un)" NFSHomeDirectory | /usr/bin/cut -d' ' -f2-)"
[[ -d "$account_home" && ! -L "$account_home" ]] || { echo 'User home unavailable' >&2;exit 1; }
base="$account_home/Library/Application Support/devMcp"
uid="$(id -u)"
/bin/launchctl print "gui/$uid" >/dev/null || { echo 'Log into the macOS desktop once before installing this user LaunchAgent' >&2;exit 1; }
# Homebrew is a prerequisite manager; no system PHP/Node or Xcode stub is assumed usable.
brew=/opt/homebrew/bin/brew
if [[ ! -x "$brew" ]]; then
  ((check_only==0)) || { echo 'Homebrew is missing';exit 1; }
  bootstrap="$(mktemp)"
  /usr/bin/curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh -o "$bootstrap"
  sudo -v
  NONINTERACTIVE=1 /bin/bash "$bootstrap" </dev/null
  rm -f "$bootstrap"
fi
[[ -x "$brew" ]] || { echo 'Homebrew installation did not complete' >&2;exit 1; }
missing=()
[[ -x /opt/homebrew/bin/php ]] || missing+=(php)
[[ -x /opt/homebrew/bin/node ]] || missing+=(node)
if ((${#missing[@]})); then
  ((check_only==0)) || { echo "Missing runtime packages: ${missing[*]}";exit 1; }
  HOMEBREW_NO_AUTO_UPDATE=1 "$brew" install "${missing[@]}" </dev/null
fi
php=/opt/homebrew/bin/php
node=/opt/homebrew/bin/node
"$php" -r 'exit(PHP_VERSION_ID>=80300 && extension_loaded("curl") && function_exists("posix_geteuid") ? 0:1);' || { echo 'PHP 8.3+, curl and posix are required' >&2;exit 1; }
"$node" -e 'if(Number(process.versions.node.split(".")[0])<18)process.exit(1)' || { echo 'Node 18+ required' >&2;exit 1; }
central_ip="${server#https://}";central_ip="${central_ip%%:*}"
interface="$(/sbin/route -n get "$central_ip" | /usr/bin/awk '/interface:/{print $2}')"
[[ "$interface" =~ ^feth[0-9]+$ && -d '/Library/Application Support/ZeroTier/One' ]] || { echo 'A working ZeroTier route is required before enrollment' >&2;exit 1; }
bind_ip="$(/sbin/ifconfig "$interface" | /usr/bin/awk '$1=="inet"{print $2}')"
[[ "$bind_ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || { echo 'One ZeroTier IPv4 required' >&2;exit 1; }
echo "Preflight OK: macOS arm64, PHP $($php -r 'echo PHP_VERSION;'), Node $($node --version), ZeroTier $bind_ip"
((check_only==0)) || exit 0
[[ "$release" =~ ^[a-f0-9]{40}$ && "$cert_hash" =~ ^[a-f0-9]{64}$ && "$token_stdin" == 1 ]] || { echo 'Immutable release, pinned certificate and token stdin required' >&2;exit 2; }
[[ ! -e "$base/config.php" ]] || { echo 'Agent identity already exists; refusing overwrite' >&2;exit 1; }
staging="$(mktemp -d)";trap 'rm -rf "$staging"' EXIT
/usr/bin/curl --insecure --proto '=https' --silent --show-error --fail --max-time 20 "$server/devmcp-ca.crt" -o "$staging/ca.pem"
[[ "$(/usr/bin/shasum -a 256 "$staging/ca.pem" | /usr/bin/awk '{print $1}')" == "$cert_hash" ]] || { echo 'Central TLS certificate mismatch' >&2;exit 1; }
/usr/bin/git -c core.hooksPath=/dev/null clone --quiet --no-checkout https://github.com/Cowprod/devMcp.git "$staging/source"
/usr/bin/git -C "$staging/source" -c core.hooksPath=/dev/null fetch --quiet origin "$release"
/usr/bin/git -C "$staging/source" -c core.hooksPath=/dev/null checkout --quiet --detach "$release"
for file in bin/distributed-agent-macos.php src/Project/RemoteProjectPackage.php src/Project/RemoteProjectRuntime.php src/Project/MacProjectRuntime.php src/Project/CordovaAndroidRuntime.php src/Project/MacAndroidRuntime.php src/Project/MobileBuildRetention.php src/Project/MobileReleaseIndex.php src/Project/MacIosRuntime.php src/Project/MacCordovaIos.php src/Project/MacIosArtifactUpload.php; do "$php" -l "$staging/source/$file" >/dev/null;done
"$node" --check "$staging/source/bin/devmcp-static-server.mjs"
for occupied in "$base/active" "$base/bundles/$release" "$account_home/Library/LaunchAgents/net.cowprod.devmcp.agent.plist" "$account_home/Library/LaunchAgents/net.cowprod.devmcp.update.plist"; do
  [[ ! -e "$occupied" && ! -L "$occupied" ]] || { echo 'Installation path occupied; enrollment token not consumed' >&2;exit 1; }
done
"$php" "$staging/source/bin/configure-macos-launcher.php" "$base" --prepare
IFS= read -r token || true
[[ "$token" =~ ^[a-f0-9]{64}$ ]] || { echo 'Invalid enrollment token' >&2;exit 2; }
printf '{"token":"%s"}' "$token" > "$staging/request.json";unset token
/usr/bin/curl --proto '=https' --fail --silent --show-error --cacert "$staging/ca.pem" --header 'Content-Type: application/json' --data-binary "@$staging/request.json" "$server/v1/enrollment/redeem" -o "$staging/identity.json"
"$php" -r '$i=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);if(!preg_match("/^[a-f0-9]{32}$/D",$i["machine_id"]??"")||!preg_match("/^[a-f0-9]{64}$/D",$i["credential"]??""))exit(1);' "$staging/identity.json"
mkdir -p "$base/bundles" "$base/state" "$account_home/Library/LaunchAgents"
bundle="$base/bundles/$release"
[[ ! -e "$bundle" ]] || { echo 'Bundle path occupied' >&2;exit 1; }
mv "$staging/source" "$bundle"
printf '%s\n' "$release" > "$bundle/revision"
export DEVMCP_MAC_BASE="$base" DEVMCP_MAC_BUNDLE="$bundle" DEVMCP_MAC_SERVER="$server" DEVMCP_MAC_IP="$bind_ip" DEVMCP_MAC_HOME="$account_home"
"$php" -r '
$b=getenv("DEVMCP_MAC_BASE");$bundle=getenv("DEVMCP_MAC_BUNDLE");$i=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
$root="/Users/Shared/devmcp-projects-".$i["machine_id"];
if(file_exists($root)||is_link($root))throw new RuntimeException("Workspace root occupied");
if(!mkdir($root,0700))throw new RuntimeException("Cannot create workspace root");
file_put_contents($b."/credential",$i["credential"]);chmod($b."/credential",0600);
$config=["machine_id"=>$i["machine_id"],"central_url"=>getenv("DEVMCP_MAC_SERVER"),"bind_ipv4"=>getenv("DEVMCP_MAC_IP"),"state_dir"=>$b."/state","credential_file"=>$b."/credential","ca_file"=>$b."/ca.pem"];
file_put_contents($b."/config.php","<?php return ".var_export($config,true).";\n");
file_put_contents($bundle."/agent-settings.php","<?php return ".var_export(["php"=>"/opt/homebrew/bin/php","node"=>"/opt/homebrew/bin/node"],true).";\n");
' "$staging/identity.json"
cp "$staging/ca.pem" "$base/ca.pem"
ln -s "bundles/$release" "$base/active"
export DEVMCP_MAC_PHP="$php"
"$php" "$bundle/bin/configure-macos-launcher.php" "$base" --configure
"$php" -r '
$b=getenv("DEVMCP_MAC_BASE");$p=["Label"=>"net.cowprod.devmcp.update","ProgramArguments"=>[getenv("DEVMCP_MAC_PHP"),$b."/active/bin/update-agent-macos.php",$b."/config.php"],"StartInterval"=>21600,"ProcessType"=>"Background","StandardOutPath"=>$b."/state/update.log","StandardErrorPath"=>$b."/state/update-error.log"];
echo json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
' > "$staging/update.plist"
/usr/bin/plutil -convert xml1 "$staging/update.plist"
update_plist="$account_home/Library/LaunchAgents/net.cowprod.devmcp.update.plist"
[[ ! -e "$update_plist" && ! -L "$update_plist" ]] || { echo 'Updater LaunchAgent path occupied' >&2;exit 1; }
cp "$staging/update.plist" "$update_plist"
/bin/launchctl bootstrap "gui/$uid" "$update_plist"

echo "Installed: approve this macOS machine once in the admin. Static/PHP/Node hosting ready."
