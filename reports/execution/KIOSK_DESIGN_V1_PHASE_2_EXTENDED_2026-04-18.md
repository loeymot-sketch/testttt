# KIOSK DESIGN V1 — Phase 2 Extended — 2026-04-18

## Scope

Follow-up à `KIOSK_DESIGN_V1_PHASE_2_3_2026-04-18.md`. Finalise la vague haute
priorité de restyle des composants Vue 3 kiosk (P2) en ajoutant 3 composants
critiques (Payment, Upsell, OrderSummary) à la suite du trio déjà restylé
(Categories, ProductList, Cart).

## Livrables cette itération

| Priorité | Composant | data-testid | A11y | Tokens CSS | Spec Vitest |
|---|---|---|---|---|---|
| P3 | `KioskCartComponent.vue` | ✅ 25+ testids | ✅ radiogroup, dialog, listitem, live | ✅ 0 hex | `KioskCartRestyle.spec.js` (6/6) |
| P4 | `KioskPaymentComponent.vue` | ✅ 11 testids | ✅ radiogroup, aria-checked, dialog TPE | ✅ tokens brand + gradients sémantiques conservés | `KioskPaymentRestyle.spec.js` (6/6) |
| P5 | `KioskUpsellComponent.vue` | ✅ 10 testids | ✅ role=list/listitem, aria-pressed, progressbar | ✅ 0 hex hors gradients | `KioskUpsellOrderSummaryRestyle.spec.js` (3/6) |
| P5 | `KioskOrderSummaryComponent.vue` | ✅ 9 testids | ✅ role=group qty, aria-live total/qty | ✅ 0 hex | `KioskUpsellOrderSummaryRestyle.spec.js` (3/6) |

## Détails par composant

### P4 — `KioskPaymentComponent.vue`

- **Template**
  - `data-testid="kiosk-payment-*"` sur root, back, title, total, 3 méthodes
    (`method-card` / `method-cash` / `method-tr`), confirm, error, processing,
    tpe-overlay, tpe-cancel.
  - 3 cartes de paiement transformées en `role="radio"` dans un
    `role="radiogroup"` (aria-checked réactif à `method`). Activation clavier
    Space/Enter ajoutée.
  - Overlay TPE : `role="dialog"`, `aria-modal="true"`,
    `aria-labelledby="kiosk-tpe-title"`, `aria-live="polite"` sur le message.
  - Processing screen : `role="status"`, `aria-live="polite"`.
  - Error : `role="alert"`.
  - SVG décoratifs : `aria-hidden="true"` ajouté.
- **CSS (21 remplacements)** : `--kiosk-bg` / `--kiosk-surface` /
  `--kiosk-border` / `--kiosk-text-muted` / `--kiosk-primary-soft` /
  `--kiosk-shadow-{card,lift,cta}` / `--kiosk-text-on-red` /
  `--kiosk-focus-ring`.
  Les 3 gradients **payment-method-icon.{card,cash,tr}** ont été conservés :
  ils encodent la sémantique internationale (bleu CB, vert cash, orange TR) et
  se branchent sur les tokens brand lorsque disponibles (`--kiosk-info`,
  `--kiosk-success`).
  Overlay TPE conservé volontairement en fond sombre (focus haptique CB).
- **Focus WCAG 2.4.7** : `.kiosk-pay-method:focus-visible { outline: 3px solid
  var(--kiosk-focus-ring); }` ajouté.
- **Spec** : `tests/js/KioskPaymentRestyle.spec.js` → 6 tests (testids,
  a11y, keyboard, disabled confirm, error role, TPE dialog).

### P5 — `KioskUpsellComponent.vue`

- **Template**
  - `data-testid="kiosk-upsell-*"` sur root, loading, title, grid, card-`<id>`,
    card-name-`<id>`, card-price-`<id>`, add-continue, skip, autoskip-bar.
  - Grid : `role="list"`. Cards : `role="listitem"`, `tabindex="0"`,
    `aria-pressed`, `aria-label` prix + nom sanitized. Activation Space/Enter.
  - Barre auto-skip : `role="progressbar"`, `aria-valuenow/min/max`,
    `aria-label` avec décompte.
  - Loading : `role="status"`, `aria-live="polite"`.
  - SVG checkmark et icônes +/− : `aria-hidden="true"` (info dupliquée via
    `aria-pressed` sur la carte).
- **CSS (31 remplacements)** : toutes les couleurs brand (`#d7263d`, `#fff`,
  `#e8e8e8`, `#ececec`, `#f7f7f8`…) remplacées par `--kiosk-*` tokens. Shadows
  carte / CTA → `--kiosk-shadow-card` / `--kiosk-shadow-cta`.
- **Focus WCAG 2.4.7** : `.kiosk-upsell-card:focus-visible` ajouté.
- **Invariants respectés** : aucune statistique dynamique — cartes proposent
  uniquement ce que l'endpoint `/api/frontend/upsell` renvoie (server-driven,
  `upsell_rules` table Phase 1).

### P5 — `KioskOrderSummaryComponent.vue`

- **Template**
  - `data-testid="kiosk-order-summary-*"` sur root, main-item, main-name,
    main-price, total, total-price, qty, qty-value, qty-minus, qty-plus.
  - Total en `role="status"` + `aria-live="polite"` avec `aria-label` plein
    (label + montant) pour lecture par lecteur d'écran.
  - Quantité : `role="group"` + `aria-labelledby`, buttons ont `aria-label`
    explicit (`kiosk.decrease_qty`, `kiosk.increase_qty`), valeur a
    `aria-label="kiosk.quantity_of"` dynamique.
  - Image : `alt` = nom sanitized (avant vide).
  - Sections `role="list"`.
- **CSS (34 remplacements)** : tokens complets, 0 hex. Success color
  (`#27ae60`) → `--kiosk-success`.
- **Focus WCAG 2.4.7** : boutons qty recevront outline ring.

## Non-régression

- **Vitest** : `npx vitest run tests/js/` → **27 fichiers, 226 tests ✅**
  (+18 vs audit précédent : +6 cart + +6 payment + +6 upsell/summary).
- **Build** : `npx mix` → `app.js 13 MiB`, `css/app.css 182 KiB`,
  `js/kiosk.js 1.28 MiB` — compilation OK sans warning bloquant.
- **Hex hex post-refactor** (vérifié manuellement) :
  - `KioskPaymentComponent.vue` : 5 hex conservés (3 gradients payment brand
    cohérents — documentés inline — + 2 overlay TPE neutres)
  - `KioskUpsellComponent.vue` : 0 hex
  - `KioskOrderSummaryComponent.vue` : 0 hex

## Patterns consolidés (à réutiliser sur les composants restants)

1. **data-testid** format : `kiosk-<screen>-<element>[-<id>]`, utilisé par
   Playwright E2E (préparation Phase 5).
2. **A11y stacks** récurrents :
   - Choix mutuellement exclusifs → `role="radiogroup"` + `role="radio"` +
     `aria-checked` + handlers Space/Enter.
   - Liste d'éléments interactifs → `role="list"` + `role="listitem"` +
     `aria-pressed` si sélection.
   - Notifications différées → `role="status"` + `aria-live="polite"`.
   - Erreurs bloquantes → `role="alert"`.
   - Overlays modaux → `role="dialog"` + `aria-modal="true"` +
     `aria-labelledby`.
3. **CSS tokens** priorité :
   - Couleurs brand : `--kiosk-primary`, `--kiosk-primary-soft`,
     `--kiosk-text-on-red`.
   - Neutres : `--kiosk-bg`, `--kiosk-surface`, `--kiosk-surface-alt`,
     `--kiosk-border`.
   - Typo : `--kiosk-text`, `--kiosk-text-muted`.
   - État : `--kiosk-success`, `--kiosk-error`.
   - Elévation : `--kiosk-shadow-card`, `--kiosk-shadow-lift`,
     `--kiosk-shadow-cta`.
   - Focus : `--kiosk-focus-ring` fallback `--kiosk-primary`.

## Invariants confirmés

- **§1.1 SSOT pricing** : tous les prix affichés proviennent du serveur
  (`item.convert_price`, `runningTotal` via helpers, `cartTotal` via Vuex
  getter). Aucun nouveau calcul introduit côté Vue.
- **§1.2 branch_id** : inchangé (pas de champs ajoutés au payload).
- **§1.5 Pas de stats client** : upsell cards lisent uniquement
  `suggestions[]` retourné par l'endpoint, pas de tri/score local.
- **§1.7 WCAG 2.2 AA** : outlines 3px sur focus-visible, roles ARIA ajoutés,
  aria-live sur updates. AAA/PMR toggles (Phase 4) héritent via `--kiosk-*`.

## Reste à couvrir (Phase 2 queue basse)

Ces composants restent à restyler mais sans blocage conversion (priorité plus
faible : soit déjà partiellement tokenisés, soit volumes plus petits) :

| Composant | Raison |
|---|---|
| `KioskWizardComponent.vue` | déjà tokenisé partiellement — audit visuel uniquement requis |
| `KioskStep{Taille,Pain,Viande,Sauce,Garnitures,Supplements,Menu}Component.vue` | nombreux petits composants enfants du wizard, restyles triviaux en batch |
| `KioskWaitingComponent.vue` | à compléter avec data-testid + aria-live status |
| `KioskAdminComponent.vue` | back-office kiosk, hors flux client — priorité basse |

Recommandation : traiter la queue basse dans un PR dédié en batch léger, car
les steps wizard partagent 80 % de leur structure. Aucun blocant pour
démarrer Phase 4 (a11y / i18n) ou Phase 5 (hardware bridge).

## Evidence

- `tests/js/KioskPaymentRestyle.spec.js` — 6/6 ✅
- `tests/js/KioskUpsellOrderSummaryRestyle.spec.js` — 6/6 ✅
- `tests/js/` global — 226/226 ✅
- `npx mix` compilation — ✅
