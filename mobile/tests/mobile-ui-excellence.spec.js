'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const homeSource = fs.readFileSync(path.join(root, 'screens-main.jsx'), 'utf8');
const itemStepsSource = fs.readFileSync(path.join(root, 'screens-item-steps.jsx'), 'utf8');
const indexSource = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'styles.css'), 'utf8');
const menu = fs.readFileSync(path.join(root, 'data/menu.js'), 'utf8');
const escapeRegex = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const homeStart = homeSource.indexOf('function ScreenHome');
const homeEnd = homeSource.indexOf('// MENU', homeStart);
assert.ok(homeStart >= 0 && homeEnd > homeStart, 'ScreenHome boundary is present');
const home = homeSource.slice(homeStart, homeEnd);
const disclosureStart = homeSource.indexOf('function AllergenDisclosure');
const disclosureEnd = homeSource.indexOf('// HOME', disclosureStart);
assert.ok(disclosureStart >= 0 && disclosureEnd > disclosureStart, 'AllergenDisclosure boundary is present');
const disclosure = homeSource.slice(disclosureStart, disclosureEnd);

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
assert.match(home, /const newItems = \[[\s\S]*ITEMS\.filter\(i => i\.is_new \|\| \(i\.tags \|\| \[\]\)\.includes\('NOUVEAU'\)\)[\s\S]*ITEMS\.filter\(i => \(i\.tags \|\| \[\]\)\.includes\('TOP'\)\)[\s\S]*\.slice\(0, 4\)/, 'Nouveautés prioritizes is_new/NOUVEAU and keeps TOP fallback capped at four');
assert.match(menu, /mkItem\(503,\s*'tacos-xl'[\s\S]*?is_new:\s*true/, 'catalog marks Tacos XL as a new arrival');

// Decorative category copy must not regress to emoji placeholders.
assert.doesNotMatch(home, /[🌶🥖🌮🥣🍔🍟🧒⚡]/, 'home decoration does not use food emoji');
assert.doesNotMatch(home, /⚠️|❗/, 'home cards do not show alert-style allergen markers');

// Allergen information is text-first and stays off dense cards/image slots.
assert.match(homeSource, /function AllergenDisclosure\(\{ allergens, size = 'sm' \}\)/, 'text-first allergen disclosure exists');
assert.match(homeSource, /<details[\s\S]*data-testid="allergen-disclosure"/, 'disclosure uses a native keyboard-accessible details control');
assert.match(homeSource, /<summary[\s\S]*Informations allergènes/, 'disclosure has a visible text summary');
assert.match(homeSource, /role="region" aria-label=\{`Allergènes : \$\{labelText\}`\}/, 'disclosure region is screen-reader labelled');
assert.match(homeSource, /const AllergenBadge = AllergenDisclosure/, 'legacy badge name is a disclosure-only compatibility alias');
assert.doesNotMatch(homeSource, /icon:\s*['"][^'"]+['"]/, 'allergen metadata contains no icon vocabulary');
assert.doesNotMatch(disclosure, /[🌾🥛🥚🥜🌰🫘🐟🦐🦑🥬🍇🌿⚠️❗]/, 'allergen disclosure contains no emoji/icon warning markers');
assert.match(homeSource, /function reactivateLoyaltyAccount\(\)/, 'loyalty reactivation has a customer-facing helper');
assert.match(homeSource, /storage\.setConsent\('opted_in'\)/, 'reactivation persists opted-in consent through public storage');
assert.match(homeSource, /loyalty\._replaceAccount\(\{[\s\S]*consent_status:\s*'opted_in',[\s\S]*balance:\s*0,[\s\S]*lifetime_earned:\s*0,[\s\S]*lifetime_redeemed:\s*0/, 'reactivation preserves the current zero-point behavior');
assert.match(homeSource, /new window\.CustomEvent\('lc:loyalty-changed'/, 'reactivation emits the existing loyalty change event');
assert.match(homeSource, /data-testid="loyalty-reactivate-btn"[\s\S]*onClick=\{reactivateLoyaltyAccount\}/, 'reactivation control is wired without LC.dev');
assert.doesNotMatch(homeSource, /loyalty-reactivate-btn[\s\S]*window\.LC\.dev\.setConsent/, 'customer-facing reactivation does not depend on the dev namespace');
assert.match(homeSource, /data-lc-decoration="loyalty-pattern"[\s\S]*pointerEvents:\s*'none'/, 'loyalty decoration cannot intercept customer clicks');
assert.match(indexSource, /window\.LC\.storage\.setConsent\('opted_out'\)/, 'opt-out persists consent through public storage');
assert.match(indexSource, /_replaceAccount\(\{\s*consent_status:\s*'opted_out',[\s\S]*balance:\s*0,[\s\S]*lifetime_earned:\s*0,[\s\S]*lifetime_redeemed:\s*0/, 'opt-out preserves the current zero-point behavior');
assert.match(indexSource, /new window\.CustomEvent\('lc:loyalty-changed'/, 'opt-out emits the existing loyalty change event');
assert.doesNotMatch(indexSource, /LC\.dev\.setConsent\('opted_out'\)/, 'opt-out does not depend on the dev namespace');
assert.match(itemStepsSource, /window\.AllergenBadge/, 'wizard consumes the disclosure-only compatibility alias');
assert.match(itemStepsSource, /aggregatedAllergens/, 'recap keeps aggregate allergen computation');
assert.match(itemStepsSource, /selections\.supplementIds[\s\S]*selections\.drinkId/, 'aggregate includes supplements and drinks');
assert.match(itemStepsSource, /window\.AllergenBadge/, 'detail and recap use the text-first disclosure');

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
