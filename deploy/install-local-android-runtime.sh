#!/usr/bin/env bash
# Machine-scoped Cordova prerequisites and persistent ADB access, never a project build as root.
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Root required' >&2;exit 1; }
enabled=$(/usr/bin/php -r 'require "/opt/devmcp/vendor/autoload.php";$g=require "/etc/devmcp/global.php";$r=\Cowprod\DevMcp\Mcp\RemoteTools::fromConfig($g);if($r===null)exit(1);$s=$g["distributed"]["config_path"]??"/etc/devmcp/mcp-distributed.php";$c=require $s;$d=\Cowprod\DevMcp\Distributed\Registry\DatabaseConnection::connect($c["dsn"],$c["writer_username"],$c["writer_password"]);echo (new \Cowprod\DevMcp\Distributed\Registry\MachineCapabilities($d))->get("local")["cordova_android"]?"yes":"no";')
[[ $enabled == yes ]] || { echo 'LOCAL_ANDROID_DISABLED: no toolchain or ADB changes';exit 0; }
for tool in /usr/bin/node /usr/bin/npm /usr/bin/python3 /usr/bin/curl /usr/bin/javac;do [[ -x $tool ]] || { echo "Missing Android prerequisite: $tool" >&2;exit 1; };done
sdk=/opt/android-sdk
for file in "$sdk/platforms/android-36/android.jar" "$sdk/build-tools/36.0.0/apksigner" "$sdk/build-tools/36.0.0/aapt" "$sdk/platform-tools/adb";do [[ -f $file ]] || { echo "Missing Android SDK prerequisite: $file" >&2;exit 1; };done
java_home=$(dirname "$(dirname "$(readlink -f /usr/bin/javac)")")
[[ $("$java_home/bin/javac" -version 2>&1) == javac\ 17.* ]] || { echo 'Configure Java 17 before enabling the default Android toolchain' >&2;exit 1; }
getent group plugdev >/dev/null
install -d -m 0755 -o root -g root /opt/devmcp-tools
gradle=/opt/devmcp-tools/gradle-8.14.2
if [[ ! -d $gradle ]];then
  stage=$(mktemp -d /opt/devmcp-tools/.gradle-stage-XXXXXXXX)
  trap 'rm -rf "$stage"' EXIT
  curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location --max-time 240 'https://services.gradle.org/distributions/gradle-8.14.2-bin.zip' -o "$stage/gradle.zip"
  printf '%s  %s\n' '7197a12f450794931532469d4ff21a59ea2c1cd59a3ec3f89c035c3c420a6999' "$stage/gradle.zip" | sha256sum -c -
  python3 - "$stage" <<'PY'
import sys,zipfile,pathlib
root=pathlib.Path(sys.argv[1])
with zipfile.ZipFile(root/'gradle.zip') as archive:
 assert all(n.startswith('gradle-8.14.2/') and '..' not in pathlib.PurePosixPath(n).parts for n in archive.namelist())
 archive.extractall(root)
(root/'gradle-8.14.2/bin/gradle').chmod(0o755)
PY
  mv "$stage/gradle-8.14.2" "$gradle"
  rm -rf "$stage";trap - EXIT
fi
[[ ! -L $gradle && $(stat -c %u "$gradle") == 0 && -x $gradle/bin/gradle ]] || { echo 'Untrusted Gradle installation' >&2;exit 1; }
install -d -o devmcp -g devmcp -m 0700 /var/lib/devmcp/mobile-builds
install -d -o devmcp -g www-data -m 2770 /var/lib/devmcp-local-artifacts
profile=/etc/devmcp/android-runtime.php
if [[ ! -e $profile ]];then
  adb_user=$(stat -c %U "$sdk")
  [[ $adb_user =~ ^[a-z_][a-z0-9_-]*$ && $(id -u "$adb_user") -ne 0 ]] || { echo 'An unprivileged Android SDK owner is required for ADB' >&2;exit 1; }
  /usr/bin/php /dev/stdin "$profile" "$java_home" "$sdk" "$gradle/bin/gradle" "$adb_user" <<'PHP'
<?php
$site=file_get_contents('/etc/nginx/sites-available/devmcp-admin.conf');
preg_match_all('/listen\s+([0-9.]+):443\s+ssl/',$site,$m);$ips=array_unique($m[1]);
if(count($ips)!==1||!filter_var(reset($ips),FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new RuntimeException('One central HTTPS listener required');
$config=['node'=>'/usr/bin/node','android'=>['java_home'=>$argv[2],'sdk_root'=>$argv[3],'gradle'=>$argv[4],'build_tools'=>'36.0.0'],'central_url'=>'https://'.reset($ips),'adb_user'=>$argv[5]];
file_put_contents($argv[1],"<?php return ".var_export($config,true).";\n",LOCK_EX);chmod($argv[1],0644);
PHP
fi
[[ ! -L $profile && $(stat -c %u "$profile") == 0 ]] || { echo 'Untrusted Android profile' >&2;exit 1; }
adb_user=$(/usr/bin/php -r '$c=require $argv[1];echo $c["adb_user"];' "$profile")
[[ $adb_user =~ ^[a-z_][a-z0-9_-]*$ && $(id -u "$adb_user") -ne 0 ]] || exit 1
adb_home=$(getent passwd "$adb_user" | cut -d: -f6)
[[ $adb_home =~ ^/[a-zA-Z0-9_./-]+$ && -f $adb_home/.android/adbkey ]] || { echo 'Existing authorized ADB key required; no key is replaced' >&2;exit 1; }
unit=/etc/systemd/system/devmcp-adb.service
[[ ! -e $unit ]] || grep -q '^# devMcp managed ADB' "$unit" || { echo 'Foreign ADB unit' >&2;exit 1; }
cat > "$unit" <<EOF
# devMcp managed ADB
[Unit]
Description=devMcp local Android USB bridge
After=local-fs.target
Before=devmcp-worker.service devmcp-tunnel.service devmcp-admin-android.service
[Service]
User=$adb_user
SupplementaryGroups=plugdev
Environment=HOME=$adb_home
ExecStart=$sdk/platform-tools/adb -L tcp:5037 nodaemon server
Restart=on-failure
RestartSec=2
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=read-only
ReadWritePaths=$adb_home/.android
PrivateTmp=true
[Install]
WantedBy=multi-user.target
EOF
for service in devmcp-worker.service devmcp-tunnel.service devmcp-http.service devmcp-admin-android.service;do
 install -d -o root -g root -m 0755 /etc/systemd/system/"$service".d
 cat > /etc/systemd/system/"$service".d/55-devmcp-android.conf <<'EOF'
[Unit]
Wants=devmcp-adb.service
After=devmcp-adb.service
[Service]
ReadWritePaths=/var/lib/devmcp-local-artifacts
EOF
done
systemctl daemon-reload
if ! systemctl is-active --quiet devmcp-adb.service;then
  /usr/bin/adb kill-server || true
  systemctl enable --now devmcp-adb.service
fi
systemctl is-active --quiet devmcp-adb.service
printf '%s\n' 'LOCAL_ANDROID_READY: project-local Cordova, Java 17, SDK 36, Gradle 8.14.2 and persistent ADB; no tablet application changed'
