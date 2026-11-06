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
  cayenne: 'sandwich-cayenne.png',
  supreme: 'sandwich-supreme.png',
  mega: 'sandwich-mega.png',
  terminator: 'sandwich-terminator.png',
  'cheese-burger': 'cheese-burger.png',
  'double-cheese': 'double-cheese.png',
  'fish-burger': 'fish-burger.png',
  'big-burger': 'big-burger.png',
  'grill-burger': 'grill-burger.png',
  'galette-normale': 'galette.png',
  'galette-cayenne': 'galette.png',
  'tacos-m': 'tacos-cayenne.png',
  'tacos-l': 'tacos-cayenne.png',
  'tacos-xl': 'tacos-cayenne.png',
};

const canonicalCategoryImages = {
  sandwichs: 'cat-sandwich-cayenne.png',
  galette: 'cat-galette.png',
  tacos: 'cat-tacos.png',
};

const canonicalPngFiles = [
  'sandwich-cayenne.png',
  'sandwich-supreme.png',
  'sandwich-mega.png',
  'sandwich-terminator.png',
  'cheese-burger.png',
  'double-cheese.png',
  'fish-burger.png',
  'big-burger.png',
  'grill-burger.png',
  'tacos-cayenne.png',
  'galette.png',
  'cat-sandwich-cayenne.png',
  'cat-galette.png',
  'cat-tacos.png',
];

// Remaining category cards are served as PNG by the current site/borne pack.
// Keep them byte-identical too; the mobile references already use these names.
const canonicalCategoryPngFiles = [
  'cat-burgers.png',
  'cat-bols-gourmands.png',
  'cat-frites.png',
  'cat-desserts.png',
  'cat-boissons.png',
  'cat-menu-enfant.png',
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

const missingExactDrinkAssets = [
  { slug: 'orangina', label: 'Orangina 33cl', image: 'item-default.svg' },
  { slug: 'fuze-tea', label: 'Fuze Tea 33cl', image: 'item-default.svg' },
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
  'assets/menu/galette.png', 'galette pain choice uses the canonical PNG');

for (const file of canonicalPngFiles) {
  const source = path.resolve(root, '../public/images/menu', file);
  const destination = path.join(root, 'assets/menu', file);
  assert.ok(fs.existsSync(source), `canonical source exists: ${file}`);
  assert.ok(fs.existsSync(destination), `canonical destination exists: ${file}`);
  const sourceSha = shaFile(source);
  const destinationSha = shaFile(destination);
  console.log(`SHA256 ${file}: source=${sourceSha} destination=${destinationSha}`);
  assert.equal(destinationSha, sourceSha, `canonical binary copied without alteration: ${file}`);
}
for (const file of canonicalCategoryPngFiles) {
  const source = path.resolve(root, '../public/images/menu', file);
  const destination = path.join(root, 'assets/menu', file);
  assert.ok(fs.existsSync(source), `canonical category source exists: ${file}`);
  assert.ok(fs.existsSync(destination), `canonical category destination exists: ${file}`);
  const sourceSha = shaFile(source);
  const destinationSha = shaFile(destination);
  console.log(`SHA256 ${file}: source=${sourceSha} destination=${destinationSha}`);
  assert.equal(destinationSha, sourceSha, `canonical category copied without alteration: ${file}`);
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
  'chicken-burger': 'chicken_burger.png',
  'cheese-burger': 'cheese-burger.png',
  'double-cheese': 'double-cheese.png',
  'fish-burger': 'fish-burger.png',
  'big-burger': 'big-burger.png',
  'grill-burger': 'grill-burger.png',
  'menu-enfant-burger': 'chicken_burger.png',
})) assert.equal(menu.findItem(slug).image, 'assets/menu/' + file, `specific local photo: ${slug}`);
for (const slug of ['cheese-burger', 'double-cheese', 'fish-burger', 'big-burger', 'grill-burger']) {
  assert.notEqual(menu.findItem(slug).image, neutral, `burger never uses the neutral fallback: ${slug}`);
}
assert.equal(menu.findItem('tiramisu').image, 'assets/menu/tiramisu.png');

const main = read('screens-main.jsx');
const steps = read('screens-item-steps.jsx');
const compact = main.slice(main.indexOf('function ScreenHome('), main.indexOf('function ScreenItem('));
assert.doesNotMatch(compact, /<AllergenDisclosure\b/, 'no compact allergen disclosure');
assert.doesNotMatch(main, /⚠️/, 'no warning emoji remains in product presentation');
assert.doesNotMatch(main, /AllergenBadge/, 'badge compatibility name is removed from the product UI');
assert.match(main, /function AllergenDisclosure\(\{ allergens, size = 'sm' \}\)/, 'text-only allergen disclosure exists');
assert.match(main, /data-testid="allergen-disclosure"/, 'allergen disclosure has the stable test id');
assert.match(main, /gluten:\s*\{ label: 'Gluten' \}/, 'French allergen label vocabulary is explicit');
assert.match(main, /oeuf:\s*\{ label: 'Œuf' \}/, 'French egg allergen label is explicit');
assert.match(compact, /CATS\.map\(c => \(/, 'all nine home categories rendered');
assert.match(compact, /id=\{`home-category-\$\{c.id\}`\}[^>]*src=\{c.image\}[^>]*fit="contain"/);
assert.match(compact, /id=\{`menu-category-\$\{c.id\}`\}[^>]*src=\{c.image\}[^>]*fit="contain"/);
assert.match(compact, /src=\{cat.image\}[^>]*fit="contain"/, 'menu section categories illustrated');
assert.match(main, /<AllergenDisclosure allergens=\{item.allergens\} size="lg"/, 'main detail disclosure retained');
assert.match(steps, /<window.AllergenDisclosure allergens=\{item.allergens\} size="lg"/, 'direct detail disclosure retained');
assert.match(steps, /<window.AllergenDisclosure allergens=\{aggregatedAllergens\} size="lg"/, 'recap disclosure retained');
assert.match(steps, /aggregatedAllergens[\s\S]*selections\.supplementIds[\s\S]*selections\.drinkId/, 'recap keeps item + supplements + drink aggregation');
assert.equal(sha(steps.match(/function buildLineItem\([\s\S]*?\n\}/)[0]),
  '92fd480da9dc71da31397f6efcd17e4bdba7f06603b790da54777271020034d1', 'order line payload builder unchanged');
console.log('PASS: burger SHA/mappings, 9 categories, 15 drinks + wizard parity, text allergens and payload invariants');
