# K09 — Upsell [FROZEN] + Toasts

> HEAD audité : `6a33a9763b7ef8da9ffb350732b1cdff1fab2261`
> Branche : `feature/mobile-app-le-cayenne-2026-05-10`
> Date : 2026-05-11
> Mode : READ-ONLY. Fixes touchant la frozen zone tagués **[OWNER GATE REQUIRED]**.

## Files audited

- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` — 543 lignes — **(FROZEN)**
- `resources/js/components/frontend/kiosk/KioskToastComponent.vue` — 127 lignes
- `resources/js/components/frontend/kiosk/CatalogChangeToastComponent.vue` — 244 lignes (SKELETON Codex partiellement wired)
- `resources/js/helpers/kioskUpsellFlow.js` — 25 lignes
- `tests/js/kioskUpsellFlow.spec.js` — 32 lignes
- Sources adjacentes lues : `resources/js/composables/useCatalogChangeNotifier.js`, `KioskAppComponent.vue` (provider showToast L205-212), `KioskCartComponent.vue` (consumer L526-547, helper L420-422), `store/modules/kioskCart.js` (`fetchUpsellItems` L820-833, `addItem` L488-490), `app/Http/Controllers/Frontend/ItemController.php::kioskUpsell` L68-108, `routes/api.php` L1141-1144, `tests/js/KioskUpsellOrderSummaryRestyle.spec.js`.

## Frozen drift verification

```
git diff main..HEAD -- KioskUpsellComponent.vue --shortstat
> 1 file changed, 31 insertions(+), 26 deletions(-)
```

**Verdict drift : autorisé.** Le diff ne touche que :
1. `<button>` → `<button type="button">` (sécurité par défaut, anti form-submit).
2. CSS tokens migrés vers la palette design refresh 2026-05-10 (light mode + mobile palette noir/rouge/jaune/blanc) : `--kiosk-surface` → `--kiosk-page-bg`, ajouts `--kiosk-shadow-sticky`/`--kiosk-shadow-card`/`--kiosk-shadow-cta`/`--kiosk-primary-soft`/`--kiosk-product-media-bg`/`--kiosk-focus-ring`, sizing tactile augmenté (cards 18px→28px radius, CTA 64px→76px min-height, font 18→22px weight 700→900, uppercase title).

Aucune modification de logique, template structure, i18n keys, ARIA, ou actions Vuex. Aligné avec la mémoire `project_kiosk_design_refresh_2026-05-10`.

## Findings

### P0 (blocker pre-merge V1)
**Aucun P0 identifié sur ce scope.**

### P1 (high — V1.0.1 sprint)

- **K09-P1-01: Upsell ajout au panier déclenche un toast — viole le mandate "cart-add must NOT trigger toast"** **[OWNER GATE REQUIRED — FROZEN]**
  - File: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue:241-246`
  - Issue: `addAndContinue()` appelle `this.showToast(this.$t('kiosk.upsell_screen.toast_added_one'/'toast_added_many'), 'success')` après l'ajout au panier. Or l'audit K07 cart bottom-sheet + commentaire explicite `KioskCartComponent.vue:537` ("vs add-to-cart où owner ne veut pas de toast") confirme que tout ajout au panier doit être absorbé par le bottom-sheet, jamais par toast.
  - Evidence:
    ```js
    this.showToast(
      count === 1
        ? this.$t('kiosk.upsell_screen.toast_added_one', { name: firstName })
        : this.$t('kiosk.upsell_screen.toast_added_many', { n: count }),
      'success'
    );
    ```
  - Suggested fix: supprimer l'appel `showToast(...)` du flow `addAndContinue` (frame visuel = le redirect vers `kiosk.payment` est déjà la confirmation). Keys i18n `toast_added_one`/`toast_added_many` (fr/en/ar L1436-1437/1592-1593/1426-1427) deviennent orphelines → à retirer aussi.

- **K09-P1-02: `CatalogChangeToastComponent` n'escalade jamais `role="alert"` / `aria-live="assertive"` pour severity=warning**
  - File: `resources/js/components/frontend/kiosk/CatalogChangeToastComponent.vue:27-31`
  - Issue: Le template fixe en dur `role="status"` + `aria-live="polite"` pour TOUTES les sévérités. Pourtant `severity="warning"` est utilisé quand `useCatalogChangeNotifier` détecte des `removedItems > 0` (panier réellement amputé, useCatalogChangeNotifier.js:330) — un évènement perturbant qui mérite une annonce assertive interrompant la lecture courante. Le TODO L109-115 du fichier lui-même reconnaît ce gap.
  - Evidence:
    ```html
    <div ... role="status" aria-live="polite" aria-atomic="true" :data-severity="severity">
    ```
  - Suggested fix: lier `role`/`aria-live` à `severity` :
    ```html
    :role="severity === 'warning' ? 'alert' : 'status'"
    :aria-live="severity === 'warning' ? 'assertive' : 'polite'"
    ```
  - Bonus : la sentinel spec mentionnée L116 (`kioskWizardCatalogChangedHandling.spec.js`) doit valider ce comportement.

- **K09-P1-03: Auto-skip timer 30s sans pause au focus visible — risque d'auto-skip pour SR/clavier**
  - File: `KioskUpsellComponent.vue:187-199` **[OWNER GATE REQUIRED — FROZEN]**
  - Issue: Le timer démarre dès `loadSuggestions` finit, n'est reset QUE par `toggleItem`. Un utilisateur SR/clavier qui parcourt les cartes sans en sélectionner perd l'écran en 30s. Aucun `@focus`/`@keydown` global ne reset le countdown.
  - Suggested fix: étendre le reset à `@keydown` global du root + `@focus` des cards (sans modifier le timing visuel) :
    ```html
    <div class="kiosk-upsell" @keydown="resetAutoSkipOnInteraction">
    ```

### P2 (medium — backlog priorisé)

- **K09-P2-01: Pas de focus initial automatique sur la grille upsell après chargement** **[OWNER GATE REQUIRED — FROZEN]**
  - File: `KioskUpsellComponent.vue:151-153, 186`
  - Issue: Après `loadSuggestions` finit, aucun élément ne reçoit le focus. Un utilisateur SR/clavier landed sur le body, doit Tab N fois pour atteindre la première card. WCAG 2.4.3 Focus Order.
  - Suggested fix: après `loading=false`, `this.$nextTick(() => this.$refs.firstCard?.focus())` sur la première card via `:ref="`upsell-card-${idx}`"`.

- **K09-P2-02: `aria-valuenow` du progressbar autoskip update 10×/seconde — SR verbose**
  - File: `KioskUpsellComponent.vue:101-104, 191-198`
  - Issue: `Math.round(autoSkipPct)` change ~tous les 0.1s pendant 30s = ~300 mutations live. Certains screen readers (NVDA notamment) verbosent chaque tick. WCAG 4.1.3 Status Messages.
  - Suggested fix: throttler `aria-valuenow` à chaque seconde (lié à `autoSkipRemaining` plutôt que `autoSkipPct`) — la barre visuelle reste fluide via CSS `transition: width 0.1s linear` mais l'attribut `aria-valuenow` ne change qu'une fois par seconde.

- **K09-P2-03: `KioskToastComponent` — toast click n'affiche pas de close indicator pour SR**
  - File: `KioskToastComponent.vue:13-26`
  - Issue: Toast container has `pointer-events: none` (L70) mais chaque toast a `pointer-events: all + cursor: pointer` (L83-84). Un click sur le body du toast ne le ferme pas (seul le `×` close button le fait). Le pointer cursor induit en erreur.
  - Suggested fix: soit ajouter `@click.stop="remove(toast.id)"` sur le `.kiosk-toast`, soit retirer le `cursor: pointer` du body et garder seulement sur le bouton close.

- **K09-P2-04: Polite `aria-live` sur container ET dynamic `aria-live` sur chaque toast — double-announcement risk**
  - File: `KioskToastComponent.vue:5-17`
  - Issue: Le `<transition-group>` racine porte déjà `role="status" aria-live="polite" aria-relevant="additions text"`. Chaque `.kiosk-toast` ajoute en plus `:role="(toast.type === 'error' || 'warning') ? 'alert' : null"` + `:aria-live="(...) ? 'assertive' : null"`. Pour les toasts error/warning, certains AT lisent 2× (polite + assertive sur ancestor + child).
  - Suggested fix: retirer `aria-live` du container, garder uniquement la logique dynamique par toast. Ou inverser : container `role="region" aria-label="Notifications"` sans live, et chaque toast porte son propre live region.

### P3 (low — nice-to-have)

- **K09-P3-01: `kioskUpsellFlow.js` traite `item_category_id` manquant comme "stop skipping"**
  - File: `resources/js/helpers/kioskUpsellFlow.js:18-22`
  - Issue: Si une ligne du panier perd son `item_category_id` (ex: ancienne entrée localStorage), la fonction return `false` (montre l'upsell). Pas un bug, mais comportement non documenté.
  - Suggested fix: ajouter JSDoc explicite "if any line lacks item_category_id, defaults to showing upsell (safe)".

- **K09-P3-02: Helper `getEmoji` mélange tokens FR ("frite", "boisson") et globaux ("coca")**
  - File: `KioskUpsellComponent.vue:123, 270-276` **[OWNER GATE REQUIRED — FROZEN]**
  - Issue: Carte sans image avec un nom AR comme "كولا" tombera sur le fallback `🍽️` au lieu de `🥤`. Acceptable V1 (les images sont uploaded sur 95% des cartes) mais documentation drift.
  - Suggested fix: support i18n des keys de l'emoji map ou utiliser `category.icon` côté backend.

- **K09-P3-03: `CatalogChangeToastComponent` `data-kiosk-reduced-motion` honoré dans CSS, pas dans JS**
  - File: `CatalogChangeToastComponent.vue:237-242`
  - Issue: Le CSS swap motion en fade pour `[data-kiosk-reduced-motion='true']`, mais le JS `watch(visible)` programme un setTimeout 5s `dismissTimer` qui ne tient pas compte d'une éventuelle préférence "longer dwell time" (WCAG 2.2.4 Timing Adjustable). Non-bloquant pour V1.

## Existing E2E coverage

- `tests/js/kioskUpsellFlow.spec.js` — couvre uniquement `shouldSkipKioskUpsellScreen` (4 tests : empty cart, all-skip, partial-skip, unknown category). Pas de couverture intégration.
- `tests/js/KioskUpsellOrderSummaryRestyle.spec.js` — 3 tests sur KioskUpsell : loading state, grid+cards+skip+autoskip rendered, keyboard Space toggle. **Aucune assertion sur le toast post-`addAndContinue` ni sur les analytics events.**
- `tests/Feature/UpsellApiTest.php` + `tests/Feature/KioskUpsellCategoryTest.php` + `tests/Feature/KioskPhase1/UpsellRuleModelTest.php` — backend kioskUpsell + upsell_rules.
- Aucun test dédié à `KioskToastComponent` ni `CatalogChangeToastComponent` (la sentinel `kioskWizardCatalogChangedHandling.spec.js` mentionnée n'a pas été lue mais existe selon TODO).

## Proposed new E2E tests

- **T-K09-01: upsell `addAndContinue` ne déclenche PAS de toast (post-fix P1-01)**
  - Steps:
    1. Mount `KioskUpsellComponent` avec 2 suggestions.
    2. Click sur 1 card → click `[data-testid="kiosk-upsell-add-continue"]`.
    3. Spy sur `showToast` injection.
  - Assertions: `expect(showToastSpy).not.toHaveBeenCalled()` + `addItem` called 1×.

- **T-K09-02: `CatalogChangeToastComponent` escalade ARIA assertive pour severity=warning**
  - Steps:
    1. Mount `CatalogChangeToastComponent` avec `:visible="true" :severity="'warning'" :removedSelections="[{id:1}]"`.
    2. Read `root.attributes('role')` + `root.attributes('aria-live')`.
  - Assertions: `expect(role).toBe('alert')` + `expect(ariaLive).toBe('assertive')`.

- **T-K09-03: auto-skip timer pause/reset quand utilisateur clavier navigue (post-fix P1-03)**
  - Steps:
    1. Mount upsell avec 3 cards.
    2. `vi.useFakeTimers()`, avance 10s, simule keydown Tab/Arrow sur root.
    3. Assert `autoSkipRemaining === AUTO_SKIP_SECONDS`.

- **T-K09-04: `KioskToastComponent` n'affiche pas de double-live-region pour toast warning**
  - Steps: monter KioskToast, appeler `show('msg', 'warning')`, lire DOM.
  - Assertions: vérifier qu'un seul nœud porte `aria-live` (pas container + child simultanément).

- **T-K09-05: Backend `/api/frontend/item/kiosk-upsell` filtre `kiosk_upsell_include=true` et exclut items dans `item_ids`**
  - Steps: Feature test — créer 2 catégories (one with `kiosk_upsell_include=false`), 4 items avec `is_upsell=Ask::YES`, query `?item_ids=1,2&limit=6`.
  - Assertions: réponse contient uniquement items de la cat avec flag true ET pas ids 1/2.

## Risks & open questions

- **OWNER GATE — P1-01** : le toast post-`addAndContinue` est-il un legacy oversight ou un comportement délibéré (le wizard utilisateur n'a pas de bottom-sheet à ce moment précis, puisqu'il va direct payment) ? Si délibéré, garder mais aligner avec K07 audit. Si oversight (probable selon `KioskCartComponent.vue:537` mandate), supprimer.
- **OWNER GATE — P1-03, P2-01, P3-02** : tous touchent `KioskUpsellComponent.vue` frozen — nécessitent LOCK doc pour patch.
- **CatalogChangeToastComponent.vue SKELETON status** : le header L17 dit "implementation TODO Codex". Le composant est wired dans `KioskAppComponent.vue:13-20` mais la sentinel `kioskWizardCatalogChangedHandling.spec.js` n'a pas été vérifiée — risque que les assertions de la spec ne matchent pas ce template. À cross-check avec audit K05 (wizard) et auditeur de la composable.
- **Endpoint `/api/frontend/item/kiosk-upsell` est public (pas de `auth:sanctum`)** — même posture que le reste du préfixe `item/*` (menu browsing public). À noter mais cohérent avec V1.
- **i18n keys orphelines à retirer post-fix P1-01** : `kiosk.upsell_screen.toast_added_one`/`toast_added_many` dans fr/en/ar.
- **Frozen drift assessment final** : +31/-26 lignes — strictement design refresh + `type="button"`. Aucune modification de logique métier, contrat composant, ARIA structure ou Vuex action.
