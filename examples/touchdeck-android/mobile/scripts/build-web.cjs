const fs=require('node:fs');
const url=process.env.DEVMCP_BACKEND_URL;
if(!url||!/^http:\/\/192\.168\.194\.189:\d+$/.test(url))throw Error('The backend of this project must already run on TouchDeck');
fs.writeFileSync('www/runtime-config.js','window.API_BASE='+JSON.stringify(url)+';\n');
