const fs = require('node:fs');
const path = require('node:path');
fs.writeFileSync(path.join(__dirname, '../www/web-build.js'), 'window.devmcpWebPrepared = true;\n');
console.log('PASS: versioned web build script executed');
