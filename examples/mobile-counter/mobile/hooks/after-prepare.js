const fs=require('node:fs'),path=require('node:path');
module.exports=function(context){for(const platform of context.opts.platforms){
 const generated=platform==='ios'?'platforms/ios/www':platform==='android'?'platforms/android/app/src/main/assets/www':null;
 if(generated&&fs.existsSync(path.join(context.opts.projectRoot,generated)))fs.writeFileSync(path.join(context.opts.projectRoot,generated,'prepared.js'),'window.devmcpPrepared=true;\n');
}};
