import {spawn} from 'node:child_process';import {mkdtempSync,rmSync} from 'node:fs';import {tmpdir} from 'node:os';import {join} from 'node:path';import assert from 'node:assert/strict';import {createServer} from 'node:net';
const sample=process.argv[2]||'mobile-counter';assert(['mobile-counter','touchdeck-android'].includes(sample));
const socket=createServer();await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));const port=socket.address().port;await new Promise(resolve=>socket.close(resolve));
const dir=mkdtempSync(join(tmpdir(),'devmcp-counter-'));let child;
async function start(){child=spawn(process.execPath,['examples/'+sample+'/server.js'],{env:{...process.env,HOST:'127.0.0.1',PORT:String(port),DATA_DIR:dir,DEVMCP_PUBLIC_BACKEND_URL:'http://127.0.0.1:'+port,DEVMCP_SOURCE_REVISION:'a'.repeat(40)},stdio:['ignore','pipe','pipe']});await new Promise((resolve,reject)=>{child.stdout.once('data',resolve);child.once('exit',code=>reject(Error('Backend exited '+code)));});}
async function stop(){child.kill('SIGTERM');await new Promise(resolve=>child.once('exit',resolve));}
const base='http://127.0.0.1:'+port;
try{
 await start();assert.equal((await (await fetch(base+'/api/counter')).json()).count,0);
 let response=await fetch(base+'/api/increment',{method:'POST',headers:{Origin:'app://localhost','Content-Type':'application/json'},body:JSON.stringify({client:'iOS'})});assert.equal(response.status,200);assert.equal((await response.json()).count,1);
 response=await fetch(base+'/api/increment',{method:'POST',headers:{Origin:'https://localhost','Content-Type':'application/json'},body:JSON.stringify({client:'Android'})});assert.equal((await response.json()).count,2);
 assert.equal((await fetch(base+'/api/increment',{method:'POST',headers:{Origin:'https://example.invalid'},body:'{}'})).status,403);
 assert.equal((await fetch(base+'/downloads')).status,200);assert.deepEqual(await (await fetch(base+'/api/releases')).json(),[]);
 await stop();await start();const state=await (await fetch(base+'/api/counter')).json();assert.equal(state.count,2);assert.equal(state.lastClient,'Android');
 console.log('PASS: iOS/Android CORS, shared counter, persistent state and download page');
}finally{if(child&&child.exitCode===null)await stop();rmSync(dir,{recursive:true});}
