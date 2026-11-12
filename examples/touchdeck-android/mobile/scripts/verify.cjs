const fs=require('node:fs');const assert=require('node:assert/strict');
const platform=process.argv[2];assert(['ios','android'].includes(platform));
const generated=platform==='ios'?'platforms/ios/www':'platforms/android/app/src/main/assets/www';
assert(fs.readFileSync(generated+'/runtime-config.js','utf8').includes('192.168.194.189:'));
assert(fs.readFileSync(generated+'/prepared.js','utf8').includes('devmcpPrepared'));
assert(fs.readFileSync(generated+'/cordova_plugins.js','utf8').includes('cordova-plugin-device'));
if(platform==='ios')assert(fs.existsSync('platforms/ios/App.xcworkspace'));
