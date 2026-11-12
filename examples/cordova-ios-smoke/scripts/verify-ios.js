const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..');
assert.equal(fs.readFileSync(path.join(root, 'platforms/ios/www/prepared.js'), 'utf8'), 'window.devmcpHookPrepared = true;\n');
assert.equal(fs.readFileSync(path.join(root, 'platforms/ios/www/web-build.js'), 'utf8'), 'window.devmcpWebPrepared = true;\n');
assert.ok(fs.existsSync(path.join(root, 'platforms/ios/App.xcworkspace')));
assert.ok(!fs.existsSync(path.join(root, 'platforms/android')));
console.log('PASS: generated workspace and prepared web sources verified; Android was not generated');
