const fs = require('node:fs');
const path = require('node:path');
module.exports = function (context) {
    if (!context.opts.platforms.includes('ios')) return;
    const generated = path.join(context.opts.projectRoot, 'platforms/ios/www');
    fs.writeFileSync(path.join(generated, 'prepared.js'), 'window.devmcpHookPrepared = true;\n');
    console.log('PASS: versioned after_prepare hook executed');
};
