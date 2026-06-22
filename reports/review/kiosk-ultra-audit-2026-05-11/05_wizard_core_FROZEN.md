# K05 — Wizard core [FROZEN]

> Sub-agent : claude-opus-4-7 (K05 read-only audit)
> Branche : `feature/mobile-app-le-cayenne-2026-05-10` · HEAD `245e8ab57`
> Mode : audit (READ-ONLY) — toutes propositions de fix taguées
> `[OWNER GATE REQUIRED]` (cf. §3 du `00_ULTRA_PLAN.md`).

## Files audited

| File | Lines | Status |
|------|------:|--------|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 3094 | **FROZEN** |
| `resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue` | 18 | NOT frozen — strict wrapper |

### Frozen drift vs `main`

```
git diff main..HEAD -- KioskWizardComponent.vue --stat
→ +1663 / -228 lines
```

`git log` reveals **20+ commits** post-snapshot tagged `[CV1-WIZARD-COMPOSABLE-001]`,
`[P-MEGA-XX]`, `[RED-R2/BLUE-R2]`, `[test-e2e/borne C-001/E-005]`,
`Phase 8.8/9.1.2/9.1.3`, `Phase C/D/v3.6/v3.7/v3.8`. Only **ONE** of those is
covered by a written LOCK document : `plans/LOCK_KIOSK_SALADE_2026-05-11.md`
which authorises a 9-line surgical change at lines 619-628 (salade template).
The remaining **+1654/-228** lines are uncovered drift on a frozen file
flagged by both POS audit (cited in K05 brief) and `CLAUDE.md §7`.

Observed legitimate work mixed in : a11y dialog wrapping + Tab-trap
(L2-7, L2199-2228), pricing-preview helper + offline toast (L160-2180),
step registry T-WC-KIOSK-REGISTRY-01 (L310-339), `effectiveWizardTemplate()`
composer-profile-first contract (L884-906), 8-template switch
(L555-642), frites_style step (L630-641, L1025-1039), etc.

**Verdict drift** : not a gold-plating commit-amend storm — most additions
are functionally motivated and tracked by existing tests. But the LOCK
discipline (cf. `CLAUDE.md §7`, memory feedback
`reference_frozen_zones.md`) was **not respected**. Audit-only here ; any
*new* fix below stays `[OWNER GATE REQUIRED]`.

`KioskPosWizardComponent.vue` is a strict compat-wrapper (8 lines of
template, 9 lines of script) — clean, verified.

## Findings

### P0 (blocker pre-merge V1)

- **K05-P0-01 — Frozen-zone discipline violated (1663 lines added without LOCK)**
  - File: `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1-3094`
  - Issue: Per `CLAUDE.md §7` and `reference_frozen_zones.md`, KioskWizardComponent
    is frozen ; only LOCK_KIOSK_SALADE_2026-05-11 covers 9 lines (619-628).
    Net drift `+1663/-228` since `main`. Cross-validated by POS audit
    that already flagged "+1665 lines".
  - Evidence: `git diff main..HEAD --stat`, `git log --oneline -20` on
    the file shows 20+ commits including non-LOCK'd refactors
    (P-MEGA-01/04/05, T-WC-KIOSK-REGISTRY-01, C-001/E-005 fixes).
  - Suggested fix: **[OWNER GATE REQUIRED]** retroactive LOCK doc
    consolidating drift OR explicit owner sign-off in
    `PROJECT_BRAIN.md §6 DECISIONS LOG` before V1 merge. Re-enable
    safety-check.sh frozen-files guard before any further mutation.

### P1 (high — V1.0.1 sprint)

- **K05-P1-04 — `_wizardSelections` payload contract pinning fragile (whitelist, not enforced)**
  - File: `KioskWizardComponent.vue:1998-2005` + `store/modules/kioskCart.js:98-111`
  - Issue: `buildCartItem()` injects a deep-clone of `selections`
    (`_wizardSelections`) into the cart line so edit-restore (P-MEGA-05) works.
    Currently safe : `sanitizeKioskOrderItem` whitelists `{ item_id, instruction,
    quantity, item_variations, item_extras, item_addons }`. But the contract
    is **not pinned by test** — a future refactor to a blacklist or
    spread-based serializer would leak `_wizardSelections` (containing
    `_painMeta.realId`, internal price hints, `composerChoices`) into the
    `/api/frontend/order` payload and corrupt `composition_snapshot`.
    Risk is fragility, not observable defect.
  - Evidence: `kioskCart.js:99-105` whitelist literal ; no test asserts
    "no leading-underscore key in POST body".
  - Suggested fix: **[OWNER GATE REQUIRED]** add unit-test (proposed
    T-K05-02) intercepting `axios.post('frontend/order', ...)` and
    asserting payload has no `_*` keys nor `price` / `convert_price`.


- **K05-P1-01 — Heuristique template fallback ignore le step menu pour items "Menu Enfant"**
  - File: `KioskWizardComponent.vue:925-931, 609-617`
  - Issue: `detectTemplateFromName()` mappe les Menus Enfants vers template
    `omelette` qui n'a QUE `sauce + recap` (L615-617). Mais "Menu" implique
    typiquement un choix boisson — la décision owner est documentée en L610-614
    "boisson upsell géré post-cart via KioskUpsellComponent" donc *behavior
    intentional*, mais aucune assurance test que `has_menu` n'écrasera pas
    cette décision via Priority-1 `wizard_template` puisque la priorité est
    `composer_profile > item.wizard_template > category.wizard_template > heuristic`.
  - Evidence: L884-906 priority chain ; if backend sends `wizard_template='omelette'`
    AND `has_menu=true`, the menu step is absent because not in switch case.
  - Suggested fix: **[OWNER GATE REQUIRED]** assert at admin-side that Menu
    Enfant items NEVER have `has_menu=true` exposed to kiosk surface ; OR
    add a runtime warn in `effectiveWizardTemplate()` when
    `template==='omelette' && item.has_menu===true`.

- **K05-P1-02 — `runningTotalLocal` divergence du serverPreview surfaced in footer only**
  - File: `KioskWizardComponent.vue:204` (footer total) vs L1739 SSOT preview
  - Issue: footer affiche `runningTotalLocal` (pure helper), pas le SSOT
    serveur `serverPreviewTotal` (cf. comment L190-202 `test-e2e/borne C-001
    round-2`). Recap card affiche aussi local. Customer voit donc le local
    qui peut diverger de ce que `/api/frontend/order` calculera —
    `composition_snapshot` est SSOT au moment de la commande, divergence
    silencieuse jusqu'à paiement.
  - Evidence: L204 `formatPrice(runningTotalLocal)` ; preview SSOT existe
    (L433, L1739) mais downgrade conscient.
  - Suggested fix: **[OWNER GATE REQUIRED]** afficher un loader/skeleton
    de prix pendant `serverPreviewLoading`, et si delta >= 0.50€ entre
    local et server, surfacer toast `kiosk.wizard.pricing_preview_offline`
    proactivement (déjà câblé sur erreur uniquement L2167).

- **K05-P1-03 — Step header pas annoncé en aria-live, pas d'aria-current sur les dots**
  - File: `KioskWizardComponent.vue:78-86, 137-139`
  - Issue: `kiosk-step-dot` n'a pas `aria-current="step"` sur l'active dot ;
    le `<div class="kiosk-step-question">` qui affiche le titre du step
    n'est pas annoncé via `aria-live="polite"` ni `role="heading"
    aria-level="2"`. Navigation au changement de step n'envoie aucune
    annonce au screen reader. WCAG 2.1 §4.1.2.
  - Evidence: L78-86 + L137-139. Grep `aria-live\|aria-current` → 0 hit.
  - Suggested fix: **[OWNER GATE REQUIRED]** ajouter `:aria-current="index === currentStepIndex ? 'step' : null"` sur kiosk-step-dot
    et `aria-live="polite" role="status"` sur kiosk-step-question, ou
    appeler `announce()` (cf. helper `a11y/announcer.js` déjà utilisé).

### P2 (medium — backlog)

- **K05-P2-01 — Pas de navigation clavier flèches gauche/droite entre steps**
  - File: `KioskWizardComponent.vue` (aucune ligne — feature absente)
  - Issue: Tab-trap installé (L2209-2228), focus dialog OK, mais `ArrowLeft/ArrowRight`
    pour naviguer entre steps n'existe pas. EAA 2025 + WCAG 2.1 §2.1.1
    keyboard accessible.
  - Suggested fix: **[OWNER GATE REQUIRED]** dans le tab-trap handler,
    ajouter `if (e.key === 'ArrowRight' && canAdvance) nextStep()` + symétrique
    avec garde `!isInsideTextarea(target)`.

- **K05-P2-02 — `i18n` fallback hard-coded English strings**
  - File: `KioskWizardComponent.vue:1580-1610`
  - Issue: `getStepLabel` et `getQuestionLabel` retombent sur des libellés
    EN ("BREAD", "CHOOSE MEAT?") si la clé `kiosk.wizard.prompt.X` manque.
    V1 est FR-lock ; un fallback raw FR/symbol serait plus cohérent.
  - Suggested fix: **[OWNER GATE REQUIRED]** retourner une chaîne neutre ou
    `step.label || type`.

- **K05-P2-03 — Heuristique nom item duplique la responsabilité backend**
  - File: `KioskWizardComponent.vue:907-947`
  - Issue: `detectTemplateFromName` reproduit la classification backend.
    Le PROJECT_BRAIN dit que `wizard_template` est exposé par l'API.
    `kioskAnalytics.trackHeuristicFallback` est appelé (L900-904) — bien.
    Mais l'existence même du fallback peut masquer un bug data quietement.
  - Suggested fix: **[OWNER GATE REQUIRED]** alerter via Sentry/console.error
    si fallback heuristique > 5% des items d'une session.

### P3 (low — nice-to-have)

- **K05-P3-01 — `getStepIcon` n'a pas d'entrée pour `taille`, `frites_style`, `generic_choices`** (résolu partiellement L1495 mais pas pour `frites_style`)
  - File: `KioskWizardComponent.vue:1492-1506`
  - Issue: map ne contient pas `frites_style` → `•` affiché.
  - Suggested fix: ajouter `frites_style: '🍟'` etc.

- **K05-P3-02 — `kiosk-step-visual-fallback` emoji jamais traduit pour RTL**
  - File: `KioskWizardComponent.vue:1497-1505`
  - Issue: emojis OK universels, mais en mode AR RTL pas testé visuellement
    avec `dir=rtl`.
  - Suggested fix: capture E2E AR RTL spécifique.

## 8 templates dispatcher — verification

Grep `case '` dans `activeSteps` L555-642 :
1. `tacos` L556 — taille(opt)+viande+sauce+garnitures+supplements+menu+recap
2. `sandwich` L566 — pain+viande(opt)+sauce+garnitures+supplements+menu+recap
3. `burger` L576 — viande(opt)+sauce+garnitures+supplements+menu+recap
4. `assiette` L585 — viande(opt)+sauce+recap (owner-feedback round-5 simplifié)
5. `snacking` L598 — sauce+menu+frites_style+supplements+recap
6. `omelette` L609 — sauce+recap (owner-feedback round-5 simplifié)
7. `salade` L619 — sauce+supplements+menu+frites_style+recap (LOCK 2026-05-11)
8. `default/simple` L633 — frites_style+supplements+recap

**8 paths confirmés**. Composer-profile-driven mode L549-550 court-circuite
le switch via `composerActiveSteps()` (L784-800) → contrat *composer-first*
documenté L877-883. Aucun template manquant pour la matrice catalogue
fast-food V1 actuelle.

## composition_summary payload audit — NF525 contract

Frontend builds en `buildCartItem()` L1747-2005, et le store sérialise via
`sanitizeKioskOrderItem` (kioskCart.js:98-111). **Whitelist stricte** :
```js
{ item_id, instruction, quantity, item_variations[], item_extras[],
  item_addons[]? }   // 6 keys max — PAS de price, PAS de _wizardSelections
```
Le helper `sanitizeKioskOrderModifiers` (L114-140) re-strip chaque modifier
à `{ id, name?, variation_name?, role?, quantity? }`. **Aucun champ `price`
ni `convert_price` n'est sérialisé vers `/api/frontend/order`.** Contrat
SSOT (cf. `CLAUDE.md §8 Pricing SSOT`) respecté.

**Risque résiduel** : `_wizardSelections` reste sur la cart line en mémoire
client (L2004) — couvert par la whitelist actuelle, fragile si on refactor
en blacklist (cf. K05-P0-02).

## EAA 2025 allergens mid-wizard

`KsAllergenBadge` est rendu **en header du wizard** L29-39 + recalculé via
`allergenBadgeSelections` L462-535 qui injecte variations (pain, viande,
sauce) ET extras (garnitures, supplements, composer choices) sélectionnés
**en temps réel**. Donc le client voit les allergènes :
- de l'item base (FIC UE 1169/2011 — toujours visible),
- des sélections en cours (extras lait via fromage, etc.).

`customerAllergenCodes` L540-546 active `role=alert` si intersection
non-vide. Wired par `data-testid` pour E2E. **Compliance FIC + EAA 2025
mid-wizard : OK**, mais visibilité dépend du composant `KsAllergenBadge`
non audité ici (cf. K07/K14 si dans le scope).

## Edit-restore / catalog-change / composer-profile-change flows

| Flow | Wiring | Tests existants |
|------|--------|-----------------|
| Edit-restore (cart → wizard → save) | L2118-2130 `restoreEditingSelectionsIfAny()` + L2104 `replaceEditingCartItem` | `kioskWizardEditRestore.spec.js`, `kioskWizardEditRoundtrip.spec.js` |
| Catalog change mid-wizard | composable `useCatalogChangeNotifier` (pas dans ce fichier — externe) | `kioskWizardCatalogChangedHandling.spec.js` |
| Composer-profile-change | `sanitizeComposerChoicesForCurrentProfile` L1366-1379 + restore call L2125 | `kioskWizardComposerProfile.spec.js`, `kioskWizardGenericComposer.spec.js`, `kioskWizardStepRegistry.spec.js` |
| Wizard navigation (back/forward preserves selections) | `nextStep/prevStep` L1478-1487 + key-based remount L145 | `kioskWizardNavigation.spec.js` |

**Verdict** : 3 flows couverts par spec dédiés. Risque résiduel = K05-P1-02
(price divergence local↔server pas testée E2E sur edit-restore).

## Existing E2E coverage

- `tests/js/kioskWizardEditRestore.spec.js` — restore selections from cart line
- `tests/js/kioskWizardNavigation.spec.js` — back/forward preserves selections (P-MEGA-04)
- `tests/js/KioskWizard.spec.js` — POS-kiosk wrapper convergence
- `tests/js/kioskWizardCatalogChangedHandling.spec.js` — CatalogChanged toast + prune (CV1-LIFECYCLE-UX-001)
- `tests/js/kioskWizardGenericComposer.spec.js` — generic composer step
- `tests/js/kioskWizardComposerProfile.spec.js` — composer profile dispatcher
- `tests/js/kioskWizardStepRegistry.spec.js` — explicit step_key→component registry
- `tests/js/kioskWizardEditRoundtrip.spec.js` — round-trip cart↔wizard
- `tests/e2e/03-kiosk-wizard.spec.js` — full kiosk E2E
- `tests/e2e/test-e2e-mobile-design-perfect-wave-wizard.spec.js` — design

## Proposed new E2E tests

- **T-K05-01: 8-template smoke — chaque template ouvre les steps attendus**
  - Steps: pour chaque `wizard_template ∈ {tacos, sandwich, burger, assiette,
    omelette, snacking, salade, simple}`, monter wizard avec item mock,
    asserter ordre exact `activeSteps`.
  - Assertions: longueur + types matchent la documentation §K05 ; aucun
    step recap n'est dupliqué.

- **T-K05-02: Payload contract — no `_wizardSelections` ni `price` dans POST /frontend/order**
  - Steps: ajouter item au cart via wizard, intercepter `axios.post` ;
    JSON.parse(items) doit n'avoir que les 6 clés whitelist.
  - Assertions: aucune clé starting with `_`, aucune clé `price` /
    `convert_price`. Pin le contrat NF525.

- **T-K05-03: Edit-restore + composer-change race — sanitize purge les choices orphans**
  - Steps: cart line avec composerChoices référant un ID ; backend
    `ComposerProfileChanged` event retire ce choice ; rouvrir wizard en
    mode edit.
  - Assertions: `selections.composerChoices` ne contient pas le choice
    orphan (via `sanitizeComposerChoicesForCurrentProfile`).

- **T-K05-04: A11y — aria-current="step" sur dot actif, step heading annoncé**
  - Steps: monter wizard, naviguer steps, attendre debounce.
  - Assertions: `getByTestId('kiosk-step-dot-N')` a `aria-current="step"`
    quand `N === currentStepIndex`. `kiosk-step-question` annoncé via
    helper `announce()` (mock vi.fn vérifié).

- **T-K05-05: Heuristique fallback alerte si > 5% items session**
  - Steps: simuler 10 items sans `wizard_template` ni `composer_profile`.
  - Assertions: `kioskAnalytics.trackHeuristicFallback` appelé N=10 fois,
    et console.error fired si seuil dépassé.

## Risks & open questions

1. **Frozen-zone discipline retroactive** : 1663 lignes ajoutées sur fichier
   `[FROZEN]` sans LOCK explicite. Cross-validé par POS audit. **Owner
   gate requis** avant V1 merge — soit on LOCK rétroactivement, soit on
   discipline la liste de drifts acceptables. Recommandation : rétro-LOCK
   consolidé citant tous les `[P-MEGA-XX]` + `[CV1-XXX]` legitimes, puis
   re-enable `safety-check.sh` frozen-files guard.

2. **Pricing preview UX divergence** : footer + recap affichent
   `runningTotalLocal` pour éviter le bug C-001 (boisson over-counted par
   `/pricing/preview`). Mais cela masque le SSOT serveur. Décision owner :
   on garde local (UX fluide, divergence rare) ou on corrige le bug
   `/pricing/preview` backend pour pouvoir réafficher serverPreviewTotal ?

3. **EAA 2025 allergens scope** : badge rendu en header (visible permanent
   mid-wizard, OK), mais le composant `KsAllergenBadge` lui-même est hors
   scope K05. À cross-valider avec audit K07 (cart) et K14 (helpers
   allergens) — recommander à l'orchestrateur d'inclure ce sub-component
   dans le pipeline.

4. **`shouldShowStep('menu')` priorité contradictoire** : si
   `wizard_template === 'omelette'` ET `item.has_menu === true`, le step
   menu n'est pas exposé (pas dans le case `omelette` switch). Cohérence
   admin nécessaire — voir K05-P1-01.

5. **`runningTotalLocal` fallback pendant cold-start** : si l'utilisateur
   ajoute au cart avant que le preview serveur n'ait répondu (rare mais
   possible sur réseau lent ou 401), le payload final est correct (backend
   recalcule) mais l'analytics `cart_added` peut logguer un prix légèrement
   off. Pas bloquant V1.

## Verdict

**Top wizard-correctness risk** : **K05-P1-02 — divergence prix
local↔serveur affichée au customer.** Footer + recap montrent
`runningTotalLocal` (pure helper) tandis que `serverPreviewTotal` (SSOT
backend) est calculé en parallèle mais ignoré pour l'affichage afin de
contourner le bug C-001 (boisson over-counted par `/pricing/preview`).
Résultat : le client voit un prix qui peut diverger de ce que le backend
charge réellement à la création d'order. NF525 final reste correct
(`composition_snapshot` SSOT), mais le contrat customer-trust est fragile
et la décision n'a pas de loader/toast proactif (uniquement on-error).

**Parallel governance flag (non-correctness)** : K05-P0-01 — discipline
frozen-zone violée (+1663 lignes uncovered LOCK). Le code lui-même est
globalement correct et bien testé (8+ specs) ; les invariants NF525
(payload sans `price` ni `_wizardSelections`) sont respectés via la
whitelist explicite du store. Mais sans rétro-LOCK signé par l'owner,
le merge V1 expose le projet à une régression d'audit (POS audit flag
déjà ce drift) et à l'érosion silencieuse de la frontière "frozen".

**Recommandation V1 merge** : `block` jusqu'à rétro-LOCK + owner sign-off
en `PROJECT_BRAIN.md §6 DECISIONS LOG`. Une fois la discipline restaurée,
les P1 (price preview UX, a11y step heading, Menu Enfant heuristique,
payload contract test) peuvent partir en sprint V1.0.1 sans bloquer la
livraison.
