# Wave W4 — HEALER K (système BORNE) — rapport de heal

**Date** : 2026-06-11 · **Worktree** : `.claude/worktrees/release-v1-2026-06-10` (branche `release/v1-2026-06-10`)
**Base** : `94fd44a08` (rapport W3) · **HEAD final** : `6942eeafa`
**Statut** : **12/12 clusters HEALÉS** · 0 SKIPPED · 0 frozen touché

---

## Commits (1/cluster, ordre K1→K12)

| Cluster | SHA | Titre |
|---|---|---|
| K1 [P1] | `d9ce4bff1` | Format prix borne — enum CurrencyPosition (5/10) normalisé → « 2,00 € » |
| K2 [P1] | `abd1312e4` | Écran blanc inscription fidélité — `@` échappé `{'@'}` (5 locales) |
| K3 [P1] | `087989305` | Boutons overlay inactivité vides — `:label` → slot (KsButton slot-only) |
| K4 [P1] | `4f9df01f7` | Catégorie vide cul-de-sac — filtre backend + état vide FR avec CTA |
| K5 [P1] | `c84197bc7` | Analytics borne perdues — sendBeacon (401) → axios + re-queue |
| K6 [P2] | `08dff7932` | Loyalty 429 brut → FR + aria-labels retour/del |
| K7 [P2] | `0bcc9cac6` | Cible tactile qty « + » — dégagement 30px sous corbeille flottante |
| K8 [P2] | `b26595c7e` | Promo applied enrichie `{code}`/`(−{amount})` FR + parité EN |
| K9 [P2] | `c9116d5e7` | Drawer a11y — section THÈME morte retirée + titre « & langue » stale |
| K10 [P2] | `b38a2a6d2` | Offline paiement — « Network Error » brut → FR + route écran réseau |
| K11 [P3] | `1f06640ed` | Toast — aria-label fermer hardcodé → `$t('kiosk.a11y.close')` |
| K12 [P3] | `6942eeafa` | Seeder — « Upsell item » → « Article complémentaire » |

---

## Détail des fixes (file:line + test)

### K1 — Format prix « €2,00 » → « 2,00 € »
- **Root-cause confirmée** : `resources/js/helpers/kioskFormatPrice.js:37` comparait `position === 'right'` (string) alors que le backend envoie l'enum numérique `App\Enums\CurrencyPosition` (`LEFT=5`, `RIGHT=10` — vérifié `app/Enums/CurrencyPosition.php:7-8`) → branche left systématique sur TOUTES les surfaces borne (grille, panier, wizard frozen inclus via le helper non-frozen, « TOTAL À RÉGLER »).
- **Fix** : `normalizeCurrencyPosition()` exportée (tolère 5/10, '5'/'10', 'left'/'right' ; défaut FR = right), appliquée dans `formatKioskPrice()` et `getPriceOptionsFromStore()`.
- **Test** : `tests/js/kioskFormatPrice.spec.js` +6 cas (enum num, enum string, legacy, défaut, store) — 9/9 verts.

### K2 — Écran blanc inscription fidélité (crash vue-i18n)
- **Root-cause** : `kiosk.loyalty_screen.placeholder_email` avec `@` non échappé = linked format vue-i18n → SyntaxError compilateur de message → render crash `KioskLoyaltyComponent` (non-frozen).
- **Fix** : `vous{'@'}exemple.fr` / `you{'@'}example.com` dans les **5 locales** (`resources/js/languages/{fr:1916,en:1899,ar:1696,de:1409,bn:1409}.json`).
- **Test** : `tests/js/kioskLoyaltyPlaceholderEmailI18n.spec.js` — compile RÉELLEMENT la clé via `createI18n` dans les 5 locales en capturant les diagnostics compilateur (le dev-build de vue-i18n log la SyntaxError au lieu de throw — 1er jet de spec passait à tort, durci). RED 5/5 → GREEN 5/5.

### K3 — Boutons overlay inactivité VIDES
- **Root-cause** : `KioskInactivityOverlayComponent.vue:38,46` passait `:label=` à `KsButton` qui n'a pas de prop `label` (slot-only, `ds/KsButton.vue:17`).
- **Fix** : texte en slot. **Grep global `:label=`** : le seul autre usage (`KioskCashInstructionComponent.vue:30`) vise `KsPriceLine` qui possède la prop → aucun autre call-site à corriger.
- **Test** : `tests/js/kioskInactivityOverlayButtons.spec.js` (mount réel) RED 2/3 → 3/3 (labels non vides + emits préservés).

### K4 — Catégorie vide = cul-de-sac écran blanc
- **Root-cause** : `app/Services/Kiosk/KioskMenuService.php::build()` (classe à :38) projetait toutes les catégories actives, même sans item visible (« Tacos Signature » = 1 item soft-deleted).
- **Fix backend** : exclusion des catégories sans item visible du payload ; **parents conservés si un enfant visible est peuplé** (hiérarchie sidebar intacte).
- **Fix front (défense cache stale)** : `KioskCategoriesComponent.vue` — état vide `data-testid="kiosk-products-empty"` (FR, rôle status) + CTA « Voir les autres catégories » → `firstPopulatedCategory` ; clés `kiosk.catalog.category_empty(+_cta)` dans 5 locales.
- **Tests** : `tests/Feature/Kiosk/KioskMenuEmptyCategoryTest.php` (CRÉÉ — 4 cas : sans item / soft-deleted / inactive / parent+enfant peuplé) RED 3/4 → **4/4** ; suite `tests/Feature/Services/Menu/` **29/29** (le commentaire de `KioskMenuCategoryOrderRegressionTest` anticipait déjà ce filtre) ; `tests/js/kioskCategoryEmptyState.spec.js` 4/4.
- **DB test vérifiée AVANT run** : `.env.testing` → `DB_DATABASE=foodking_test` (fix footgun 2026-06-05 en place) → PHPUnit ciblé autorisé.

### K5 — Hygiène 401 : analytics borne perdues
- **Vrai path** : `resources/js/helpers/kioskAnalytics.js` (le rapport W3 citait `services/` — n'existe pas) ; `sendNow()` ~:200.
- **Root-cause** : `navigator.sendBeacon` ne peut pas porter le Bearer Sanctum → 401 garanti, et `if (ok) return true` (ok = accepté par le browser, pas 2xx) = perte définitive.
- **Fix** : sendBeacon + fallback fetch supprimés ; transport unique axios (`window.axios` = intercepteur d'auth kiosk, fallback module) ; tout échec réseau/non-2xx → `enqueue()` (queue FIFO locale, drain auto). `KioskMachineLoginController` **non touché** (rotation token = single-session voulu).
- **Test** : `tests/js/kioskAnalytics.spec.js` +2 (beacon jamais utilisé même dispo+`true` ; re-queue sur 401) RED → 9/9 ; sweep analytics/offline 8 fichiers 70/70.
- **Note assumée** : la queue étant en mémoire, un unload pendant un échec perd encore la queue (pré-existant, hors scope W4).

### K6 — Loyalty : 429 brut + a11y
- `KioskLoyaltyComponent.vue` : branche `status === 429` → `kiosk.loyalty_screen.too_many_attempts` (« Trop de tentatives, patientez quelques secondes. », 5 locales) sur la vérif code (:~505) **ET** l'inscription (:~592, même hygiène) ; bouton retour SVG-only → `:aria-label="$t('kiosk.loyalty_screen.back')"` (clé existante) ; touche « del » numpad → `numpad_del_aria` (nouvelle clé, 5 locales).
- **Test** : `tests/js/kioskLoyalty429A11y.spec.js` 4/4 (source-contract + i18n).

### K7 — Cible tactile qty « + » 23px < 24px
- **Root-cause précisée** : le bouton fait 50px ; c'est la corbeille flottante `.kiosk-cart-item-trash` (absolute `top:-8px; right:-8px`, 36px — `KioskCartComponent.vue:1006-1010`) qui recouvre le coin haut-droit du « + » → plus grand rectangle libre 22-23px (= le 23px d'axe).
- **Fix** : `padding-top: 30px` sur `.kiosk-cart-item-controls` (dégagement ≥ 28px de débord corbeille) — pilule qty démarre sous la corbeille, boutons 50px (>44 WCAG 2.5.5) inchangés, layout flex centré absorbe le décalage.
- **Test** : nouveau cas contrat CSS dans `tests/js/kioskA11yTouchTargets.spec.js` RED → 8/8.

### K8 — Promo appliquée : placeholders perdus
- `fr.json:1682` : `"Code promo appliqué"` → `"Code promo {code} appliqué (−{amount})"` ; clé EN créée (absente — fallback) `"Promo code {code} applied (−{amount})"`. ar/de/bn n'ont pas le bloc `applied` (fallback fr, borne FR-locked).
- **Test** : `tests/js/kioskPromoAppliedI18n.spec.js` 2/2 (placeholders + interpolation vue-i18n réelle).
- **Incident maîtrisé** : un perl `\x{2212}` a mojibaké `en.json` (391 lignes) — détecté au commit-stat, restauré `HEAD~1`, ré-appliqué en bytes littéraux, commit **amendé** `b26595c7e` (3 fichiers, +43) ; `grep "Ã©|â¬"` = 0 sur les 5 locales.

### K9 — Drawer a11y : section THÈME morte + titre stale
- **Kill-switch vérifié** : `resources/css/kiosk-fallback.css:17-18,33+` masque `.ks-theme-toggle` + force les variables light (owner mandate light 100%) → section THÈME = zone morte.
- **Fix** : section retirée de `ds/KsA11ySettings.vue` (+ nettoyage import `KsThemeToggle`, computed `theme`, `selectTheme`, `themeHeadingId` ; `kioskSettings/setTheme` store conservé) ; commentaire de réintroduction sous gate owner. Titre `kiosk.a11y.title` « Accessibilité & langue » (stale depuis ADR-007) → « Accessibilité » (5 locales). **Aucun changement au kill-switch CSS.**
- **Test** : `tests/js/kioskA11ySettingsDrawer.spec.js` +2 RED → 11/11 ; FR-lock/telemetry sentinels verts.

### K10 — Offline payment : « Network Error » brut
- **Composant vérifié** : `KioskPaymentComponent.vue` (NON-frozen — pas dans la liste §7) ; catch de `confirmPayment()`.
- **Fix** : détection `isNetworkError` (`!err.response && (ERR_NETWORK | 'Network Error' | err.request)` — n'attrape PAS TPE_TIMEOUT/KIOSK_QUOTE_NO_TOKEN, plain Errors sans `request`) → message FR `kiosk.pay_screen.network_lost` (« Connexion perdue. Votre commande n'a pas été envoyée. », 5 locales) + `router.push kiosk.error.network` (route vérifiée `kioskRoutes.js:259-260` ; écran avec CTA Réessayer + appel staff, **aucun reset panier**) avec `return` AVANT `paymentFailureCount += 1` (coupure réseau ≠ refus TPE → pas d'escalade payment-refused).
- **Test** : `tests/js/kioskPaymentNetworkError.spec.js` 5/5 (source-contract + route + i18n + ordre branche réseau/compteur) ; 5 specs paiement adjacents 26/26.

### K11 — Toast aria-label hardcodé
- `KioskToastComponent.vue:23` → `:aria-label="$t('kiosk.a11y.close')"` (clé existante 5 locales). Axe kiosk sweep 5/5.

### K12 — Seeder data EN
- `database/seeders/MenuSeeder.php:505` `'Upsell item'` → `'Article complémentaire'` (`php -l` OK). **Images cassées Boisson/Frites Seules = DATA DB opérante → owner gate, DB non touchée** (conformément au brief).

---

## Tripwire frozen — PREUVE

```
git diff --stat 94fd44a08..HEAD -- \
  KioskWizardComponent.vue KioskAppComponent.vue KioskUpsellComponent.vue \
  PaymentComponent.vue PosV5TrancheRow.vue \
  public/js/pos-wizard.js public/css/pos-wizard.css admin-pos-v4.blade.php \
  app/Services/Fiscal/ PricingService.php BranchScope.php \
  IdempotencyKeyMiddleware.php OrderStateMachine.php ComposerProfileController.php
→ SORTIE VIDE (0 fichier, 0 ligne) — EXIT 0
```
27 fichiers touchés au total (11 src non-frozen + 5 locales + 11 tests) — liste complète dans `git diff --name-only 94fd44a08..HEAD`. `ComposerProfileController.php` (LOCK job parallèle) non touché.

---

## Vérifications finales

| Gate | Résultat |
|---|---|
| Vitest sweep complet `tests/js` | **2196/2201 verts, 3 skipped, 2 fails = sentinels bundle-freshness (`appBundleFreshnessSentinel`, `posAppBundleFreshnessSentinel`) — ATTENDUS pré-rebuild** (npm build interdit à ce healer, rebuild Mix central après W4 ; même état que W2 pré-rebuild) |
| PHPUnit `tests/Feature/Kiosk/` | **34/34** (85 assertions) |
| PHPUnit `tests/Feature/Services/Menu/` | **29/29** (328 assertions) |
| DB test | `.env.testing` → `foodking_test` vérifié AVANT tout run |
| JSON locales | `JSON.parse` OK ×5 + grep mojibake = 0 |
| `php artisan` / `npm build` / push | non exécutés (interdits) |

## Suites pour l'orchestrateur
1. **Rebuild Mix central** (les 2 sentinels bundle-freshness reverdiront) puis re-capture visuelle des surfaces borne (prix `2,00 €`, overlay inactivité, fidélité, panier, paiement offline).
2. K4 backend : le filtre s'applique au prochain rebuild du snapshot menu (`snapshot_version` inchangé par ce fix — une invalidation cache menu peut être nécessaire sur l'environnement servi).
3. Owner gates restants hérités de W3 (orange #F4501E contrastes, wizard frozen P2-2, images DATA DB K12).
