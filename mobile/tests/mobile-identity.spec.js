// Standalone identity/contact verifier for the bounded mobile Phase 2 change.
// Run: node mobile/tests/mobile-identity.spec.js
'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const mobileRoot = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(mobileRoot, file), 'utf8');
const sha256 = value => crypto.createHash('sha256').update(value).digest('hex');
const readBinary = file => fs.readFileSync(path.isAbsolute(file) ? file : path.join(mobileRoot, file));

const sandbox = { window: {}, console: { warn() {}, log() {}, error() {} } };
vm.createContext(sandbox);
vm.runInContext(read('data/menu.js'), sandbox, { filename: 'mobile/data/menu.js' });
const menu = sandbox.window.LC && sandbox.window.LC.menu;
assert.ok(menu, 'mobile catalog is available');

// The branch phone is the intended identity change. All other serialized menu
// data must remain byte-stable, including prices, IDs, slugs and option data.
assert.equal(menu.branch.phone, '0365678291', 'canonical branch phone is stored in menu data');
assert.equal(
  sha256(JSON.stringify(menu, (key, value) => ['image', 'hero', 'phone'].includes(key) ? undefined : value)),
  'dba1d13ba410398c0429ad164d937e28cffb91336cf176b0d384c20b313ae91d',
  'catalog business data is unchanged apart from visual paths and branch phone',
);

const shared = read('shared.jsx');
const main = read('screens-main.jsx');
const onboarding = read('screens-onboarding.jsx');
const sourceLogo = path.resolve(mobileRoot, '../public/images/kiosk-attract/logo.webp');
const mobileLogo = path.join(mobileRoot, 'assets/brand/lecayenne-logo.webp');

assert.ok(fs.existsSync(mobileLogo), 'mobile logo copy exists');
assert.equal(sha256(readBinary(mobileLogo)), 'f864bbe05441084e54bffbbccdf85b8bbbc7799e0659d38638ec17e54cc1d2fb', 'mobile logo has canonical SHA-256');
assert.ok(readBinary(mobileLogo).equals(fs.readFileSync(sourceLogo)), 'mobile logo is byte-identical to the canonical source');
assert.match(shared, /const BRAND_LOGO_SRC = 'assets\/brand\/lecayenne-logo\.webp';/);
assert.match(shared, /onError=\{\(\) => setImageFailed\(true\)\}/, 'logo has a failure fallback');
assert.match(shared, /aria-label="Le Cayenne"[\s\S]*LE[\s\S]*CAYENNE/, 'fallback remains text-readable');
assert.doesNotMatch(`${shared}\n${main}\n${onboarding}`, /RotTacos/i, 'ambiguous RotTacos identity is not referenced');

const digits = String(menu.branch.phone).replace(/\D/g, '');
const display = digits.replace(/(\d{2})(?=\d)/g, '$1 ');
assert.equal(display, '03 65 67 82 91', 'branch phone display format is canonical');
assert.match(main, /function branchPhoneInfo\(\)[\s\S]*const digits = raw\.replace\(\/\\D\/g, ''\)/);
assert.match(main, /href: digits \? `tel:\$\{digits\}` : null/);
assert.match(main, /t: 'Nous contacter', d: branchPhoneDisplay, href: branchPhoneHref/);
assert.doesNotMatch(main, /06 51 30 XX XX|tel:\+33/, 'old placeholder and non-canonical tel URI are absent');

for (const file of ['screens-main.jsx', 'screens-onboarding.jsx']) {
  assert.match(read(file), /<Logo\b/, `${file} uses the shared real-brand component`);
}

console.log('PASS: canonical logo, branch phone, callable contact, safe fallback, and catalog invariants');
