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

const canonicalProductImages = {
  cayenne: 'sandwich-cayenne.webp',
  supreme: 'sandwich-supreme.webp',
  mega: 'sandwich-mega.webp',
  terminator: 'sandwich-terminator.webp',
  'galette-normale': 'galette.webp',
  'galette-cayenne': 'galette.webp',
  'tacos-m': 'tacos-cayenne.webp',
  'tacos-l': 'tacos-cayenne.webp',
  'tacos-xl': 'tacos-cayenne.webp',
};

const canonicalCategoryImages = {
  sandwichs: 'cat-sandwich-cayenne.webp',
  galette: 'cat-galette.webp',
  tacos: 'cat-tacos.webp',
};

const canonicalBinaryFiles = [
  'sandwich-cayenne.webp',
  'sandwich-supreme.webp',
  'sandwich-mega.webp',
  'sandwich-terminator.webp',
  'tacos-cayenne.webp',
  'galette.webp',
  'cat-sandwich-cayenne.webp',
  'cat-galette.webp',
  'cat-tacos.webp',
];

const canonicalDrinkBinaryFiles = [
  'coca.png',
  'coca-zero.png',
  'coca-cherry.png',
  'tropico.png',
  'lipton-peche.png',
  'fanta-citron.png',
  'fanta-orange.png',
  'oasis.png',
  'sprite.png',
  'eau.png',
  'capri-sun.png',
];

const canonicalExternalDrinkAssets = [
  {
    file: 'fuze-tea.png',
    source: path.resolve(root, '../../testttt-kiosk/frontend public/public/upload_files/09-2022/fe1b1ad469e24302aed19e29dbcb7f86.Fuze_Tea_Peche.png'),
  },
];

const missingExactDrinkAssets = [
  { slug: 'hawai', label: 'Hawaï 33cl', image: 'item-default.svg' },
  { slug: 'perrier', label: 'Perrier 33cl', image: 'item-default.svg' },
  { slug: null, label: 'Schweppes Agrumes', image: null },
  { slug: null, label: 'Oasis Cassis', image: null },
  { slug: null, label: 'Coca bouteille', image: null },
];

function shaFile(file) {
  return sha(fs.readFileSync(file));
}

for (const [slug, file] of Object.entries(canonicalProductImages)) {
  const item = menu.findItem(slug);
  const expected = 'assets/menu/' + file;
  assert.equal(item.image, expected, `canonical product image: ${slug}`);
  assert.equal(item.hero, expected, `canonical product hero reuses image: ${slug}`);
}
for (const [slug, file] of Object.entries(canonicalCategoryImages)) {
  assert.equal(menu.categories.find(category => category.slug === slug).image,
    'assets/menu/' + file, `canonical category image: ${slug}`);
}
assert.equal(menu.pains.find(pain => pain.id === 'pain-galette').image,
  'assets/menu/galette.webp', 'galette pain choice uses the canonical WebP');

for (const file of canonicalBinaryFiles) {
  const source = path.resolve(root, '../public/images/menu/thumbs', file);
  const destination = path.join(root, 'assets/menu', file);
  assert.ok(fs.existsSync(source), `canonical source exists: ${file}`);
  assert.ok(fs.existsSync(destination), `canonical destination exists: ${file}`);
  const sourceSha = shaFile(source);
  const destinationSha = shaFile(destination);
  console.log(`SHA256 ${file}: source=${sourceSha} destination=${destinationSha}`);
  assert.equal(destinationSha, sourceSha, `canonical binary copied without alteration: ${file}`);
}
for (const file of canonicalDrinkBinaryFiles) {
  const source = path.resolve(root, '../public/images/menu', file);
  const destination = path.join(root, 'assets/menu', file);
  assert.ok(fs.existsSync(source), `canonical drink source exists: ${file}`);
  assert.ok(fs.existsSync(destination), `canonical drink destination exists: ${file}`);
  const sourceSha = shaFile(source);
  const destinationSha = shaFile(destination);
  console.log(`SHA256 drink ${file}: source=${sourceSha} destination=${destinationSha}`);
  assert.equal(destinationSha, sourceSha, `canonical drink binary copied without alteration: ${file}`);
}
for (const { file, source } of canonicalExternalDrinkAssets) {
  const destination = path.join(root, 'assets/menu', file);
  assert.ok(fs.existsSync(source), `canonical kiosk source exists: ${file}`);
  assert.ok(fs.existsSync(destination), `canonical kiosk destination exists: ${file}`);
  const sourceSha = shaFile(source);
  const destinationSha = shaFile(destination);
  console.log(`SHA256 kiosk drink ${file}: source=${sourceSha} destination=${destinationSha}`);
  assert.equal(destinationSha, sourceSha, `canonical kiosk binary copied without alteration: ${file}`);
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
  'fuze-tea': 'fuze-tea.png', hawai: 'item-default.svg', perrier: 'item-default.svg',
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
for (const missing of missingExactDrinkAssets) {
  if (missing.slug) {
    assert.equal(menu.findItem(missing.slug).image, 'assets/menu/' + missing.image,
      `missing exact brand asset stays neutral: ${missing.label}`);
    assert.equal(menu.formuleDrinks.find(d => d.id === 'd-' + missing.slug).image,
      'assets/menu/' + missing.image,
      `missing exact brand asset stays neutral in formula choices: ${missing.label}`);
  }
}
assert.deepEqual(
  missingExactDrinkAssets.filter(item => item.slug === null).map(item => item.label),
  ['Schweppes Agrumes', 'Oasis Cassis', 'Coca bouteille'],
  'unavailable owner-supplied drink assets remain explicitly listed',
);
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
