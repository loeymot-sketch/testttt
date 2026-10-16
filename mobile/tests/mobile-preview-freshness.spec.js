'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const VERSION = 'v=20261016';
const VERSION_BY_RESOURCE = new Map([
  ['styles.css', 'v=20261016-r2'],
  ['redesigns-styles.css', 'v=20261016-r2'],
  ['screens-main.jsx', 'v=20261016-r2'],
]);

const localScriptOrder = [...html.matchAll(/<script[^>]+src="([^"]+)"/g)]
  .map(match => match[1])
  .filter(src => !src.startsWith('http'))
  .map(src => src.split('?')[0]);
const expectedOrder = [
  'api/storage.js', 'api/client.js', 'data/menu.js', 'data/loyalty.js',
  'data/loyaltyRewardState.js', 'data/orders.js', 'data/user.js',
  'data/wallet-spec.js', 'data/dev-helpers.js', 'image-slot.js', 'icons.jsx',
  'shared.jsx', 'vendor/qrcode.js', 'hooks/useCountdown.js',
  'hooks/useLoyaltyQR.js', 'components/BarcodeMock.jsx', 'components/LoyaltyQR.jsx',
  'components/WizardRedeem.jsx', 'screens-onboarding.jsx', 'screens-item-steps.jsx',
  'screens-main.jsx', 'screens-modals.jsx',
];
assert.deepEqual(localScriptOrder, expectedOrder, 'local script order is unchanged');

const localResources = [...html.matchAll(/<(?:script[^>]+src|link[^>]+href)="([^"]+)"/g)]
  .map(match => match[1])
  .filter(url => !url.startsWith('http'));
const expectedLocalResources = [
  'styles.css', 'redesigns-styles.css',
  ...expectedOrder,
];
assert.equal(localResources.length, expectedLocalResources.length, 'exactly 24 local resources are present');
assert.deepEqual(
  localResources.map(url => url.split('?')[0]),
  expectedLocalResources,
  'local CSS/script resource set and order are unchanged'
);
for (const url of localResources) {
  const resource = url.split('?')[0];
  assert.equal(url.split('?')[1], VERSION_BY_RESOURCE.get(resource) || VERSION, `local resource has the preview version: ${url}`);
}

const reactTags = [...html.matchAll(/<script[^>]+src="(https:\/\/[^\"]+)"[^>]*>/g)].map(match => match[0]);
const expectedCdnTags = [
  '<script src="https://unpkg.com/react@18.3.1/umd/react.development.js" integrity="sha384-hD6/rw4ppMLGNu3tX5cjIb+uRZ7UkRJ6BPkLpg4hAu/6onKUg4lLsHAs9EBPT82L" crossorigin="anonymous">',
  '<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.development.js" integrity="sha384-u6aeetuaXnQ38mYT8rp6sbXaQe3NL9t+IBXmnYxwkUI2Hw4bsp2Wvmx4yRQF1uAm" crossorigin="anonymous">',
  '<script src="https://unpkg.com/@babel/standalone@7.29.0/babel.min.js" integrity="sha384-m08KidiNqLdpJqLq95G/LEi8Qvjl/xUYll3QILypMoQ65QorJ9Lvtp2RXYGBFj1y" crossorigin="anonymous">',
];
assert.deepEqual(reactTags, expectedCdnTags, 'CDN URLs, order, integrity and crossorigin match the immutable baseline');
assert.ok(reactTags.every(tag => !tag.includes('?v=')), 'CDN scripts are not cache-busted locally');
assert.match(html, /<link rel="preconnect" href="https:\/\/fonts\.googleapis\.com">/);
assert.match(html, /<link rel="preconnect" href="https:\/\/fonts\.gstatic\.com" crossorigin>/);
assert.equal(
  html.match(/<link href="(https:\/\/fonts\.googleapis\.com\/css2\?family=[^"]+)" rel="stylesheet">/)?.[1],
  'https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap',
  'Google Fonts URL matches the immutable baseline'
);
assert.doesNotMatch(html, /fonts\.googleapis\.com[^\"']*v=20261016/, 'Google Fonts is not locally versioned');

assert.equal(html.match(/apiBase: '([^']+)'/)?.[1], 'http://127.0.0.1:8766', 'apiBase baseline unchanged');
assert.equal(html.match(/branchId: (\d+)/)?.[1], '1', 'branchId baseline unchanged');
assert.equal(html.match(/onlineCardEnabled: (true|false)/)?.[1], 'false', 'online payment baseline unchanged');

console.log(`PASS: ${localResources.length} local resources versioned; CDN order, integrity and runtime config preserved`);
