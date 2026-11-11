#!/usr/bin/env php
<?php
declare(strict_types=1);
// A named, stable responsible app for macOS Local Network privacy attribution.
try{
 if(PHP_OS_FAMILY!=='Darwin'||posix_geteuid()===0||$argc!==3||!in_array($argv[2],['--prepare','--configure'],true))throw new RuntimeException('Usage: configure-macos-launcher.php AGENT_BASE --prepare|--configure');
 $base=$argv[1];$bundle=dirname(__DIR__);
 if(!is_dir($base)&&!mkdir($base,0700,true))throw new RuntimeException('Agent directory unavailable');
 if(is_link($base)||realpath($base)!==$base||fileowner($base)!==posix_geteuid())throw new RuntimeException('Untrusted agent base directory');
 $run=static function(array $args,array $allowed=[0]):string{
  $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
  if(!is_resource($p))throw new RuntimeException('Launcher setup command unavailable');
  $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$code=proc_close($p);
  if(!in_array($code,$allowed,true))throw new RuntimeException('Launcher setup command failed: '.($args[1]??$args[0]).' ('.$code.')');
  return $out;
 };
 $source=$bundle.'/native/macos/devmcp-launcher.c';$hash=hash_file('sha256',$source);$app=$base.'/devMcp.app';
 if(is_link($app))throw new RuntimeException('Linked launcher app rejected');
 if(is_dir($app)){
  if(trim((string)file_get_contents($app.'/Contents/Resources/source.sha256'))!==$hash)throw new RuntimeException('Installed launcher source changed; explicit app migration required');
  $run(['/usr/bin/codesign','--verify','--deep','--strict',$app]);
 }else{
  if(file_exists($app))throw new RuntimeException('Launcher app path occupied');
  $stage=$base.'/.launcher-'.bin2hex(random_bytes(6)).'.app';
  mkdir($stage.'/Contents/MacOS',0755,true);mkdir($stage.'/Contents/Resources',0755);
  $info=['CFBundleIdentifier'=>'net.cowprod.devmcp','CFBundleName'=>'devMcp','CFBundleDisplayName'=>'devMcp',
   'CFBundleExecutable'=>'devmcp-launcher','CFBundlePackageType'=>'APPL','CFBundleVersion'=>'1','CFBundleShortVersionString'=>'1.0',
   'LSUIElement'=>true,'NSLocalNetworkUsageDescription'=>'devMcp se connecte au serveur de votre lab et aux services de vos projets sur le réseau ZeroTier.'];
  file_put_contents($stage.'/Contents/Info.plist',json_encode($info,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
  $run(['/usr/bin/plutil','-convert','xml1',$stage.'/Contents/Info.plist']);
  $run(['/usr/bin/clang','-Os',$source,'-Wl,-sectcreate,__TEXT,__info_plist,'.$stage.'/Contents/Info.plist','-o',$stage.'/Contents/MacOS/devmcp-launcher']);
  chmod($stage.'/Contents/MacOS/devmcp-launcher',0755);
  file_put_contents($stage.'/Contents/Resources/source.sha256',$hash."\n");
  $run(['/usr/bin/codesign','--force','--sign','-','--identifier','net.cowprod.devmcp',$stage]);
  if(!rename($stage,$app))throw new RuntimeException('Cannot publish named launcher app');
 }
 $run(['/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister','-f',$app]);
 if($argv[2]==='--prepare'){echo "PASS: named devMcp app prepared for Local Network permission\n";exit(0);}
 $config=require $base.'/config.php';
 $poll=fopen($config['state_dir'].'/poll.lock','c+b');if(!is_resource($poll)||!flock($poll,LOCK_EX))throw new RuntimeException('Agent poll lock unavailable');
 $settings=require $base.'/active/agent-settings.php';$settings['launcher']=$app.'/Contents/MacOS/devmcp-launcher';
 file_put_contents($base.'/active/agent-settings.php',"<?php return ".var_export($settings,true).";\n",LOCK_EX);
 $account=posix_getpwuid(posix_geteuid());$plist=$account['dir'].'/Library/LaunchAgents/net.cowprod.devmcp.agent.plist';
 $definition=['Label'=>'net.cowprod.devmcp.agent','AssociatedBundleIdentifiers'=>['net.cowprod.devmcp'],
  'ProgramArguments'=>[$settings['launcher'],$settings['php'],$base.'/active/bin/distributed-agent-macos.php',$base.'/config.php'],
  'RunAtLoad'=>true,'StartInterval'=>60,'ProcessType'=>'Background','StandardOutPath'=>$base.'/state/agent.log','StandardErrorPath'=>$base.'/state/agent-error.log'];
 $expected=json_encode($definition,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 if(is_link($plist))throw new RuntimeException('Linked agent plist rejected');
 if(is_file($plist)){
  $existing=json_decode($run(['/usr/bin/plutil','-convert','json','-o','-',$plist]),true,32,JSON_THROW_ON_ERROR);
  if($existing==$definition){echo "PASS: named devMcp LaunchAgent already configured\n";exit(0);}
 }
 $target='gui/'.posix_geteuid().'/net.cowprod.devmcp.agent';
 if(str_contains($run(['/bin/launchctl','print',$target],[0,113]),' = {'))$run(['/bin/launchctl','bootout',$target]);
 $temporary=$plist.'.tmp';file_put_contents($temporary,$expected);chmod($temporary,0600);
 $run(['/usr/bin/plutil','-convert','xml1',$temporary]);rename($temporary,$plist);
 flock($poll,LOCK_UN);
 $run(['/bin/launchctl','bootstrap','gui/'.posix_geteuid(),$plist]);
 echo "PASS: devMcp LaunchAgent associated with named app; allow Local Network on the Mac when prompted\n";
}catch(Throwable $error){fwrite(STDERR,'devMcp macOS launcher: '.$error->getMessage()."\n");exit(1);}
