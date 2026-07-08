const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({ testDir: __dirname, testMatch:['final-shot.spec.js'], workers:1, timeout:120000, retries:0, use:{ channel:'chrome', headless:true, viewport:{width:1360,height:900} }});
