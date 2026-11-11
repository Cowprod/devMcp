import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import {createReadStream} from 'node:fs';
const root=await fs.realpath(process.argv[2]);
const types={'.html':'text/html; charset=utf-8','.css':'text/css; charset=utf-8','.js':'text/javascript; charset=utf-8','.mjs':'text/javascript; charset=utf-8','.json':'application/json','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg','.jpeg':'image/jpeg','.ico':'image/x-icon','.webp':'image/webp','.woff2':'font/woff2','.txt':'text/plain; charset=utf-8'};
http.createServer(async(req,res)=>{
 try{
  if(!['GET','HEAD'].includes(req.method)){res.writeHead(405);res.end();return;}
  let name=decodeURIComponent(new URL(req.url,'http://localhost').pathname);
  if(name.includes('\0')||name.includes('\\'))throw Error('path');
  let target=await fs.realpath(path.join(root,name));
  if(target!==root&&!target.startsWith(root+path.sep))throw Error('path');
  if((await fs.stat(target)).isDirectory())target=await fs.realpath(path.join(target,'index.html'));
  if(!target.startsWith(root+path.sep)||!(await fs.stat(target)).isFile())throw Error('path');
  res.writeHead(200,{'Content-Type':types[path.extname(target)]??'application/octet-stream','X-Content-Type-Options':'nosniff'});
  if(req.method==='HEAD')res.end();else createReadStream(target).on('error',()=>res.destroy()).pipe(res);
 }catch{res.writeHead(404);res.end('Not found');}
}).listen(Number(process.env.PORT),process.env.HOST);
