// Run: node mobile/tests/mobile-catalog-visual.spec.js — standalone, no dependencies.
'use strict';
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const sandbox = { window: {}, console };
vm.runInNewContext(read('data/menu.js'), sandbox);
const menu = sandbox.window.LC.menu;
const neutral = 'assets/menu/item-default.svg';

// Snapshot taken before this presentation-only mission; exclude only image paths.
// Guards names, slugs, IDs, prices, allergens, flags and option metadata.
// The branch phone is intentionally covered by mobile-identity.spec.js.
assert.equal(sha(JSON.stringify(menu, (key, value) => ['image', 'hero', 'phone'].includes(key) ? undefined : value)),
  'dba1d13ba410398c0429ad164d937e28cffb91336cf176b0d384c20b313ae91d',
  'all nonvisual catalog data remains unchanged');

function localAsset(file) {
  assert.ok(file.startsWith('assets/menu/'), `local menu image: ${file}`);
  assert.ok(!file.includes('..') && !file.includes('generated_'), `existing selected asset: ${file}`);
  assert.ok(fs.existsSync(path.join(root, file)), `asset exists: ${file}`);
}
for (const item of menu.items) {
  localAsset(item.image);
  localAsset(item.hero);
}
assert.equal(menu.categories.length, 9);
for (const category of menu.categories) {
  localAsset(category.image);
  assert.equal(sandbox.window.CATS.find(c => c.id === category.slug).image, category.image);
}

const expectedDrinks = {
  coca: 'coca.png', 'coca-zero': 'coca-zero.png', fanta: 'fanta-orange.png',
  sprite: 'sprite.png', oasis: 'oasis.png', orangina: 'item-default.svg',
  'eau-plate': 'eau.png', 'capri-sun': 'capri-sun.png', 'coca-cherry': 'coca-cherry.png',
  tropico: 'tropico.png', 'ice-tea-peche': 'lipton-peche.png', 'fanta-citron': 'fanta-citron.png',
  'fuze-tea': 'item-default.svg', hawai: 'item-default.svg', perrier: 'item-default.svg',
};
assert.equal(menu.items.filter(i => i.category_id === 10).length, 15);
assert.equal(menu.formuleDrinks.length, 15);
assert.equal(menu.branch.id, 1, 'mobile catalogue stays on the active Le Cayenne branch');
assert.equal(menu.branch.phone, '0365678291', 'branch phone matches the borne/backend canonical phone');
for (const [slug, file] of Object.entries(expectedDrinks)) {
  const item = menu.findItem(slug);
  assert.equal(item.image, 'assets/menu/' + file, `correct brand/flavour or neutral: ${slug}`);
  // Only these two option IDs differ from the canonical item slug.
  const optionId = slug === 'eau-plate' ? 'd-eau' : slug === 'capri-sun' ? 'd-capri' : 'd-' + slug;
  assert.equal(menu.formuleDrinks.find(d => d.id === optionId).image, item.image,
    `same drink image in catalog and both wizard drink pools: ${slug}`);
}
for (const file of ['coca-cherry.png', 'lipton-peche.png', 'fanta-citron.png']) {
  assert.ok(fs.readFileSync(path.join(root, 'assets/menu', file))
    .equals(fs.readFileSync(path.resolve(root, '../public/images/menu', file))),
  `canonical binary copied without alteration: ${file}`);
}
for (const [slug, file] of Object.entries({
  'chicken-burger': 'chicken_burger.png', 'fish-burger': 'fish_burger.png',
  'double-cheese': 'double_cheese.png', 'big-burger': 'big_burger.png',
  'menu-enfant-burger': 'chicken_burger.png',
})) assert.equal(menu.findItem(slug).image, 'assets/menu/' + file, `specific local photo: ${slug}`);
for (const slug of ['cheese-burger', 'grill-burger']) assert.equal(menu.findItem(slug).image, neutral);
for (const slug of ['supreme', 'mega', 'terminator']) {
  assert.equal(menu.findItem(slug).image, `assets/menu/signature/${slug}-hero.png`);
  assert.equal(menu.findItem(slug).hero, menu.findItem(slug).image);
}
assert.equal(menu.findItem('tiramisu').image, 'assets/menu/tiramisu.png');

const main = read('screens-main.jsx');
const steps = read('screens-item-steps.jsx');
const compact = main.slice(main.indexOf('function ScreenHome('), main.indexOf('function ScreenItem('));
assert.doesNotMatch(compact, /<AllergenBadge\b/, 'no compact allergen badge');
assert.doesNotMatch(main, /⚠️/, 'no warning emoji remains in product presentation');
assert.match(compact, /CATS\.map\(c => \(/, 'all nine home categories rendered');
assert.match(compact, /id=\{`home-category-\$\{c.id\}`\}[^>]*src=\{c.image\}[^>]*fit="contain"/);
assert.match(compact, /id=\{`menu-category-\$\{c.id\}`\}[^>]*src=\{c.image\}[^>]*fit="contain"/);
assert.match(compact, /src=\{cat.image\}[^>]*fit="contain"/, 'menu section categories illustrated');
assert.match(main, /<AllergenBadge allergens=\{item.allergens\} size="lg"/, 'legacy detail disclosure retained');
assert.match(steps, /<window.AllergenBadge allergens=\{item.allergens\} size="lg"/, 'direct detail disclosure retained');
assert.match(steps, /<window.AllergenBadge allergens=\{aggregatedAllergens\} size="lg"/, 'recap disclosure retained');
assert.equal(sha(steps.match(/function buildLineItem\([\s\S]*?\n\}/)[0]),
  '92fd480da9dc71da31397f6efcd17e4bdba7f06603b790da54777271020034d1', 'order line payload builder unchanged');
console.log('PASS: 39 product visuals, 9 categories, 15 drinks + wizard parity, canonical copies, allergens and business invariants');
