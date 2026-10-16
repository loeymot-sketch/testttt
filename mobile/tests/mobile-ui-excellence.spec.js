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
const meatStepStart = itemStepsSource.indexOf('function ScreenStepViandes');
const meatStepEnd = itemStepsSource.indexOf('// STEP 2 — Sauce', meatStepStart);
const menuStepStart = itemStepsSource.indexOf('function ScreenStepMenu');
const menuStepEnd = itemStepsSource.indexOf('// STEP 6 — Drink', menuStepStart);
assert.ok(meatStepStart >= 0 && meatStepEnd > meatStepStart, 'meat choice step boundary is present');
assert.ok(menuStepStart >= 0 && menuStepEnd > menuStepStart, 'formula choice step boundary is present');
const meatStep = itemStepsSource.slice(meatStepStart, meatStepEnd);
const menuStep = itemStepsSource.slice(menuStepStart, menuStepEnd);

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
assert.match(indexSource, /api\.deleteAccount\(\)/, 'account deletion calls the authenticated mobile API');
assert.match(indexSource, /storage\.clearAuth\(\);[\s\S]*setHistory\(\['login'\]\)/, 'local identity is purged only before returning to login');
assert.match(homeSource, /data-testid="delete-account-btn"[\s\S]*go\('deleteAccount'\)/, 'profile exposes account deletion for authenticated users');
assert.match(indexSource, /ModalDeleteAccountConfirm/, 'account deletion requires the dedicated confirmation modal');
assert.match(indexSource, /if \(result && result\.status === false\)/, 'server-declared failure cannot become a local success');
assert.match(itemStepsSource, /window\.AllergenBadge/, 'wizard consumes the disclosure-only compatibility alias');
assert.match(itemStepsSource, /aggregatedAllergens/, 'recap keeps aggregate allergen computation');
assert.match(itemStepsSource, /selections\.supplementIds[\s\S]*selections\.drinkId/, 'aggregate includes supplements and drinks');
assert.match(itemStepsSource, /window\.AllergenBadge/, 'detail and recap use the text-first disclosure');

// Phase 18 — visual choice surfaces use real local product assets and never
// expose the legacy food-emoji placeholders in the wizard.
assert.match(meatStep, /m\.image/, 'meat choices consume the catalog image field');
assert.match(meatStep, /data-testid=\{`meat-choice-image-\$\{m\.id\}`\}/, 'meat images are addressable for E2E load checks');
assert.doesNotMatch(meatStep, /m\.emoji|[\u{1F300}-\u{1FAFF}]/u, 'meat choices have no visible emoji fallback');
for (const asset of ['menu_complet.png', 'frites.png', 'boisson.png']) {
  assert.match(itemStepsSource, new RegExp(`assets/menu/${asset}`), `formula choice uses ${asset}`);
}
assert.match(menuStep, /data-testid=\{`formula-choice-image-\$\{o\.id\}`\}/, 'formula images are addressable for E2E load checks');
assert.match(menuStep, /label="Sans formule — aucune formule sélectionnée"/, 'non-product formula choice has an accessible neutral fallback');
assert.doesNotMatch(menuStep, /o\.emoji|[\u{1F300}-\u{1FAFF}]/u, 'formula choices have no visible emoji fallback');
// Legacy emoji prefixes remain in the order-line payload for kiosk/backend
// compatibility; only visible choice surfaces are forbidden from rendering
// them.
assert.match(itemStepsSource, /summary\.push\('🥤 '/, 'order-line payload compatibility is preserved');

for (const selector of [
  '.lc-home__hero', '.lc-home__categories', '.lc-home__category',
  '.lc-home__product-art', '.lc-home__new-card', '.lc-home__restaurant',
  '.lc-wizard-choice-image', '.lc-wizard-choice-image--meat',
]) {
  assert.match(styles, new RegExp(escapeRegex(selector)), `styles include ${selector}`);
}
assert.match(home, /fit="contain"/, 'product cutouts keep containment');
assert.match(menu, /const BRANCH\s*=\s*\{/, 'menu data exposes the branch contract');
assert.match(menu, /phone:\s*['"]\d+['"]/, 'catalog branch contains a phone value');

console.log('PASS: Atelier Cayenne home structure, real-image wiring, prices and callable branch phone are guarded');
