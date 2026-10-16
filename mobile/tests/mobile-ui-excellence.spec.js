'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const homeSource = fs.readFileSync(path.join(root, 'screens-main.jsx'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'styles.css'), 'utf8');
const menu = fs.readFileSync(path.join(root, 'data/menu.js'), 'utf8');
const escapeRegex = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const homeStart = homeSource.indexOf('function ScreenHome');
const homeEnd = homeSource.indexOf('// MENU', homeStart);
assert.ok(homeStart >= 0 && homeEnd > homeStart, 'ScreenHome boundary is present');
const home = homeSource.slice(homeStart, homeEnd);

for (const selector of [
  'lc-home', 'lc-home__hero', 'lc-home__categories', 'lc-home__rail',
  'lc-home__new-grid', 'lc-home__restaurant', 'data-testid="branch-phone"',
]) {
  assert.match(home, new RegExp(selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `home keeps ${selector}`);
}

assert.match(home, /src={c\.image}/, 'categories use the canonical category image');
assert.match(home, /src={it\.image}/, 'product cards use canonical product images');
assert.match(home, /featured\.price\.toFixed/, 'hero price comes from catalog data');
assert.match(home, /it\.price\.toFixed/, 'card prices come from catalog data');
assert.match(home, /onClick=\{\(\) => go\('menu'/, 'category and see-all navigation remains wired');
assert.match(home, /href=\{branchPhoneInfo\(\)\.href/, 'branch phone remains callable');

// Decorative category copy must not regress to emoji placeholders.
assert.doesNotMatch(home, /[🌶🥖🌮🥣🍔🍟🧒⚡]/, 'home decoration does not use food emoji');
assert.doesNotMatch(home, /⚠️|❗/, 'home cards do not show alert-style allergen markers');

for (const selector of [
  '.lc-home__hero', '.lc-home__categories', '.lc-home__category',
  '.lc-home__product-art', '.lc-home__new-card', '.lc-home__restaurant',
]) {
  assert.match(styles, new RegExp(escapeRegex(selector)), `styles include ${selector}`);
}
assert.match(home, /fit="contain"/, 'product cutouts keep containment');
assert.match(menu, /const BRANCH\s*=\s*\{/, 'menu data exposes the branch contract');
assert.match(menu, /phone:\s*['"]\d+['"]/, 'catalog branch contains a phone value');

console.log('PASS: Atelier Cayenne home structure, real-image wiring, prices and callable branch phone are guarded');
