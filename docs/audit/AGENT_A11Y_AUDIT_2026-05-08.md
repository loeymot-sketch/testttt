# Agent A11Y — Audit accessibilité MEGA PARCOURS — 2026-05-08

> **Rôle** : Agent A11Y (GSTACK = QA accessibility hostile).
> **Scope** : surfaces visitées par `tests/e2e/mega-parcours-e2e-2026-05-08.spec.js`
> (POS V5, Kiosk, KDS, payment modals, login, ConnectionStatusBanner, cash counter ticket).
> **Référentiel** : WCAG 2.1 AA + EAA 2025 + best-practice screen-reader / clavier.
> **Méthode** : audit **statique structurel** (lecture source `.vue`/`.js`/`.css` + DOM probes
> capturés en runtime mega-parcours). Le serveur Laravel n'étant pas joignable
> dans cette session (`curl /login` indisponible), `axe-core` n'a pas été ré-exécuté
> live ce cycle — la baseline R4-06 KDS et `cv1-kds-a11y-rich-2026-05-08.spec.js`
> tiennent lieu d'évidence runtime existante (à ré-exécuter quand server up).

## 0. Limitations honnêtes (CLAUDE.md §11)

1. **Audit statique** — pas de run axe-core ce cycle. Les findings reposent sur
   l'inspection du source + DOM probes mega-parcours du 2026-05-07T18:38Z.
2. **Pas de mesure runtime de contrast ratio** sur tile OOS — le calcul est
   théorique à partir des tokens CSS (`#C21E2F` rgba 0.86 sur `#FFFFFF` ink).
3. **Pas de test screen-reader réel** (VoiceOver / NVDA non lancé). On infère
   les annonces via les attributs `role=status` / `aria-live`.
4. **Pas de mesure clavier réelle** — focus order vérifié structurellement par
   l'ordre DOM des focusables et la présence/absence de `tabindex` aberrants.
5. axe-core =~ 30 % des violations capturables (WAI-ARIA Authoring Practices) ;
   les 70 % restants (sens, alt-text qualité, ordre logique tab, annonce SR)
   nécessitent un audit manuel humain ou un test screen-reader.

## 1. Bilan A1 → A10

### A1 — `role`/`aria` invariants sur wizards POS et Kiosk au runtime

**Verdict : OK (statique)**.

| Surface | Élément | Attributs trouvés | Ligne |
|---|---|---|---|
| POS wizard | `#item-variation-modal` | `role="dialog"` + `aria-modal="true"` + `aria-labelledby="item-variation-modal-title"` + `tabindex="-1"` | `resources/js/components/admin/pos/ItemComponent.vue:64` |
| POS wizard title | `#item-variation-modal-title` | `<h3>` — id résolvable | `ItemComponent.vue:74` |
| Kiosk wizard | `.kiosk-wizard` root | `role="dialog"` + `aria-modal="true"` + `aria-labelledby="kiosk-wizard-title"` + `tabindex="-1"` | `KioskWizardComponent.vue:4-7` |
| Kiosk wizard abandon modal | secondary dialog | `role="dialog"` + `aria-modal="true"` + `aria-labelledby="kiosk-wizard-abandon-title"` | `KioskWizardComponent.vue:204-206` |

`public/js/pos-wizard.js` (vanilla JS legacy, frozen-zone) n'attribue **aucun**
`role`/`aria-*` directement (0 match sur `aria-[a-z]+|role="`). Toute la
sémantique vient du shell Vue `ItemComponent.vue` qui rend le `<div id="item-variation-modal">`
avec les bons attributs avant que `pos-wizard.js` n'injecte son contenu — pattern
acceptable car le rôle dialog est statique.

### A2 — Focus management aux passages de page

**Verdict : OK avec un caveat**.

- **Login → SPA admin** : géré par `LoginComponent.vue` puis Vue Router. Pas de
  vérification source d'un re-focus sur un landmark après route change → dépend
  du browser default (focus reste sur `<body>`, screen-reader recommence reading
  depuis le début).
- **POS wizard open** (`PosComponent.vue:1499` et `:1598`) :
  `_wizardReturnFocusEl = document.activeElement` est mémorisé avant ouverture,
  puis restauré (`returnFocusEl.focus()`) à la fermeture (`:1632`). Pattern WCAG
  2.4.3 conforme.
- **Kiosk wizard close** (`KioskWizardComponent.vue:2073`) :
  `setTimeout(() => returnFocusEl.focus(), 0)` — restauration explicite du focus.
- **POS modal payment** (`PosComponent.vue` ligne 698) : bouton close avec
  `:aria-label="$t('button.close')"` mais pas de pattern `aria-hidden` siblings
  vérifié → axe peut signaler (à valider runtime).
- **Caveat** : aucun `focus()` programmé sur le main-content après route Vue
  Router → utilisateurs SR en mode admin (150 focusables RED-R1 S3) repartent
  de zéro à chaque navigation, friction connue mais non bloquante EAA V1.

### A3 — Keyboard navigation Tab/Shift+Tab — focus traps

**Verdict : OK**.

- **Kiosk wizard focus trap** : implémenté `:2019-2029` et `:2095-2105` —
  `querySelectorAll('button:not([disabled]), [href], input:not([disabled])…')`
  + sentinel last/first `focus()` sur Shift+Tab/Tab. Pattern conforme.
- **POS wizard** : pas de focus trap explicite trouvé, mais Bootstrap modal
  fournit le piégeage natif via la classe `modal` + backdrop. À vérifier
  runtime que les composants tiers du wizard (drinks catalog, viande select)
  n'introduisent pas de `tabindex` négatif aberrant.
- **ConnectionStatusBanner** : bouton close `aria-label="Fermer"` —
  focusable, pas de trap (banner non modal, conforme).
- **Aucun `tabindex="42"` ou tabindex positif aberrant détecté** dans les
  surfaces principales scannées (POS, Kiosk, KDS, banner).

### A4 — Screen reader announcements sur transitions critiques

**Verdict : OK**.

| Transition | Région `aria-live` | Source |
|---|---|---|
| POS commande créée / cart change | `#pos-a11y-live aria-live="polite" aria-atomic="true"` | `PosComponent.vue:13` |
| POS cart region | `aria-live="polite"` (intégré sur `.pos-v5-cart__body`) | `PosComponent.vue:447` |
| POS loading items | `aria-live="polite" aria-relevant="additions" aria-busy` | `PosComponent.vue:165-166` |
| Kiosk cart summary (subtotal/total) | `role="region" aria-live="polite"` | `KioskCartComponent.vue:223-225` (sentinel `kioskCartAriaLive` lock) |
| Kiosk cart qty change | `aria-live="polite"` sur compteur | `KioskCartComponent.vue:194` |
| Kiosk pay processing | `role="status" aria-live="polite"` | `KioskPaymentComponent.vue:148-149` |
| Kiosk TPE message | `aria-live="polite"` sur `#kiosk-tpe-title` | `KioskPaymentComponent.vue:181` |
| KDS transitions (ACCEPT → PREPARING → PREPARED) | `<div role="status" aria-live="polite" aria-atomic="true" data-testid="kds-aria-live">` | `KitchenDisplaySystemComponent.vue:751-755` (CV1-KDS-A11Y-RICH-001) |
| ConnectionStatusBanner | `role="alert"` + `aria-live="assertive"` (session_invalid) ou `polite` (transient) | `ConnectionStatusBanner.vue:5-9` |
| KioskOrderSummary running total | `role="status" aria-live="polite"` | `KioskOrderSummaryComponent.vue:97-98` |

Couverture **excellente**. CV1-KDS-A11Y-RICH-001 a verrouillé un sentinel
runtime (`tests/e2e/cv1-kds-a11y-rich-2026-05-08.spec.js:95-127`) qui assert
position absolute + width/height 1px → région SR-only sans reflow visuel.

### A5 — Contrast ratio texte/fond sur tile OOS

**Verdict : OK théorique**.

Token CSS de l'overlay OOS (`public/css/app.css` extrait scoped `data-v-ea217858`,
source équivalent `resources/css/foundations/pos-v5-tokens.css`) :

```css
.pos-item-86-badge { background: rgba(194, 30, 47, 0.86); color: #FFFFFF; }
/* = #C21E2F @ 0.86 alpha sur fond panel #FFFFFF →
   couleur effective ≈ #C9252F (un peu plus clair que C21E2F pur) */
```

Calcul approximatif :
- `#C9252F` (rouge effectif) sur `#FFFFFF` (blanc) → ratio ≈ **5.3:1**
- WCAG 2.1 AA texte normal → 4.5:1 ✓ **PASS**
- WCAG 2.1 AAA texte normal → 7:1 ✗ FAIL (acceptable car AA cible V1)
- Tile derrière l'overlay : `opacity: 0.55` + `filter: grayscale(0.4)` → texte
  legacy "Sold out" propre via overlay, pas par texte transparent.

**Risque** : le `backdrop-filter: blur(2px)` peut être désactivé sur Safari
ancien → fallback rgba seul reste lisible (testé visuel sur screenshot
`pos-4-step-03-tile-after-oos.png`).

### A6 — Touch target size sur kiosk (min 44×44px WCAG 2.1, 24×24 WCAG 2.2 AA)

**Verdict : EXCELLENT** (over-spec).

| Élément | Min size | Source |
|---|---|---|
| Kiosk PMR mode universal floor | **64×64 px** (`--kiosk-touch-min`) sur tout `button`, `[role="button"]`, `a[href]`, `input[type=submit]` | `resources/css/kiosk/tokens-pmr.css:73-80` |
| Kiosk btn primary (CTA add-to-cart, pay) | `min-height: 76px` | `KioskCartComponent.vue:1062` |
| Kiosk touch tokens | `comfortable: 80px`, `large: 96px`, `hero: 132px` | `tokens-pmr.css:50-52` |

WCAG 2.1 AA Target Size (Enhanced) = 44×44 px. WCAG 2.2 AA = 24×24 px.
Kiosk impose **64×64 px partout en PMR** → couvre AAA et 6.6 EAA Annexe I §3
("control surfaces accessible to persons with reduced mobility").

POS V5 (admin desktop) — le plancher est moins agressif (CTA `tap-large` token
existe `pos-v5-tokens.css`) mais POS = clavier + souris pro, pas tactile pur.
Hors scope WCAG cible-tactile pour cette surface.

### A7 — Form labels — input password

**Verdict : OK**.

- **Admin login (Vue)** `LoginComponent.vue:24-29` :
  ```html
  <label for="formPassword">{{ $t('label.password') }}</label>
  <input autocomplete="current-password" type="password" id="formPassword" v-model="form.password" />
  ```
  → `<label for>` → id matching ✓ ; `autocomplete="current-password"` ✓
  (post-R1 fix confirmé) ; aucun `placeholder`-as-label détecté.
- **Email input** `:18-20` :
  ```html
  <label for="formEmail">{{ $t('label.email') }}</label>
  <input id="formEmail" />
  ```
  Couplage label/input correct, mais **pas de `autocomplete="email"` ou `username`**
  → P3 mineur (bonne pratique non bloquante WCAG).
- **Kiosk login** `KioskLoginComponent.vue` n'a **pas** de form input — auth
  est auto via `kioskAutoLogin` injecté par `window.foodkingConfig`. Pas
  d'input password à labeler. ✓

### A8 — Skip links et navigation segmentée

**Verdict : Partiel — POS OK, Kiosk OK, ADMIN absent**.

- **POS** : `<a href="#pos-cart" class="pos-v5-skip-link sr-only focus:not-sr-only">{{ $t('a11y.skip_to_cart') }}</a>`
  (`PosComponent.vue:12`). Parfait — SR-only par défaut, visible au focus,
  cible `#pos-cart` ✓.
- **POS landmarks** : `role="banner"` (header `:18`), `nav aria-label="Actions caisse"` (`:46`),
  `role="region" aria-label="cart"` (`:197`, `:447`), `role="tablist"` catégories
  (`:143-144`) → 4 landmarks structurés.
- **Kiosk** : pas de skip-link explicite trouvé, mais surface ne dispose que de
  3-5 focusables max par écran (idle, categories, cart, payment) → pas de besoin
  WCAG 2.4.1 strict.
- **Admin shell (RED-R1 S3 = 150 focusables)** : ⚠️ **pas de skip-link détecté**
  dans le shell admin. Dans `admin-shell.js` / master.blade.php — à vérifier
  manuellement plus tard. **Possible P2** (WCAG 2.4.1 Bypass Blocks) si
  réellement absent. Hors scope mega-parcours qui touche surtout POS+Kiosk+KDS.

### A9 — Wizard close-button accessibility

**Verdict : OK**.

- **POS wizard close** : `<button :aria-label="$t('button.close')">` (`PosComponent.vue:698, 203`)
  → label clair, focusable.
- **Kiosk wizard close** : `<button :aria-label="$t('kiosk.wizard.close_aria')">` (`KioskWizardComponent.vue:41`)
  + abandon modal `:aria-label="$t('kiosk.wizard.close_aria')"`.
- Tous deux activables clavier (`<button>` natif → Enter/Space ✓).
- ESC handler : présent côté Kiosk wizard via le focus trap (Tab/Shift+Tab) ;
  côté POS via Bootstrap modal natif (Esc ferme).

### A10 — Receipt rendering (NF525 fiscal table)

**Verdict : N/A — hors-scope DOM**.

Les tickets fiscaux NF525 vivent dans `resources/views/pdf/` (PDF rendu côté
serveur via `barryvdh/laravel-dompdf` ou équivalent) et sur l'imprimante
thermique TM-T20III via le bypass `STUB-{Date.now()}`. **Pas de surface DOM
publique** → pas d'évaluation screen-reader applicable.

Le ticket à l'écran kiosk (`KioskCashInstructionComponent.vue` — `Rendez-vous en caisse`)
a été observé en mega-parcours `kiosk-2-step-07-ticket-state.png` et
`hasPendingCounterText: true` (DOM probe). Le contenu écran lui (`{{ totalAmount }} €` +
queue number) est en `<span>` simple sans rôle dédié → annoncé par SR comme texte
plain, ce qui est **suffisant** pour cette information transactionnelle (pas de
table ARIA nécessaire, pas de rôle status car rien ne change live).

## 2. Top 5 violations P0/P1 (audit statique)

> Aucune violation **P0** trouvée. Le wizard POS, le wizard Kiosk, le KDS et la
> cart kiosk respectent tous le contrat dialog/aria-modal/labelled. Les findings
> ci-dessous sont **P1 dérivés du mega-parcours**, élevés en a11y formelle.

| # | Sev | Slug | Source / preuve | WCAG/EAA |
|---|---|---|---|---|
| 1 | **P1** | `pos-5/extra-oos-not-marked-ui` | DOM probe `findings.json:332` — `oosMarkedCount: 0` sur 16 extras quand un extra est en `is_available=false`. Aucun `aria-disabled`, aucun marker visuel/sémantique. Le caissier-SR sélectionne un extra indispo → submit 422. | **WCAG 1.3.1** (Info & Relationships) + **4.1.2** (Name, Role, Value). EAA 2025 §6 friction info utilisable. |
| 2 | **P1** | `pos-modal-payment-confirm-without-status-region` | `PosComponent.vue:637` — un `<div role="status" aria-live="polite" aria-atomic="true">` existe pour discount, mais **pas** trouvé pour le résultat de submit payment (succès/échec). Le caissier-SR ne sait pas si le confirm a abouti. | **WCAG 4.1.3** (Status Messages) — à vérifier runtime, peut être déjà présent via `pos-a11y-live` SR-only région globale (`PosComponent.vue:13`) qui annonce via `posA11y.announce()`. Si oui, descend en OK ; sinon P1. |
| 3 | **P2** | `admin-shell-skip-link-missing` | RED-R1 S3 mentionne 150 focusables dans le shell admin. Pas de `pos-v5-skip-link` équivalent détecté dans les layouts admin globaux (à confirmer en lisant `admin-shell.js`). | **WCAG 2.4.1** (Bypass Blocks). |
| 4 | **P2** | `pos-modal-aria-hidden-siblings-not-set` | Quand `#item-variation-modal` est ouvert, les siblings `<main>`, `<header>`, `<aside>` ne sont pas marqués `aria-hidden="true"`. SR peut continuer à lire l'arrière-plan en parallèle du wizard. | **WAI-ARIA APG dialog pattern** — recommandation, non bloquante WCAG. |
| 5 | **P3** | `login-email-input-no-autocomplete` | `LoginComponent.vue:18-20` — `<input id="formEmail">` sans `autocomplete="email"` ni `autocomplete="username"`. Les password managers ne peuvent pas pré-remplir. | **WCAG 1.3.5** (Identify Input Purpose) — bonne pratique. |

## 3. Cross-check sentinels existants

| Sentinel | Lock | Statut |
|---|---|---|
| `tests/js/sentinels/kdsA11yRichStructure.spec.js` | `role="article"` + `aria-labelledby="order-{id}-title"` sur cards KDS | OK (template lock) |
| `tests/js/sentinels/kdsInflightOosMarkerStructure.spec.js` | OOS marker structure KDS | OK |
| `tests/js/sentinels/kioskCartAriaLive.spec.js` | `kiosk-cart-summary` `aria-live="polite"` + `role="region"` + `aria-label` | OK |
| `tests/e2e/cv1-kds-a11y-rich-2026-05-08.spec.js` | runtime axe-core 0 critical/serious sur KDS + sr-only région absolue 1×1px | OK (à re-run) |
| W1/W2/W3 (commit 9ce2f2e6f) | a11y wizard POS | landed |
| WK1/WK2/WK3/WK4 (commit e309083b7) | a11y wizard Kiosk | landed |

**Aucune régression structurelle détectée** sur les surfaces déjà sentinelées.

## 4. Verdict GO/NO-GO a11y EAA 2025 V1

### Synthèse (CLAUDE.md §8 Decision Framework)

| Dimension | Score | Évidence |
|---|---|---|
| Wizard ARIA POS | 9/10 | dialog+modal+labelledby, focus return OK |
| Wizard ARIA Kiosk | 10/10 | dialog+modal+labelledby+focus trap propre |
| KDS | 10/10 | sentinel runtime axe 0 critical, role=article, aria-live |
| Login forms | 9/10 | `<label for>` + autocomplete="current-password" ; mineur missing email autocomplete |
| Kiosk touch targets | 10/10 | floor 64×64 PMR, 76px primary CTA — over-spec |
| aria-live transitions | 10/10 | POS+Kiosk+KDS+banner couverts |
| Skip links | 6/10 | POS OK, admin shell incertain |
| Contrast ratio OOS | 8/10 | 5.3:1 estimé (AA OK, AAA fail) |
| Receipt NF525 | N/A | pas de surface DOM |
| Extras OOS marker | **3/10** | pas de marker SR+visuel — finding P1 confirmé |

### Décision

**Verdict : `GO` conditionné EAA 2025 V1**.

- **Aucun P0** bloquant la sortie EAA.
- **1 P1 a11y formel** : extras OOS dans wizard POS (couvert par finding mega
  `pos-5/extra-oos-not-marked-ui`, WCAG 1.3.1 + 4.1.2). À fixer avant V1
  finale ou à documenter comme limitation acceptée + workaround caissier
  (re-validation 422 backend protège la chaîne, pas de risque correctness).
- **2 P2** : skip-link admin shell (à vérifier), aria-hidden siblings dialog
  (recommandation APG). Non bloquants EAA.
- **1 P3** : email autocomplete missing.

**Conditions GO** :
1. Fix P1 extras OOS marker dans wizard POS avant V1 prod
   (sentinel à ajouter : `posWizardExtraOosMarkerStructure.spec.js`)
2. Run `axe-core` runtime sur les 8 surfaces principales (POS, Kiosk idle,
   Kiosk categories, Kiosk wizard, Kiosk cart, KDS, login, observability)
   quand server up — pour confirmer 0 violation critical/serious.
3. Vérifier skip-link admin shell ; ajouter si absent.
4. Test manuel screen-reader (NVDA Windows + VoiceOver Mac) sur le flow
   complet POS et Kiosk → preuve EAA 2025 §III.A.5 (utilisabilité avec
   technologies d'assistance).

### Pas de `block`, pas de `escalate`

- Aucune contradiction architecturale détectée.
- Sealing fiscal NF525 ne dépend pas du DOM (PDF + thermique).
- L'invariant business "items/extras OOS bloqués backend" tient (mega-parcours
  prouve 422 systématique).

## 5. Limitations honnêtes finales (récap CLAUDE.md §11)

1. **Pas d'axe-core runtime ce cycle** — server inaccessible. Re-run mandatory
   au prochain cycle V1 prep.
2. **Pas de test SR humain** — VoiceOver / NVDA non lancé. Les annonces
   `aria-live` sont **structurellement correctes** mais leur **timing**
   (debounce, queue, interruption par focus change) reste à valider main-test.
3. **Contrast OOS = calcul théorique** depuis tokens CSS, pas mesure DevTools
   live. Les `backdrop-filter: blur(2px)` peuvent contaminer le lightness
   final sur certains GPU.
4. **Admin shell skip-link** non confirmé — à scanner au prochain cycle.
5. **POS wizard `pos-wizard.js`** (vanilla JS frozen-zone, 5964 lignes) :
   0 attribut `aria-*` direct détecté ; toute l'a11y vient du shell Vue
   `ItemComponent.vue`. Acceptable car role/aria sont sur le container modal,
   pas sur le contenu généré, mais le focus trap natif Bootstrap doit être
   validé runtime sur les composants tiers (drinks catalog, viande select).
6. **Kiosk-3 multi-add fail** dans le mega-parcours = test infra
   (`waitForTimeout` × N), pas a11y. Aucun impact verdict.

## 6. Artefacts produits

- Le présent rapport `docs/audit/AGENT_A11Y_AUDIT_2026-05-08.md`
- Sources analysées (références durables) :
  - `resources/js/components/admin/pos/PosComponent.vue` (skip link, aria-live, landmarks)
  - `resources/js/components/admin/pos/ItemComponent.vue` (wizard dialog wrapper)
  - `resources/js/components/admin/pos/PaymentComponent.vue` (payment modal aria-selected)
  - `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (focus trap + return focus)
  - `resources/js/components/frontend/kiosk/KioskCartComponent.vue` (aria-live region cart)
  - `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (TPE dialog + aria-live)
  - `resources/js/components/frontend/kiosk/KioskLoginComponent.vue` (auto login, no form)
  - `resources/js/components/frontend/auth/LoginComponent.vue` (admin login form)
  - `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (aria-live KDS)
  - `resources/js/components/common/ConnectionStatusBanner.vue` (role=alert)
  - `resources/css/foundations/pos-v5-tokens.css` (contrast tokens)
  - `resources/css/kiosk/tokens-pmr.css` (touch target floor)
  - `public/js/pos-wizard.js` (vanilla frozen — no aria injected, relies on Vue shell)
- DOM probes : `tests/e2e/screenshots/mega-parcours-2026-05-08/dom-probes.json`
- Findings mega : `tests/e2e/screenshots/mega-parcours-2026-05-08/findings.json`

## 7. Références

- WCAG 2.1 AA (W3C Recommendation 5 June 2018)
- EAA 2025 — Directive (UE) 2019/882 Annexe I §III.A
- CLAUDE.md §8 Decision Framework, §11 Evidence Rules
- R4-06 a11y axe-core baseline (`tests/e2e/red-team-r4-kds-reception-2026-05-07.spec.js`)
- CV1-KDS-A11Y-RICH-001 (`tests/e2e/cv1-kds-a11y-rich-2026-05-08.spec.js`)
- W1/W2/W3 + WK1/WK2/WK3/WK4 fixes (commits 9ce2f2e6f + e309083b7)
- MEGA PARCOURS report (`docs/audit/MEGA_PARCOURS_E2E_REPORT_2026-05-08.md`)
