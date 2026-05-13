# K02 — Idle / Welcome screen

> Auditeur : sub-agent K02 (read-only)
> Branch : `feature/mobile-app-le-cayenne-2026-05-10` — HEAD `245e8ab57`
> Date : 2026-05-11
> Owner design mandate : LIGHT MODE, palette **noir/rouge Cayenne/jaune/blanc**, "très flat et vraiment bien organisé"
>   (cf. memory `project_kiosk_design_refresh_2026-05-10.md`, `feedback_design_flat_organized.md`)

## Files audited
- `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` — 834 lignes (NON-frozen ; refresh design en cours)
- Co-lecture (read-only) :
  - `resources/css/kiosk/tokens-bold.css` — 275 lignes (overrides V2 palette Cayenne)
  - `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (FROZEN, lignes 1085-1194) — définit `--kiosk-idle-bg` light + dark
  - `resources/js/composables/useKioskTheme.js` — cascade `data-kiosk-theme`
  - `resources/js/languages/{fr,en,ar}.json` — i18n keys `kiosk.idle_screen.*`, `kiosk.order_type.*`, `kiosk.choose_language`, `kiosk.a11y.open`
- Recent commits (`git log --oneline -10 -- <file>`) :
  - `c0b81a4c1` fix(iter15-mega): round-8 — C-039/C-040/C-041/C-042 closures
  - `22bb2c74d` fix(iter15-mega): comprehensive run-1..run-5 mega-audit fixes + run-6 D5-003
  - `4a79dac00` heal(P1a-fr-lock): retire `setLocale` calls runtime — ADR-007 immutable
  - `53f1ea45c` / `b873d4728` / `183e69202` canary T08b…T20 remediations

## Findings

### P0 (blocker pre-merge V1)

#### K02-P0-01 : Texte invisible — off-white sur dégradé blanc→pêche (light mode)
- **Files** :
  - `KioskIdleScreenComponent.vue:342` → `.kiosk-idle--bold { color: #FFF5E8; }` (commentaire ligne 338-341 : *"on fixe la couleur claire en dur"*).
  - `KioskIdleScreenComponent.vue:447-449` → `.kiosk-idle-brand { color: #FFF5E8; text-shadow: 0 4px 24px rgba(0,0,0,0.7); }`
  - `KioskIdleScreenComponent.vue:464-468` → `.kiosk-idle-title { color: #FFF5E8; }`
  - `KioskIdleScreenComponent.vue:471-478` → `.kiosk-idle-subtitle { color: rgba(255,245,232,0.88); }`
  - `KioskIdleScreenComponent.vue:533-541` → `.kiosk-idle-tap-hint { color: rgba(255,245,232,0.85); }`
  - `tokens-bold.css:250` → `--kiosk-idle-bg: linear-gradient(180deg, #FFFFFF 0%, #FFE8DD 55%, #F4501E 100%) !important;`
  - `tokens-bold.css:273-275` → `.kiosk-idle-overlay { background: transparent !important; }` (neutralisé en light)
- **Issue** : la décision iter15-C-042 round-8 a basculé le fond en light gradient blanc → pêche → Cayenne, et a neutralisé l'overlay sombre — mais TOUTES les couleurs de texte hardcodées sont restées en `#FFF5E8` (off-white quasi blanc). Sur la moitié supérieure du gradient (~0-55%), le texte est **blanc-cassé sur blanc/pêche**. Le `text-shadow rgba(0,0,0,0.7)` produit un halo flou mais ne corrige pas le ratio (contraste ~1.1:1, WCAG AA minimum = 4.5:1). Le commentaire ligne 338-341 explique le raisonnement *au cas où il y a overlay sombre* — ce qui n'est plus vrai en light mode.
- **Evidence** : `<h2 class="kiosk-idle-title" style="color:#FFF5E8">Bienvenue !</h2>` rendu sur fond `#FFFFFF`. Le `welcomeTitle`, `welcomeSubtitle`, `tapHint`, brand `restaurantName` sont concernés.
- **Suggested fix** : remplacer `color: #FFF5E8` par `var(--kiosk-bold-text-primary, #0F0F0F)` sur `.kiosk-idle--bold`, `.kiosk-idle-brand`, `.kiosk-idle-title`. Retirer/atténuer `text-shadow` en light. Subtitle/tap-hint passent sur `var(--kiosk-bold-text-secondary, #5A5A5A)`. En dark mode (qui réactive l'overlay), un override `[data-kiosk-theme='dark']` peut restaurer le `#FFF5E8`. Cible AA strict + AAA via `[data-kiosk-contrast='aaa']`.

### P1 (V1.0.1 sprint)

#### K02-P1-01 : Hover order-type-card → vert `#2D6A4F` — hors palette owner
- **File** : `KioskIdleScreenComponent.vue:595-600`
- **Issue** : `.kiosk-order-type-card--takeaway:hover { background: #2D6A4F; ... box-shadow: ... rgba(45,106,79,0.45) }` introduit du **vert** alors que palette officielle = noir/rouge/jaune/blanc (memory `project_kiosk_design_refresh_2026-05-10.md`).
- **Suggested fix** : remplacer par `var(--kiosk-bold-primary, #F4501E)` (rouge Cayenne) ou `var(--kiosk-bold-text-primary, #0F0F0F)` (noir) selon hiérarchie.

#### K02-P1-02 : Hover card background `#E63946` — red legacy, pas Cayenne
- **File** : `KioskIdleScreenComponent.vue:577-584`
- **Issue** : hover hardcode `background: #E63946; box-shadow: ... rgba(230,57,70,0.45)`. La palette V2 owner gate 2026-05-10 = `#F4501E` (cayenne). `#E63946` est l'ancien red legacy explicitement réécrit par `tokens-bold.css:55` (`--kiosk-bold-primary: #F4501E`).
- **Suggested fix** : utiliser `var(--kiosk-bold-primary)` au lieu du hex inline.

#### K02-P1-03 : Card background warm cream `rgba(255,248,241,0.96)` — hors palette blanche
- **File** : `KioskIdleScreenComponent.vue:559-561`
- **Issue** : background card warm crème, owner mandate "background blanc". Ratio AA OK mais drift visuel : crème vs blanc pur.
- **Suggested fix** : `var(--kiosk-bold-surface, #FFFFFF)` + bordure neutre `var(--kiosk-bold-border, #E5E5E5)`.

#### K02-P1-04 : `restaurantLogo` rendu sans `<h1>` accessible
- **File** : `KioskIdleScreenComponent.vue:81-87`
- **Issue** : `v-if="restaurantLogo"` → `<img alt="">` ; `v-else` → `<h1>`. Quand le logo charge, la page n'a aucun `h1`. Hiérarchie de titres cassée pour screen reader (WCAG 1.3.1, 2.4.6).
- **Suggested fix** : conserver `<h1 class="visually-hidden">{{ restaurantName }}</h1>` (sr-only) + `<img alt="{{ restaurantName }}">` ; ou `aria-label` sur le `<h1>` parent du logo.

#### K02-P1-05 : `kiosk-idle-touch-btn` — sentinel mort dans `aria-hidden`
- **File** : `KioskIdleScreenComponent.vue:97-106`
- **Issue** : `.kiosk-idle-cta { aria-hidden="true" }` contient un `<div class="kiosk-idle-touch-btn" data-testid="kiosk-idle-touch-btn">` purement décoratif, **sans `@click`**. Pourtant 6+ specs Playwright (`iter15-mega-kiosk-roundtrip.spec.js:198,203`, `kiosk-happy-path.spec.js:350`, `test-e2e-rush-hour-50x50-2026-05-10-wave-B.spec.js:270`, `test-e2e-borne-2026-05-10-wave-C.spec.js:237`) appellent `getByTestId('kiosk-idle-touch-btn').click()` en fallback. Le clic n'a **aucun effet** (cursor:pointer hérité du root, mais aucun listener `start-order`). Les tests masquent silencieusement les régressions du chooser réel.
- **Suggested fix** : soit retirer `data-testid` du `<div>` (élément purement visuel) et nettoyer les specs ; soit câbler un `@click` réel sur le root et déclencher `selectOrderTypeAndStart(TAKEAWAY)` par défaut V1 (gate owner). **[OWNER GATE QUESTION]**.

#### K02-P1-06 : Selector de langue actif mais `changeLanguage()` no-op
- **File** : `KioskIdleScreenComponent.vue:263-271` + template ligne 23-34
- **Issue** : `changeLanguage()` documenté FR-lock ADR-007 → ne change RIEN. Mais le selector rend toujours FR/EN avec `aria-pressed` et `class:active`. Cliquer EN n'affiche aucun feedback visuel ni textuel. Trompeur pour l'utilisateur + accessibilité (ARIA pressed change détecté par screen reader sans réel changement).
- **Suggested fix** : soit cacher complètement le selector (`enabledLanguages.length` doit rester `1` en V1 via settings backend), soit afficher un toast "Langue fixée en FR (V1)", soit autoriser `setLocale` réel (revoit ADR-007).

#### K02-P1-07 : Aucun focus initial / pas d'annonce screen reader au mount
- **File** : `KioskIdleScreenComponent.vue:236-242` (mounted)
- **Issue** : aucun `ref().focus()` ni `aria-live` polite au mount. Screen reader landing → silence. La hiérarchie WCAG 2.4.3 (Focus Order) n'a pas d'amorçage explicite ; le focus tombe par défaut sur `<body>`.
- **Suggested fix** : focus programmatique sur la première card takeaway en `mounted()` (avec `tabindex="0"` déjà implicite). Ajouter `role="status" aria-live="polite"` sur `.kiosk-idle-headline` pour annoncer welcomeTitle au mount.

### P2 (backlog priorisé)

#### K02-P2-01 : Décor emojis flottants — contradit "flat et bien organisé"
- **File** : `KioskIdleScreenComponent.vue:71-75` + CSS `.kiosk-idle-decor*` (387-401)
- **Issue** : 3 emojis `🌮🍔🍟` (opacity 0.04-0.06, font-size jusqu'à 220px) en animation 18s `kiosk-decor-drift`. Memory `feedback_design_flat_organized.md` : owner refuse décor pictural superflu, préfère "flat/minimal".
- **Suggested fix** : retirer le bloc `.kiosk-idle-decor` complet, ou réduire à 1 emoji opacity 0.02 statique.

#### K02-P2-02 : Icon badge `#FFF3D6` warm cream
- **File** : `KioskIdleScreenComponent.vue:610` (`.kiosk-order-type-icon { background: #FFF3D6 }`)
- **Issue** : crème chaud (proche du jaune accent `#F5C518` mais désaturé) — drift par rapport au triptyque blanc/jaune.
- **Suggested fix** : `var(--kiosk-bold-accent-soft, #FFF7D6)` (token jaune accent soft) OU `var(--kiosk-bold-primary-soft, #FFE8DD)` (cohérent Cayenne).

#### K02-P2-03 : Fallback chain `#E63946` partout en CSS
- **Files** : `KioskIdleScreenComponent.vue:498,515,521,528-529` (5 occurrences `var(--kiosk-bold-primary, #E63946)`)
- **Issue** : la variable résout bien à `#F4501E` (cf. tokens-bold.css:55) mais la **valeur fallback hex est encore l'ancien red `#E63946`**. Si le token ne charge pas (FOUC, CSS purge bug, e2e snapshot), le red legacy fait surface.
- **Suggested fix** : mettre à jour les 5 fallbacks à `#F4501E`.

#### K02-P2-04 : `cursor: pointer` sur root sans listener réel
- **File** : `KioskIdleScreenComponent.vue:329`
- **Issue** : `.kiosk-idle--bold { cursor: pointer }` indique l'affordance "tap anywhere" mais aucun `@click` n'est attaché au root. Les seuls handlers sont sur les boutons. Cursor pointer sur zone non-clickable = anti-pattern UX.
- **Suggested fix** : retirer `cursor: pointer` du root et le déclarer uniquement sur boutons/cards. Ou implémenter réellement le "tap anywhere" (gate owner).

#### K02-P2-05 : Brand `text-shadow` épais en light
- **File** : `KioskIdleScreenComponent.vue:449,467`
- **Issue** : `text-shadow: 0 4px 24px rgba(0,0,0,0.7), 0 2px 8px rgba(0,0,0,0.5)` halo très lourd en light mode — anti-pattern flat design.
- **Suggested fix** : retirer text-shadow en light, conserver uniquement en dark.

### P3 (nice-to-have)

#### K02-P3-01 : `enabledLanguages` data hardcode `['fr','en']` au boot
- **File** : `KioskIdleScreenComponent.vue:195`
- **Issue** : default value avant `loadSettings()` rend deux boutons. Si settings backend renvoient `['fr']`, le selector apparaît brièvement puis disparaît (FOUC).
- **Suggested fix** : initialiser à `['fr']` (FR-lock V1 baseline) ; loadSettings écrasera si serveur en propose plus.

#### K02-P3-02 : `applyLocalizedDefaults()` puis `loadSettings` non-bloquant
- **File** : `KioskIdleScreenComponent.vue:237-238` (mounted)
- **Issue** : `applyLocalizedDefaults` synchrone, puis `loadSettings` async sans `await`. Si l'utilisateur tape un order-type avant la fin du fetch, `restaurantName` reste sur la valeur par défaut i18n.
- **Suggested fix** : ajouter un skeleton/spinner sur `.kiosk-idle-brand-block` pendant le fetch, ou `await this.loadSettings()` dans mounted async.

#### K02-P3-03 : Reset cart à chaque mount
- **File** : `KioskIdleScreenComponent.vue:241`
- **Issue** : `this.$store.dispatch('kioskCart/reset')` agressif. Si l'utilisateur revient par back-nav suite à un timeout, le panier est wipé sans confirmation.
- **Suggested fix** : cohérent avec V1 (kiosk = pas de panier persistant entre sessions). Documenter le comportement, pas urgent.

## Branding integrity — actual CSS var values

| Token | Valeur résolue (light) | Source |
|---|---|---|
| `--kiosk-bold-bg` | `#FFFFFF` | `tokens-bold.css:35` |
| `--kiosk-bold-text-primary` | `#0F0F0F` (noir) | `tokens-bold.css:42` |
| `--kiosk-bold-primary` | `#F4501E` (rouge Cayenne) | `tokens-bold.css:55` |
| `--kiosk-bold-accent` | `#F5C518` (jaune gold) | `tokens-bold.css:62` |
| `--kiosk-idle-bg` (light override) | `linear-gradient(180deg, #FFFFFF 0%, #FFE8DD 55%, #F4501E 100%) !important` | `tokens-bold.css:250` |
| `--kiosk-bold-text-on-primary` | `#FFFFFF` | `tokens-bold.css:46` |

**Verdict palette** : tokens corrects. Le drift vient des HEX hardcoded dans le SFC scoped CSS (`#FFF5E8`, `#E63946`, `#2D6A4F`, `#FFF3D6`) qui contournent la cascade.

## Wake-up trigger analysis

- Root `.kiosk-idle--bold` : `cursor: pointer` ligne 329, **aucun `@click`**.
- Boutons CTA réels (avec `@click`) :
  - `kiosk-idle-a11y-btn` (ligne 41) → openSettings (drawer, **pas** wake-up)
  - `kiosk-order-type-dine-in` (ligne 122-140, `v-if="dineInEnabled"`) → `selectOrderTypeAndStart(KIOSK)` → `$emit('start-order')`
  - `kiosk-order-type-takeaway` (ligne 141-157) → `selectOrderTypeAndStart(TAKEAWAY)` → `$emit('start-order')`
- `kiosk-idle-touch-btn` (ligne 101) : div décoratif aria-hidden, **AUCUN listener**. E2E specs l'utilisent en fallback : **clicks dans le vide** (cf. K02-P1-05).
- Conclusion : un seul vrai chemin wake-up = click sur une order-type-card. Pas de "tap anywhere" fonctionnel.

## i18n coverage

Tous les keys utilisés sont présents dans les 3 langues :

| Key | FR | EN | AR |
|---|---|---|---|
| `kiosk.idle_screen.default_restaurant_name` | "Notre restaurant" | "Our Restaurant" | "مطعمنا" |
| `kiosk.idle_screen.default_title` | "Bienvenue !" | "Welcome!" | "مرحباً!" |
| `kiosk.idle_screen.default_subtitle` | "Commandez en quelques touches" | OK | "اطلب ببضع لمسات فقط" |
| `kiosk.idle_screen.default_tap_hint` | "Touchez l'écran pour commander" | OK | "المس الشاشة لبدء الطلب" |
| `kiosk.order_type.takeaway` / `dine_in` | "À emporter" / "Sur place" | OK | "طلب خارجي" / "تناول في المطعم" |
| `kiosk.choose_language` | "Choisissez votre langue" | OK | "اختر لغتك" |
| `kiosk.a11y.open` | "Paramètres d'accessibilité" | OK | présent |

**Pas de raw label fuyant** détecté.

## Performance

- 3 `<span>` emojis avec animations CSS infinies (`kiosk-decor-drift`, `kiosk-float`, `kiosk-pulse-ring`, `kiosk-btn-pulse`, `kiosk-slide-up`, `kiosk-slide-down`) : 6+ animations actives en permanence. Reduced-motion neutralise correctement (lignes 679-698, 814-817).
- Vidéo de fond `autoplay loop muted playsinline` (ligne 54-63) avec `.play().catch(() => {})` defensive. OK.
- `backdrop-filter: blur(8px-12px)` sur 3 éléments (cards, lang-selector, a11y-btn) : coûteux sur kiosk hardware bas de gamme. **À surveiller**.

## A11y résumé

| Item | Status |
|---|---|
| Contraste texte titre | **FAIL** (off-white sur blanc, P0-01) |
| Hiérarchie h1/h2 | FAIL si logo (P1-04) |
| First focus / announce | MISSING (P1-07) |
| `cursor:pointer` sans listener | MISLEADING (P2-04) |
| Touch target min ≥48px | OK (`--kiosk-touch-min: 48px` consommé) |
| Reduced motion | OK (système + manuel) |
| RTL `[dir="rtl"]` | OK (floating ligne 730-733) |
| Lang selector ARIA | Trompeur (P1-06 : pressed change sans effet) |

## Existing E2E coverage

- `tests/js/kioskOrderTypeExplicit.spec.js` — Vitest unit, click `kiosk-order-type-takeaway` (couvre selectOrderTypeAndStart).
- `tests/Playwright/kiosk-order-type-required.spec.js` — vérifie présence `data-testid="kiosk-order-type-takeaway"` dans source.
- `tests/e2e/kiosk-happy-path.spec.js:40,59,109,350` — flow end-to-end via takeaway tile.
- `tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js:490` — vérifie visibilité `kiosk-idle-root`.
- `tests/e2e/iter15-mega-kiosk-roundtrip.spec.js:188-203` — chooser takeaway + fallback `kiosk-idle-touch-btn` (cf. P1-05).
- `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-B.spec.js:263,544,962` — burger journey via takeaway.
- `tests/e2e/test-e2e-borne-2026-05-10-wave-C.spec.js:230,237` — wave C flow.
- `tests/e2e/red-team-r2-fixes-validation-2026-05-07.spec.js:21` — red-team flow.
- `tests/e2e/mega-parcours-e2e-2026-05-08.spec.js:831-1170` — mega parcours multi-order.
- `tests/e2e/test-e2e-visual-e2e-2026-05-11-wave-C-edge.spec.js:131,539` — visual edge cases.
- `tests/js/kioskIdleWarningEvent.spec.js` + `kioskSettingsIdleTimeouts.spec.js` — timeout/inactivity (composables, pas screen idle directement).

**Gap** : aucun test ne valide
- Contraste visuel du titre (white-on-white).
- Palette hex respectée (pas de `#E63946`/`#2D6A4F`).
- Présence d'un `<h1>` quand le logo charge.
- Annonce screen reader au mount.

## Proposed new E2E tests

### T-K02-01 : Visual contrast — title not invisible on light gradient
- **Steps** (Playwright) :
  ```js
  await page.goto('/kiosk/idle');
  await expect(page.getByTestId('kiosk-idle-title')).toBeVisible();
  const { textColor, bgColor } = await page.evaluate(() => {
    const el = document.querySelector('[data-testid="kiosk-idle-title"]');
    return { textColor: getComputedStyle(el).color, bgColor: getComputedStyle(el.closest('.kiosk-idle--bold')).backgroundColor };
  });
  // axe-core color-contrast on h2
  const results = await new AxeBuilder({ page }).include('[data-testid="kiosk-idle-title"]').withTags(['wcag2aa']).analyze();
  expect(results.violations.filter(v => v.id === 'color-contrast')).toHaveLength(0);
  ```
- **Assertions** : ratio ≥ 4.5:1, axe color-contrast=0.

### T-K02-02 : Palette compliance — no green, no legacy red
- **Steps** (Playwright) :
  ```js
  await page.goto('/kiosk/idle');
  await page.getByTestId('kiosk-order-type-takeaway').hover();
  const bg = await page.getByTestId('kiosk-order-type-takeaway').evaluate(el => getComputedStyle(el).backgroundColor);
  expect(bg).not.toBe('rgb(45, 106, 79)');   // #2D6A4F green
  expect(bg).not.toBe('rgb(230, 57, 70)');   // #E63946 legacy red
  expect(['rgb(244, 80, 30)', 'rgb(15, 15, 15)']).toContain(bg); // Cayenne or black
  ```

### T-K02-03 : Dead sentinel — kiosk-idle-touch-btn must NOT advance flow
- **Steps** :
  ```js
  await page.goto('/kiosk/idle');
  await page.getByTestId('kiosk-idle-touch-btn').click({ force: true });
  await page.waitForTimeout(500);
  await expect(page.getByTestId('kiosk-idle-root')).toBeVisible(); // still on idle
  await expect(page.getByTestId('kiosk-categories-root')).toHaveCount(0);
  ```
- **Goal** : verrouille le statut "décoratif" et bloque toute spec qui s'appuierait dessus comme CTA.

### T-K02-04 : H1 presence when logo loaded
- **Steps** : mock backend to return `logo_full_path`, navigate, assert `page.locator('h1').count() >= 1`. (PR fix attendu pour P1-04.)

### T-K02-05 : First focus + aria-live announce on mount
- **Steps** :
  ```js
  await page.goto('/kiosk/idle');
  const focused = await page.evaluate(() => document.activeElement?.dataset?.testid);
  expect(['kiosk-order-type-takeaway', 'kiosk-order-type-dine-in']).toContain(focused);
  await expect(page.locator('[role="status"][aria-live="polite"]')).toBeVisible();
  ```

## Risks & open questions

1. **[OWNER GATE]** Sentinel mort `kiosk-idle-touch-btn` (P1-05) : 6+ specs E2E s'en servent en fallback. Deux options :
   - (a) Retirer `data-testid` + nettoyer les 6 specs (PR coordonnée).
   - (b) Câbler `@click` réel sur le CTA = wake-up → `selectOrderTypeAndStart(TAKEAWAY)` (default V1 puisque dine-in flag-OFF).
   Recommandation perso : **(b)** — match les attentes E2E historiques et restaure le "tap anywhere" affordance promis par `cursor:pointer`.

2. **[OWNER GATE]** FR-lock + selector langue visible (P1-06) : si V1 reste FR-lock strict (ADR-007), masquer le selector entièrement plutôt que de le rendre cliquable sans effet. Sinon revoir ADR-007 pour autoriser FR/EN runtime switch (impact i18n init multiple).

3. **[OWNER GATE]** Décor emojis (P2-01) : style flat owner mandate vs décor warm appétissant du plan V2.B. Le plan original demandait du décor — owner a depuis pivoté flat (memory `feedback_design_flat_organized.md`). Trancher.

4. **Frozen-zone interaction** : `KioskAppComponent.vue` (FROZEN) définit `--kiosk-idle-bg` lignes 1099 et 1175. Le `tokens-bold.css:250` l'override en light avec `!important`. Toute modification du fallback doit rester dans `tokens-bold.css` (non-frozen), pas dans le composant `App` frozen.

5. **Performance** : `backdrop-filter: blur(8-12px)` sur 3 éléments + 6 animations infinies + 1 vidéo loop. Profiler kiosk hardware réel (Android tablet bas de gamme) — risque jank.
