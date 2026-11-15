'use strict';
// Marges d'écran (barre d'état / encoche en haut, barre d'accueil ou de navigation en bas).
// Défaut d'origine (2026-11-15) : l'app réservait 68 px en haut et 28 px en bas sur TOUS les téléphones
// (valeurs d'un seul iPhone) — trop haut sur Android, textes et boutons sous l'heure ou coupés en bas.
// Ce test statique empêche le retour de valeurs fixes ; la mesure réelle, écran par écran, est faite par
// tools/banc-marges/parcours.mjs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const lire = (f) => fs.readFileSync(path.join(root, f), 'utf8');
const css = lire('styles.css');
const cssRd = lire('redesigns-styles.css');
const html = lire('index.html');
const ecrans = ['shared.jsx', 'screens-onboarding.jsx', 'screens-item-steps.jsx', 'screens-main.jsx', 'screens-modals.jsx',
  'components/LoyaltyQR.jsx', 'components/WizardRedeem.jsx', 'components/BarcodeMock.jsx'];
const sources = Object.fromEntries(ecrans.map(f => [f, lire(f)]));

// 1. La page couvre tout l'écran : c'est l'app qui gère les marges.
assert.match(html, /<meta name="viewport" content="[^"]*viewport-fit=cover/, 'viewport-fit=cover');

// 2. Source unique : la marge réelle du téléphone, avec le repli posé par Capacitor sur Android.
assert.match(css, /--lc-inset-top:\s*max\(env\(safe-area-inset-top, 0px\), var\(--safe-area-inset-top, 0px\)\);/, 'marge haute réelle');
assert.match(css, /--lc-inset-bottom:\s*max\(env\(safe-area-inset-bottom, 0px\), var\(--safe-area-inset-bottom, 0px\)\);/, 'marge basse réelle');
for (const jeton of ['--lc-safe-top', '--lc-safe-bottom', '--lc-tabs-pad-bottom', '--lc-tabs-h']) {
  assert.match(css, new RegExp(jeton + ':\\s*[^;]*var\\(--lc-inset-(top|bottom)\\)|' + jeton + ':\\s*[^;]*var\\(--lc-tabs-pad-bottom\\)'), jeton + ' dérive de la marge réelle');
}

// 3. Plus aucun ancien jeton fixe, nulle part.
for (const [f, s] of Object.entries({ 'styles.css': css, 'redesigns-styles.css': cssRd, 'index.html': html, ...sources })) {
  assert.doesNotMatch(s, /var\(--ios-safe-(top|bottom)\)/, f + ' : ancien jeton fixe --ios-safe-*');
  assert.doesNotMatch(s, /--ios-safe-(top|bottom)\s*:/, f + ' : définition d\'un jeton fixe');
}

// 4. Aucun conteneur défilant ne passe sous la barre d'état : il commence sous elle.
assert.match(css, /\.lc-screen \{[^}]*inset: var\(--lc-inset-top\) 0 0 0;/, '.lc-screen commence sous la barre d\'état');
assert.match(css, /\[data-screen-scroll\] \{[^}]*inset: var\(--lc-inset-top\) 0 0 0;/, 'le défilement de l\'assistant aussi');
assert.match(css, /\.lc-page \{[^}]*padding-top: var\(--lc-inset-top\);/, 'les écrans fixes aussi');
assert.doesNotMatch(sources['screens-item-steps.jsx'], /data-screen-scroll style=\{\{[^}]*inset: 0/, 'l\'assistant ne remet pas son défilement à inset 0');

// 5. Barre d'onglets et pieds d'écran : au-dessus de la barre d'accueil.
assert.match(css, /\.lc-tabs \{[^}]*padding: 10px 4px var\(--lc-tabs-pad-bottom\);/, 'barre d\'onglets');
assert.match(css, /\.lc-foot \{[^}]*calc\(var\(--lc-safe-bottom\) \+ 10px\)/, 'pied d\'écran');
assert.match(cssRd, /\.rdw-cta-wrap \{[^}]*calc\(var\(--lc-safe-bottom\) \+ 8px\)/, 'bouton de l\'assistant');

// 6. Dans les écrans : aucun bord bas ou haut chiffré « à la main » sur un élément ancré.
for (const [f, s] of Object.entries(sources)) {
  // ancré en bas de l'écran avec une marge en dur (ex. bottom: 122, padding '14px 20px 36px')
  assert.doesNotMatch(s, /position: 'absolute', left: (0|16), right: (0|16), bottom: [1-9]/, f + ' : élément ancré en bas sans jeton de marge');
  assert.doesNotMatch(s, /bottom: 0, background: '#fff', padding: '\d+px \d+px \d+px'/, f + ' : pied d\'écran sans jeton de marge');
  // écran défilant avec un dégagement bas en dur
  assert.doesNotMatch(s, /className="lc-screen"[^>]*paddingBottom: \d/, f + ' : dégagement bas en dur');
}

// 7. Le cadre d'aperçu (ordinateur) reprend les marges d'un iPhone et ne s'affiche jamais sur un écran tactile.
assert.match(html, /@media \(min-width: 480px\) and \(hover: hover\) and \(pointer: fine\) \{[\s\S]*?:root \{ --lc-inset-top: 59px; --lc-inset-bottom: 34px; \}/, 'aperçu ordinateur');
assert.doesNotMatch(html, /@media \(min-width: 480px\) \{/, 'pas de faux cadre sur tablette ou téléphone couché');

console.log('PASS: marges d\'écran — jetons réels, aucun reste de valeur fixe, défilements sous la barre d\'état');
