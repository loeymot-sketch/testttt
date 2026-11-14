#!/usr/bin/env node

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const menuSource = fs.readFileSync(path.join(root, 'data/menu.js'), 'utf8');
const canonical = JSON.parse(fs.readFileSync(
  path.join(root, '../reports/goal-web-app-sync/catalog-canonical.json'),
  'utf8',
)).data;

const context = { window: {}, console };
vm.runInNewContext(menuSource, context, { filename: 'mobile/data/menu.js' });
const menu = context.window.LC.menu;

assert.equal(menu.branch.id, canonical.branch_id, 'mobile branch must match canonical branch');

const canonicalDrinks = canonical.items
  .filter((item) => item.category_id === 10)
  .map(({ slug, name, price }) => ({ slug, name, price }));
const mobileDrinks = Array.from(menu.items)
  .filter((item) => item.category_id === 10)
  .map(({ slug, name, price, image }) => ({ slug, name, price, image }));
const mobileBySlug = new Map(mobileDrinks.map((item) => [item.slug, item]));
const aliases = new Map([['ice-tea', 'ice-tea-peche']]);

assert.equal(canonicalDrinks.length, 15, 'the current canonical fixture must contain 15 drinks');
for (const expected of canonicalDrinks) {
  const actualSlug = aliases.get(expected.slug) || expected.slug;
  const actual = mobileBySlug.get(actualSlug);
  assert.ok(actual, `mobile drink missing: ${expected.slug}`);
  assert.equal(actual.name, expected.name, `name drift for ${expected.slug}`);
  assert.equal(actual.price, expected.price, `price drift for ${expected.slug}`);
}

const formulaAliases = new Map([
  ['d-ice-tea-peche', 'ice-tea-peche'],
  ['d-eau', 'eau-plate'],
  ['d-capri', 'capri-sun'],
]);
const formulaSlugs = new Set(Array.from(menu.formuleDrinks).map((drink) => (
  formulaAliases.get(drink.id) || drink.id.replace(/^d-/, '')
)));
assert.deepEqual(
  Array.from(formulaSlugs).sort(),
  mobileDrinks.map((drink) => drink.slug).sort(),
  'formula drink choices must expose the full mobile beverage catalogue',
);

const neutral = mobileDrinks.filter((drink) => drink.image.endsWith('item-default.svg'));
const missingFiles = mobileDrinks.filter((drink) => {
  return !fs.existsSync(path.join(root, drink.image));
});
assert.deepEqual(missingFiles, [], 'every mobile beverage image path must resolve');

console.log(`PASS: ${mobileDrinks.length} mobile drinks match the canonical names/prices.`);
console.log(`PASS: ${formulaSlugs.size} formula drink choices match the catalogue.`);
if (neutral.length) {
  console.warn(`VISUAL_BLOCKERS: ${neutral.map((drink) => drink.name).join(', ')}`);
  console.warn('These products intentionally use the neutral placeholder until an owner-approved brand asset is supplied.');
}
