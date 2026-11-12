'use strict';
let client,ready=false;const $=id=>document.getElementById(id);
async function request(route,options){
 if(typeof window.API_BASE!=='string')throw Error('Adresse du backend absente de ce build');
 const response=await fetch(window.API_BASE+route,{...options,signal:AbortSignal.timeout(12000)});
 const data=await response.json();if(!response.ok)throw Error(data.error||'HTTP '+response.status);return data;
}
function render(data){$('count').textContent=data.count;$('status').textContent='Connecté · dernière action : '+data.lastClient;$('status').className='';$('details').textContent=data.server+' · '+new Date(data.updatedAt).toLocaleString()+' · commit '+data.revision.slice(0,8);}
function failed(error){$('status').textContent=error.message+' — Vérifie ZeroTier et l’accès au réseau local.';$('status').className='error';}
async function refresh(){try{render(await request('/api/counter'));$('increment').disabled=!ready;}catch(error){failed(error);}}
async function start(){
 try{
  if(window.cordova){if(!window.device||!window.devmcpPrepared)throw Error('Plugin natif ou hook de préparation absent');client=device.platform;if(!['iOS','Android'].includes(client))throw Error('Plateforme native inconnue');$('downloads').hidden=true;}else client='web';
  ready=true;$('platform').textContent=client==='web'?'Version web':client+' · Cordova';$('backend').textContent=window.API_BASE;
  await refresh();
  if(client==='web'){
   const releases=await request('/api/releases');if(releases.length){$('releases').replaceChildren();for(const release of releases){const box=document.createElement('div');box.className='release';const link=document.createElement('a');link.className='download';link.textContent=release.platform==='ios'?'Installer sur iPhone / iPad':'Télécharger l’APK Android';link.href=release.platform==='ios'?release.build_url:release.download_url;const detail=document.createElement('small');detail.textContent='Version '+release.version+' · '+release.revision.slice(0,8);box.append(link,detail);$('releases').append(box);}}
  }
 }catch(error){failed(error);}
}
$('increment').addEventListener('click',async()=>{if(!ready)return;$('increment').disabled=true;try{render(await request('/api/increment',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({client})}));}catch(error){failed(error);}finally{$('increment').disabled=false;}});
$('refresh').addEventListener('click',refresh);
if(window.cordova)document.addEventListener('deviceready',start,false);else start();
