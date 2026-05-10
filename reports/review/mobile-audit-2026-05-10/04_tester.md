# 04 — TESTER : Plan E2E Playwright exhaustif (mobile wizard refactor)

**Auteur** : AGENT-TESTER (YC GStack)
**Date** : 2026-05-10
**Scope** : Plan de tests pour le futur refactor multi-pages du wizard mobile
**Status** : PLAN ONLY — aucun spec écrit, aucun test exécuté
**Cible** : `mobile/screens-main.jsx:248-530` (ScreenItem actuel) → futur `mobile/screens-item-steps.jsx`

---

## 1. Test environment + invariants

### 1.1 Cibles
- **Mobile preview** : `http://127.0.0.1:8081/index.html` (PHP -S, port 8081)
- **Kiosk reference** : `http://127.0.0.1:8000/kiosk/idle` (Laravel, port 8000)
- **Source data** : `mobile/data/menu.js:1-341` (47 items, 13 cats)
- **SSOT backend** : `config/menu.php:47-62` (catégories), `config/menu.php:83-149` (meats/sauces/sup)

### 1.2 Browser context Playwright
```js
const context = await browser.newContext({
  viewport: { width: 390, height: 844 },              // iPhone 14
  deviceScaleFactor: 3,
  isMobile: true,
  hasTouch: true,
  userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) ...',
  locale: 'fr-FR',
});
```

### 1.3 Auth bootstrap (initScript avant `page.goto`)
Cf. pattern utilisé par `/tmp/lc-capture.mjs` :
```js
await context.addInitScript(() => {
  localStorage.setItem('lecayenne.auth', JSON.stringify({
    token: 'mock-token-v0',
    user: { id: 'u-test', name: 'Test User', phone: '+33600000000' },
    issued_at: Date.now(),
  }));
  localStorage.setItem('lecayenne.onboarding_seen', 'true');
  localStorage.removeItem('lecayenne.cart'); // clean cart per test
});
```

### 1.4 Output structure
- **Captures mobile** : `reports/test-e2e/mobile-vs-kiosk-2026-05-10/captures/<cat-slug>/<step-N>-<step-key>.png`
  - ex : `captures/nos-tacos/01-viandes.png`, `02-sauce.png`, `03-crudites.png`...
- **DOM dumps** : même chemin, suffixe `.dom.html`
- **Console logs** : même chemin, suffixe `.console.json`
- **Reference kiosk** : `reports/test-e2e/mobile-vs-kiosk-2026-05-10/kiosk-ref/<cat-slug>/`
  copies sélectives depuis `tests/e2e/__screenshots__/test-e2e-borne-B/{309,310,311,312,313,314}-*.png`

### 1.5 Invariants globaux (tous tests)
1. `page.on('pageerror')` collecte → `expect(jsErrors).toHaveLength(0)` à la fin
2. `page.on('console', m => m.type()==='error')` → tolérance 0
3. `body.innerText` ne match pas `/Whoops|Fatal error|Server Error|undefined|NaN/i`
4. Aucun raw label : pas de `Label.X`, `kiosk.X.`, `0undefined`, `composition.unknown`

---

## 2. Per-category test plan (13 catégories)

Représentation : `Cat N (slug)` — item testé — séquence d'étapes attendues post-refactor.
**Convention post-refactor** : chaque step = un écran (route hash `#step-<key>`), CTA "Suivant" pour avancer, "Retour" pour reculer, recap final consolidé.

### Cat 1 — Nos Tacos : `tacos-xxl` (id 104, viandes=4, has_menu_addon=true)
File ref : `mobile/data/menu.js:150`
Steps attendus (8 max) :
1. **viandes** — title contient "Choisis 4 viandes" ; grid de **9 meat cards** (Merguez, Kefta, Mexicain, Cordon Bleu, Viande Hachée, Nuggets, Escalope, Tenders, Fricandelle) ; CTA "Suivant" disabled tant que `meatIds.length !== 4` ; badge counter `0/4` → `4/4` (vert) ; tap meat → bordure orange + checkmark.
2. **sauce** — title "Sauce" ; grid de **15 cards** ; "Sans Sauce" exclusive (clic → vide les autres) ; 1ʳᵉ sauce free, 2ème+ affiche `+0,50 €` (data attr `data-extra-price`).
3. **crudites** — title "Crudités" ; **3 toggle cards** (Salade/Tomate/Oignon) tous default-ON (vert ✓) ; tap → ✕ rouge + `text-decoration: line-through`.
4. **supplements** — title "Suppléments" ; **6 toggle rows** (Jambon, Boursin, Raclette, Fromage, Œuf, Galette à 1,00 €) — note : data/menu.js:79-87 expose 7 items (sup-sauce 0,50 € inclus) → flag inconsistance avec config/menu.php:135-142 (6 items).
5. **menu** — title "Faire un menu ?" ; **4 radio cards** (Sans formule / Menu +3,00 € / Frites +2,00 € / Boisson +2,00 €) ; au moins une sélectionnée par défaut = "Sans formule".
6. **drink** *(si formule = `f-menu` ou `f-boisson`)* — **8 boisson radios** issues de `DRINKS` (Coca, Coca Zero, Fanta, Sprite, Oasis, Orangina, Eau, Capri-Sun) — **CETTE ÉTAPE N'EXISTE PAS DANS LE CODE ACTUEL** (cf. `screens-main.jsx:441-462` — pas de step drink). → **GAP refactor**.
7. **frites_style** *(si formule = `f-menu` ou `f-frites`)* — radios à définir (Cheddar / Cheddar+Oignons / Nature) — **NON PRÉSENT EN DB** (cf. §7 Q1).
8. **recap** — résumé composition liste meats + sauces + crudités retirées + supplements + formule ; total = base + extras + formule ; CTA "Ajouter au panier".

**Smoke final** : panier contient 1 ligne, `qty=1`, `lineTotal === recap.total`, navigation `go('cart')` déclenchée.

---

### Cat 2 — Nos Sandwichs : `le-terminator` (id 202, viandes=2, has_menu_addon=true)
File ref : `mobile/data/menu.js:156`
Steps : viandes (2/9) → sauce → crudites → supplements → menu → [drink] → recap (7 max).
Assertions clés : badge `0/2 → 2/2` ; bouton "Suivant" disabled si meatIds.length !== 2.

### Cat 3 — Nos Burgers : `double-cheese` (id 304, viandes=0, has_menu_addon=true)
File ref : `mobile/data/menu.js:170`
Steps : sauce → crudites → supplements → menu → [drink] → recap (6 max). **PAS DE STEP VIANDES** (item.viandes===0 → écran skip, transition directe sauce). Assertion : URL hash ne contient jamais `#step-viandes`.

### Cat 4 — Nos Assiettes : `assiette-poulet` (id 401, viandes=0, has_menu_addon=false)
File ref : `mobile/data/menu.js:177`
Steps : sauce → supplements → recap (3 max). Pas de crudités (`has_crudites=false`) ni de menu addon.
**FLAG** : la description backend mentionne "Poulet (Nature · Curry · Paprika)" — un step **cooking_style** est attendu mais **N'EXISTE PAS** dans data/menu.js. → **GAP refactor**, cf. §7 Q2.
Référence kiosk : `tests/e2e/__screenshots__/test-e2e-borne-B/309-{01-step-sauce,02-step-supplements,03-step-recap}.png`.

### Cat 5 — Ojja : `ojja-merguez` (id 504, viandes=0, has_sauce=true, has_supplements=true)
File ref : `mobile/data/menu.js:188`
Steps : sauce → supplements → recap (3). Référence kiosk : `tests/e2e/__screenshots__/test-e2e-borne-B/310-*.png`.
Assertion `is_spicy=true` → emoji 🌶️ dans header card.

### Cat 6 — Omelettes : `omelette-fromage` (id 602, viandes=0, has_sauce=true)
File ref : `mobile/data/menu.js:194`
Steps : sauce → supplements → recap (3). Référence kiosk : `tests/e2e/__screenshots__/test-e2e-borne-B/311-*.png`.

### Cat 7 — Nos Salades : `salade-royale` (id 702, viandes=0, has_sauce=true, has_supplements=true)
File ref : `mobile/data/menu.js:201`
**Mismatch user prompt vs data/menu.js** : prompt dit "NO wizard, direct add-to-cart" mais `has_sauce=true` + `has_supplements=true` (default true sauf override). → **wizard sera affiché** : sauce → supplements → recap (3 steps).
**Flag** : si owner veut effectivement *direct add*, il faut `has_sauce=false, has_supplements=false` dans data ; cf. §7 Q3.
Référence kiosk : `tests/e2e/__screenshots__/test-e2e-borne-B/312-cat-salades.*` (preview cat-list, pas de wizard capturé → kiosk a peut-être skip wizard pour salades).

### Cat 8 — Poulet croustillant : `wings-12` (id 802, has_sauce=true, has_supplements=true)
File ref : `mobile/data/menu.js:209`
Steps : sauce → supplements → recap (3). Pas de crudites/menu_addon. 15 sauces génériques.
**Flag** : prompt mentionne BBQ/Nashville spécifiques — **non présent en DB** (cf. §7 Q4).

### Cat 9 — Nos Menus Enfants : `menu-cheese-enfant` (id 901, has_sauce=false, has_supplements=true par default, has_menu_addon=false)
File ref : `mobile/data/menu.js:216`
**Mismatch SSOT vs mobile** :
- `config/menu.php:516` → `has_sauce: true`
- `mobile/data/menu.js:216` → `has_sauce: false` (override explicite)
→ **Flag P2**, cf. §7 Q5.
Steps mobile actuel : supplements → recap (2). Si on aligne SSOT : sauce → supplements → recap (3).
Drink Capri-Sun déjà dans la description (fixé), pas de step drink.

### Cat 10 — Frites & Accompagnements : `frites-grande` (id 1002, has_sauce=false, has_supplements=false, has_menu_addon=false)
File ref : `mobile/data/menu.js:223`
Steps : **0** — direct add-to-cart (qty stepper only).
**Flag** : prompt évoque step `frites_style` (Cheddar / Cheddar+Oignons / Nature) — **non présent en DB ni mobile**. Cf. §7 Q1.

### Cat 11 — Nos Desserts : `tiramisu` (id 1103, tous flags=false)
File ref : `mobile/data/menu.js:230`
Steps : **0** — direct add-to-cart, qty stepper only. Assertion : URL hash reste sur item-detail jusqu'au CTA "Ajouter".

### Cat 12 — Nos Boissons : `coca` (id 1201, tous flags=false)
File ref : `mobile/data/menu.js:235`
Steps : **0** — direct add. Cf. validation déjà passée HOW_TO_RESUME.md:169.

### Cat 13 — Suppléments : `item-sauce-sup` (id 1301, has_sauce=true, has_supplements=false)
File ref : `mobile/data/menu.js:247`
Steps : sauce → recap (2). 1 step utile (sauce only).

---

## 3. Pricing assertions per category

Table de régression price-correctness (à snapshoter). Calcul aligné `priceFor` (`mobile/data/menu.js:269-287`).

| # | Item | Sélections | Calcul | Total attendu |
|---|---|---|---|---|
| 1 | Tacos XXL (104) | 4 viandes + 2 sauces + Menu+3 + Œuf+1 | 12,50 + 0,50 + 3,00 + 1,00 | **17,00 €** |
| 2 | Le Terminator (202) | 2 viandes + 1 sauce + Frites+2 | 9,00 + 0 + 2,00 | **11,00 €** |
| 3 | Double Cheese (304) | 1 sauce + Boisson+2 + Boursin+1 | 7,00 + 0 + 2,00 + 1,00 | **10,00 €** |
| 4 | Assiette Poulet (401) | 1 sauce + Galette+1 | 12,50 + 0 + 1,00 | **13,50 €** |
| 5 | Ojja Merguez (504) | 3 sauces (1 free, 2×0,50) | 13,50 + 1,00 | **14,50 €** |
| 6 | Coca (1201) | qty=3 | 1,50 × 3 | **4,50 €** |
| 7 | Tiramisu (1103) | qty=2 | 3,80 × 2 | **7,60 €** |
| 8 | Frites Grande (1002) | qty=1 | 4,00 | **4,00 €** |

Format affiché : virgule décimale FR (`12,50 €`), pas de point. Assertion regex : `/^\d+,\d{2} €$/`.

---

## 4. Cross-category invariants (tous flows)

### 4.1 Console & errors
- `jsErrors.filter(/TypeError|ReferenceError|Cannot read|is not a function|is not defined/)` → length === 0
- `consoleErrors` (page.on('console')) → length === 0 (tolérance 0)
- Réponses HTTP 4xx/5xx → 0 (sauf 404 favicon toléré)

### 4.2 Raw label sweep (DOM body text)
Pour chaque step capturé :
```js
const txt = await page.locator('body').innerText();
expect(txt).not.toMatch(/Label\.\w+|kiosk\.\w+\.|composition\.unknown|0undefined|NaN €|undefined €/);
```

### 4.3 White-on-white sanity (PNG analysis post-capture)
Pour chaque PNG dans `captures/<cat>/<step>.png` :
```js
// Compter pixels avec R>240 G>240 B>240 dans la bbox de [data-screen-label]
const ratio = await page.evaluate(() => { /* PNG pixel scan inside bbox */ });
expect(ratio).toBeLessThan(0.95); // <95% near-white = pas de surface vide
```

### 4.4 Focus management
À l'entrée de chaque step :
```js
const tag = await page.evaluate(() => document.activeElement?.tagName);
const role = await page.evaluate(() => document.activeElement?.getAttribute('role'));
expect(['BUTTON','INPUT','A'].includes(tag) || ['button','radio','checkbox'].includes(role)).toBeTruthy();
```

### 4.5 Mobile-only assertions
- `expect(viewport.width).toBe(390)` — iPhone 14 strict
- `body.scrollWidth <= 390` — pas de scroll horizontal
- Sticky CTA `.lc-btn` toujours dans viewport (bottom: 24px)

---

## 5. Mobile vs Kiosk diff matrix

Référence kiosk dispo dans `tests/e2e/__screenshots__/test-e2e-borne-B/`.

| # | Cat slug | Kiosk ref disponible | Mobile expected step | Comparable | Notes |
|---|---|---|---|---|---|
| 1 | nos-tacos | ❌ aucun (pas de capture wizard tacos) | viandes/sauce/crudites/sup/menu/drink/recap | ❌ | besoin fresh kiosk capture |
| 2 | nos-sandwichs | ❌ | viandes/sauce/crudites/sup/menu/recap | ❌ | besoin fresh capture |
| 3 | nos-burgers | ❌ | sauce/crudites/sup/menu/recap | ❌ | besoin fresh capture |
| 4 | nos-assiettes | ✅ `309-{01-sauce,02-sup,03-recap}.png` | sauce/sup/recap | ✅ | `id=309` borne→assiette, 3 steps |
| 5 | ojja | ✅ `310-{01-sauce,02-sup,03-recap}.png` | sauce/sup/recap | ✅ | id=310 |
| 6 | omelettes | ✅ `311-{01-sauce,02-sup,03-recap}.png` | sauce/sup/recap | ✅ | id=311 |
| 7 | nos-salades | partial : `312-cat-salades.png` (cat list only) | sauce/sup/recap *(si wizard)* | ⚠️ | kiosk skip wizard? |
| 8 | chicken-tenders | partial : `313-cat-poulet-croustillant.png` | sauce/sup/recap | ⚠️ | kiosk wizard à capturer |
| 9 | nos-menus-enfants | ✅ `314-{01-sauce,02-sup,03-recap}.png` | sup/recap *(mobile)* ou sauce/sup/recap *(SSOT)* | ⚠️ | mismatch §7 Q5 |
| 10 | frites-accompagnements | ❌ | direct add | ❌ | possible step `frites_style` à valider |
| 11 | nos-desserts | partial : `316-cat-desserts.png` | direct add | ✅ | pas de wizard |
| 12 | nos-boissons | partial : `317-cat-boissons.png` | direct add | ✅ | pas de wizard |
| 13 | supplements | partial : `318-cat-supplements.png` | sauce/recap | ⚠️ | kiosk wizard à capturer |

**Hors scope round-1** : générer captures kiosk fraîches pour cats 1-3, 8, 10, 13. À planifier round-2.

---

## 6. Playwright spec stub (blueprint, NOT executed)

Path proposé : `tests/e2e/mobile/wizard-suite.spec.js` (ou `/tmp/lc-mobile-wizard-suite.mjs` pour itération rapide).

```js
// tests/e2e/mobile/wizard-suite.spec.js
const { test, expect, devices } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8081/index.html';
const OUT  = 'reports/test-e2e/mobile-vs-kiosk-2026-05-10/captures';

const ITEMS = [
  { cat: 'nos-tacos',              item: 'tacos-xxl',         steps: ['viandes','sauce','crudites','supplements','menu','drink','recap'] },
  { cat: 'nos-sandwichs',          item: 'le-terminator',     steps: ['viandes','sauce','crudites','supplements','menu','recap'] },
  { cat: 'nos-burgers',            item: 'double-cheese',     steps: ['sauce','crudites','supplements','menu','recap'] },
  { cat: 'nos-assiettes',          item: 'assiette-poulet',   steps: ['sauce','supplements','recap'] },
  { cat: 'ojja',                   item: 'ojja-merguez',      steps: ['sauce','supplements','recap'] },
  { cat: 'omelettes',              item: 'omelette-fromage',  steps: ['sauce','supplements','recap'] },
  { cat: 'nos-salades',            item: 'salade-royale',     steps: ['sauce','supplements','recap'] },
  { cat: 'chicken-tenders',        item: 'wings-12',          steps: ['sauce','supplements','recap'] },
  { cat: 'nos-menus-enfants',      item: 'menu-cheese-enfant',steps: ['supplements','recap'] }, // pending §7 Q5
  { cat: 'frites-accompagnements', item: 'frites-grande',     steps: ['recap'] },               // direct
  { cat: 'nos-desserts',           item: 'tiramisu',          steps: ['recap'] },               // direct
  { cat: 'nos-boissons',           item: 'coca',              steps: ['recap'] },               // direct
  { cat: 'supplements',            item: 'item-sauce-sup',    steps: ['sauce','recap'] },
];

test.use({ ...devices['iPhone 14'], locale: 'fr-FR' });

test.beforeEach(async ({ context }) => {
  await context.addInitScript(() => {
    localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'mock-token-v0', user: { id: 'u-test', name: 'T' } }));
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
    localStorage.removeItem('lecayenne.cart');
  });
});

for (const t of ITEMS) {
  test(`wizard mobile — ${t.cat} / ${t.item}`, async ({ page }) => {
    test.setTimeout(60_000);
    const errs = [];
    page.on('pageerror', (e) => errs.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });

    await page.goto(BASE);
    await page.waitForLoadState('networkidle');

    // Nav home → menu → cat → item
    await page.locator('[data-tab="menu"]').click();
    await page.locator(`[data-cat-slug="${t.cat}"]`).first().click();
    await page.locator(`[data-item-slug="${t.item}"]`).first().click();

    const dir = path.join(OUT, t.cat);
    fs.mkdirSync(dir, { recursive: true });

    for (const [i, key] of t.steps.entries()) {
      const stepN = String(i + 1).padStart(2, '0');
      // step-specific selections (TODO: complete per item)
      await maybeSelectStep(page, key, t.item);
      // capture
      await page.screenshot({ path: path.join(dir, `${stepN}-${key}.png`), fullPage: true });
      fs.writeFileSync(path.join(dir, `${stepN}-${key}.dom.html`), await page.content());
      // raw label sweep
      const text = await page.locator('body').innerText();
      expect(text).not.toMatch(/Label\.|kiosk\.\w+\.|0undefined|NaN €|undefined €/);
      // advance
      if (key !== 'recap') await page.locator('[data-cta="next-step"]').click();
    }

    // Final smoke : add-to-cart
    await page.locator('[data-cta="add-to-cart"]').click();
    await expect(page.locator('[data-screen-label="11 Cart"]')).toBeVisible();
    const cart = await page.evaluate(() => JSON.parse(localStorage.getItem('lecayenne.cart') || '[]'));
    expect(cart).toHaveLength(1);
    expect(errs).toHaveLength(0);
  });
}

async function maybeSelectStep(page, key, itemSlug) {
  // Helper minimal : sélectionne assez pour valider chaque step
  if (key === 'viandes') {
    const need = await page.evaluate(() => Number(document.querySelector('[data-step-viandes-need]')?.dataset.stepViandesNeed || 0));
    for (let i = 0; i < need; i++) await page.locator('[data-meat-card]').nth(i).click();
  }
  if (key === 'sauce') await page.locator('[data-sauce-card]').first().click();
  if (key === 'menu')  await page.locator('[data-formule="f-menu"]').click();
  if (key === 'drink') await page.locator('[data-drink-card]').first().click();
  // crudites/supplements : laisser default
}
```

**Sélecteurs `data-*` à introduire dans le refactor** :
- `[data-screen-label="<N> <Title>"]` (déjà existant)
- `[data-step-key="viandes|sauce|crudites|supplements|menu|drink|recap"]`
- `[data-meat-card][data-meat-id]`, `[data-sauce-card][data-sauce-id]`
- `[data-cta="next-step"]`, `[data-cta="prev-step"]`, `[data-cta="add-to-cart"]`
- `[data-cat-slug]`, `[data-item-slug]`

---

## 7. Open questions for AGENT-ADVERSARIAL

### Q1 — Frites styles (Cheddar / Cheddar+Oignons / Nature) : DB ou inventés ?
Source du prompt user : "ScreenStepFritesStyle expected — FLAG IF MISSING".
Évidence DB : `config/menu.php:532-551` → 2 items (Frites Moyenne, Frites Grande), aucune mention de variations.
Évidence mobile : `mobile/data/menu.js:222-224` → 2 items, `has_sauce=false, has_supplements=false`. Aucune notion de style.
**Recommandation TESTER** : assertion = AUCUN step `frites_style`, direct add-to-cart pour cat 10. Si owner veut variations, c'est un feature-add et requiert seed DB d'abord.

### Q2 — Cat 4 Assiettes : cooking_style step (Nature/Curry/Paprika) ?
Description backend `config/menu.php:328` : `'Poulet (Nature - Curry - Paprika) + Frites...'` → indication textuelle uniquement, pas de structure.
Mobile : aucun step prévu (`viandes=0, has_crudites=false, has_menu_addon=false`).
**Recommandation TESTER** : current state = NO step. Si refactor doit exposer ces 3 cuisson → besoin nouveau flag `cooking_styles: ['nature','curry','paprika']` dans data + migration backend `ItemAttribute`.

### Q3 — Cat 7 Salades : direct add ou wizard sauce/sup ?
Mobile data : `has_sauce=true` (default) → wizard affiché.
Prompt user : "NO wizard, direct add-to-cart".
**Conflit**. Owner doit trancher : (A) override `has_sauce=false, has_supplements=false` dans data → direct, (B) garder wizard sauce/sup. Recommandation tester = (A) cohérent avec UX salade composée fixe.

### Q4 — Cat 8 Wings : sauces génériques (15) ou BBQ/Nashville spécifiques ?
DB : `config/menu.php:100-116` → 15 sauces génériques partagées par tous les items `has_sauce`.
Aucune sauce BBQ/Nashville en DB (Barbecue existe ligne 108, Nashville n'existe pas).
**Recommandation TESTER** : assertion = 15 sauces génériques identiques aux autres categories. Pas de variation per-item.

### Q5 — Cat 9 Menus Enfants : has_sauce=true (SSOT) vs false (mobile) ?
- `config/menu.php:516` : `'has_sauce' => true`
- `mobile/data/menu.js:216` : `has_sauce: false` (override explicite)
**Drift confirmé**. Mobile n'expose pas de step sauce alors que SSOT le demande. Recommandation : aligner mobile sur SSOT (`has_sauce: true`) + tester step sauce affiché. Sinon corriger SSOT côté seeder.

---

## 8. Acceptance criteria (refactor done = green sur tous ces points)

1. ✅ Les 13 catégories ont une test entry dans `ITEMS[]` (§6).
2. ✅ Chaque step capturé en PNG + DOM dans `captures/<cat>/<step>.png`.
3. ✅ 8 pricing combos verts (table §3).
4. ✅ Invariants §4 verts pour les 13 cats : 0 console error, 0 raw label, white-on-white < 95%, focus interactive.
5. ✅ Cart final : 1 ligne, qty correct, lineTotal === recap.total.
6. ✅ Diff visuel mobile vs kiosk (cats 4/5/6/9) — ratio similarité layout > 60% (logique identique, design libre).
7. ✅ Aucune régression sur les tests existants (`tests/e2e/03-kiosk-wizard.spec.js`).

---

## 9. Estimation effort

| Phase | Effort tester |
|---|---|
| Stub spec + helpers (§6) | 1h |
| Implémentation 13 tests + selections per step | 3h |
| Pricing fixtures (§3) + assertions | 0,5h |
| White-on-white + focus assertions (§4) | 0,5h |
| Diff matrix kiosk-vs-mobile + capture script | 1h |
| Debug + flake stabilization | 1h |
| **Total** | **~7h** post-refactor |

---

## 10. Risk register

| Risque | Mitigation |
|---|---|
| Sélecteurs `data-*` absents post-refactor | Bloquer le refactor sur l'introduction de `[data-step-key]` + `[data-cta]` (cf. §6) |
| Mobile preview port 8081 indispo | Spawn `php -S 127.0.0.1:8081 -t mobile/` dans `globalSetup` Playwright |
| LocalStorage pollué entre tests | `removeItem('lecayenne.cart')` dans `beforeEach` (déjà §1.3) |
| Babel-standalone slow → flake | `waitForLoadState('networkidle')` + `waitForFunction(() => window.LC?.menu)` |
| Drift kiosk references (cats 4-6) | Capturer fresh refs round-2 si discordance sémantique |

---

— *Fin AGENT-TESTER. Plan exhaustif 13 cats, 8 pricing combos, 5 questions ouvertes pour ADVERSARIAL.*
