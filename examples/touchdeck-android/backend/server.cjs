'use strict';
const http=require('node:http'), fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {HOST,PORT,DATA_DIR,MOBILE_RELEASES_FILE}=process.env;
if(!HOST||!PORT||!DATA_DIR||!process.env.DEVMCP_PUBLIC_BACKEND_URL||!process.env.DEVMCP_SOURCE_REVISION)throw Error('HOST, PORT and DATA_DIR must be supplied by devMcp');
const file=path.join(DATA_DIR,'counter.json'),assets=path.join(__dirname,'../mobile/www');
fs.mkdirSync(DATA_DIR,{recursive:true});
let state=fs.existsSync(file)?JSON.parse(fs.readFileSync(file,'utf8')):{count:0,updatedAt:new Date().toISOString(),lastClient:'web'};
if(!Number.isSafeInteger(state.count)||state.count<0)throw Error('Invalid stored counter');
const allowed=new Set([process.env.DEVMCP_PUBLIC_BACKEND_URL,'app://localhost','http://localhost','https://localhost']);
const json=(res,status,data)=>{res.writeHead(status,{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store'});res.end(JSON.stringify(data));};
const snapshot=()=>({...state,server:os.hostname(),revision:process.env.DEVMCP_SOURCE_REVISION});
const server=http.createServer(async(req,res)=>{
 try{
  const pathname=new URL(req.url,'http://localhost').pathname,origin=req.headers.origin;
  if(origin&&!allowed.has(origin)){json(res,403,{error:'Origin not allowed'});return;}
  if(origin){res.setHeader('Access-Control-Allow-Origin',origin);res.setHeader('Vary','Origin');}
  res.setHeader('Access-Control-Allow-Methods','GET, POST, OPTIONS');res.setHeader('Access-Control-Allow-Headers','Content-Type');
  if(req.method==='OPTIONS'){res.writeHead(204);res.end();return;}
  if(pathname==='/api/counter'&&req.method==='GET'){json(res,200,snapshot());return;}
  if(pathname==='/api/increment'&&req.method==='POST'){
   let body='';for await(const chunk of req){body+=chunk;if(Buffer.byteLength(body)>1024){json(res,413,{error:'Request too large'});return;}}
   let payload;try{payload=JSON.parse(body);}catch{json(res,400,{error:'Invalid JSON'});return;}
   if(!['iOS','Android','web'].includes(payload.client)){json(res,400,{error:'Explicit iOS, Android or web client required'});return;}
   const next={count:state.count+1,updatedAt:new Date().toISOString(),lastClient:payload.client};
   const temporary=file+'.tmp';fs.writeFileSync(temporary,JSON.stringify(next),{mode:0o600});fs.renameSync(temporary,file);state=next;
   json(res,200,snapshot());return;
  }
  if(pathname==='/api/releases'&&req.method==='GET'){
   const index=MOBILE_RELEASES_FILE&&fs.existsSync(MOBILE_RELEASES_FILE)?JSON.parse(fs.readFileSync(MOBILE_RELEASES_FILE,'utf8')):{};
   json(res,200,Object.entries(index).filter(([key])=>['ios','android'].includes(key)).map(([platform,release])=>({platform,...release})));return;
  }
  if(req.method!=='GET'&&req.method!=='HEAD'){json(res,405,{error:'Method not allowed'});return;}
  if(pathname==='/runtime-config.js'){res.writeHead(200,{'Content-Type':'text/javascript'});res.end('window.API_BASE=location.origin;');return;}
  if(pathname==='/cordova.js'||pathname==='/prepared.js'){res.writeHead(200,{'Content-Type':'text/javascript'});res.end('// Browser version of Compteur Lab');return;}
  const files={'/':'index.html','/downloads':'index.html','/app.js':'app.js','/style.css':'style.css'};
  if(!files[pathname]){json(res,404,{error:'Not found'});return;}
  const type=pathname.endsWith('.js')?'text/javascript':pathname.endsWith('.css')?'text/css':'text/html; charset=utf-8';
  res.writeHead(200,{'Content-Type':type,'Cache-Control':'no-store'});if(req.method==='HEAD')res.end();else fs.createReadStream(path.join(assets,files[pathname])).pipe(res);
 }catch(error){console.error(error);if(!res.headersSent)json(res,500,{error:'Backend storage or request failed'});else res.end();}
});
server.listen(Number(PORT),HOST,()=>console.log(`Compteur Lab on http://${HOST}:${PORT}`));
for(const signal of ['SIGTERM','SIGINT'])process.on(signal,()=>server.close(()=>process.exit(0)));
