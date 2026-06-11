# Wave W2 — HEALER H2 (lot 2) — rapport de heal
2026-06-11 · worktree `release-v1-2026-06-10` · branche `release/v1-2026-06-10` · heal sérialisé (post-H1)

## Verdict
**7/7 clusters traités — 21 fixes appliqués, 2 sous-items SKIPPED documentés.**
Vitest sweep complet : **2162 passed / 3 skipped / 2 failed** — les 2 fails sont les
sentinels bundle-freshness attendus pré-rebuild central (`appBundleFreshness`,
`posAppBundleFreshness` : sources plus récentes que les bundles ; `npm build`
interdit aux healers — état identique à celui rapporté par H1).
PHPUnit ciblé G5 : **24/24** (PosDineInServerGate 3 + NullableTotal/TerminalIdWireIn/
NoClientTotals/SplitPaymentE2E 21).
**Tripwire frozen = 0 ligne** sur mes 7 commits (preuve §Tripwire).

## Commits (1/cluster)
| Cluster | SHA | Titre |
|---|---|---|
| G1 | `7d6426271` | menu profil navbar → role="dialog" popup |
| G2 | `31d679038` | a11y POS target-size + gris + labels vue-select |
| G3 | `165d1db46` | floorplan FR + états explicatifs dine-in off |
| G4 | `f43633a1a` | show commande : variations/empty/Client borne |
| G5 | `1f8e9434e` | messages 422 PosOrderRequest FR (13 textes) |
| G6 | `8a340e630` | modal encaissement : sticky CTA/casse/numpad/héro |
| G7 | `3351d1b97` | petits P3 mécaniques (5 fixes, 1 skip) |

## Détail des fixes (file:line · test)

### G1 — a11y critical global : menu profil navbar ✅
- `resources/js/components/layouts/backend/BackendNavbarComponent.vue:117-125` —
  `role="menu"` → `role="dialog"` + `aria-haspopup="dialog"` sur le trigger (l.106).
  Le panneau contient une carte profil (figure/avatar/file-input/email) → ne peut
  JAMAIS être un `menu` valide (axe `aria-required-children` CRITICAL, 100% des pages).
- `role="menuitem"`/`tabindex="-1"` retirés des 5 liens/bouton (l.157-187) — liens
  nativement tabbables ; `<nav role="none">` → `<nav>` natif.
- Helper clavier `openProfileMenuAndFocusFirst` requery `.paper-link` (l.441, était
  `[role="menuitem"]`). Zéro changement visuel.
- Test : `tests/js/uiuxW2H2HealSentinel.spec.js` bloc G1 (red→green).

### G2 — a11y POS : target-size + gris faibles + labels orphelins ✅ (1 sous-item stale)
- `PosComponent.vue:4694-4700` — `:deep(.db-field-control .vue-input input) { min-height: 24px; }`
  (l'input vue-next-select fait 19.5px : la lib reset padding + font 0.8rem ; l'enveloppe
  `.db-field-control` reste h-10/40px → 0 layout shift). WCAG 2.5.8.
- `PosComponent.vue:4860-4866` — `.pos-shortcuts__refresh` gris `#9a9a9a` (2.81:1) →
  `var(--pos-v5-muted, #6b6b6b)` ≈5.3:1 (token du sibling `__empty` ; `--pos-v5-muted-2`
  n'est défini nulle part — le fallback ÉTAIT la couleur rendue).
- Labels orphelins vue-select (vue-next-select ÉCRASE l'id passé par son id interne
  `vsN-combobox` → `label[for]` pointe dans le vide ; `aria-label` passe par `$attrs`
  et atterrit sur le div `role="combobox"`) :
  - `PosOrderListComponent.vue:34-41` `searchStatus` + `:53-58` `user_id`
  - `HistoriqueListComponent.vue:45-50` `searchOrigin` + `:55` `searchStatus` + `:69` `searchPayment`
- **NOT FOUND (stale)** : « `.db-btn > span` encaissement #9a9a9a » — re-greppé :
  l'unique `.db-btn` d'EncaissementComponent (l.16) est blanc sur `bg-primary`,
  aucun #9a9a9a dans le fichier ni dans les CSS globales. Finding d'audit périmé.
- Test : bloc G2 du sentinel (regex CSS + aria-labels).

### G3 — Floorplan propre (dine-in désactivé V1) ✅
- `FloorplanComponent.vue:10` — « tables » pluriel FR conditionnel ; `:51` — « seats » →
  « place(s) » FR.
- `:34-43` — états explicatifs FR sur le canvas blanc : `data-testid="floorplan-dinein-off"`
  « Le service en salle est désactivé. » quand `pos_dine_in_enabled=false` (computed
  `dineInEnabled` = même lecture stricte que `PosComponent.vue:1905` ; le flag EST exposé
  au front via `SettingResource.php:124` / store `frontendSetting/lists`, dispatch
  fallback en accès direct à la page) ; sinon « Aucune table configurée. » si 0 table.
- Styles `__empty` dashed neutres tokens V5 (pas d'orange marque).
- Test : bloc G3 du sentinel.

### G4 — Show commande : polish P2 ✅
- `PosOrderShowComponent.vue:224-229` → méthode `variationsText()` (l.668-680) :
  « nom: valeur » seulement si les DEUX existent, sinon le seul présent, entrées
  vides filtrées — fini « Poulet Mariné: , Algérienne: ».
- État vide `data-testid="order-items-empty"` (l.258-263) : icône + « Aucun article
  dans cette commande. » (zone Détails Commande blanche à 0 article).
- Commande borne : computed `isKioskCustomer` (l.494-499, convention identique à
  `EncaissementComponent.customerName` : `source_surface==='kiosk'` primaire + filet
  `/kiosk/i` sur le nom brut) → en-tête client « Client borne »
  (`$t('label.client_borne')`, clé existante) + email/téléphone machine masqués.
- Tests : unitaires réels (import du composant, `variationsText`/`isKioskCustomer`
  appelés sur fixtures) — bloc G4 du sentinel.

### G5 — Backend messages 422 FR ✅
- `app/Http/Requests/PosOrderRequest.php` — **13 textes** traduits (l.167, 173-177,
  197, 207, 234, 250 + les 6 de `messages()` l.263-272) : dine-in désactivé, type de
  commande désactivé, montant reçu < total, motif remise (3 car. min), terminal carte
  requis / indisponible, 4 derniers chiffres carte, n° transaction, note paiement,
  min/max digits, montant reçu requis, table requise. **AUCUNE règle changée** (diff
  = chaînes uniquement). Au passage l'EN d'origine disait « The cart must contain… »
  (typo card→cart) — plus de typo en FR.
- Couplage chaîne : seul `tests/Feature/PosDineInServerGateTest.php:117` assertait
  « Dine-in is disabled » → mis à niveau « Le service en salle est désactivé ».
  Aucun string-match côté frontend ni pos-wizard.js (greppé).
- Tests : `php -l` OK ; PHPUnit `PosDineInServerGateTest` 3/3 +
  `PosOrderRequestNullableTotal|TerminalIdWireIn|PosOrderRequestNoClientTotals|SplitPaymentEndToEnd` 21/21.

### G6 — Modal encaissement : CTA sous le fold + polish ✅
- `PosCounterCollectModal.vue:838-852` — `.cc-modal-footer` **sticky bottom:0** dans
  le scroll du modal (fond `--pos-v5-surface` + bleed marges négatives sur le padding
  20/24px — design inchangé, juste l'ancrage). CTA « Confirmer & Imprimer ticket »
  toujours visible <900px de hauteur.
- `:641-648` — `text-transform: capitalize` du titre supprimé (Title Case non-FR).
- `PosV5Numpad.vue:53-65` — doublon **confirmé au render** : le template `v-for` sur
  16 keys rendait 2 boutons ⌫ (`back`/`back2`) + 2 boutons C (`clear`/`clear2`)
  empilés avec bordure entre. Dédupliqué : 1 seule touche de chaque avec `span: 2`
  (classe existante `--span-2` `grid-row: span 2` — layout 4×4 préservé, auto-placement
  vérifié). NB : PaymentComponent (frozen) consomme cet atome NON-frozen — son numpad
  rendu gagne la même déduplication sans qu'aucun fichier frozen ne soit touché.
- `:701-712` — `.cc-hero-value` : « 3 , 80 € » (Rubik Mono One = virgule/espace pleine
  chasse) → police standard du modal + `font-variant-numeric: tabular-nums` (chiffres
  à chasse fixe pendant la saisie, virgule normale).
- Tests : bloc G6 du sentinel + suites existantes `posCounterCollectModalSentinel` /
  `counterCollectFrDecimalSentinel` / `f4DynamicButtonMatrixSentinel` **42/42**
  (les specs numpad utilisent `length > 0`, compatibles dédup).

### G7 — Petits P3 mécaniques ✅ (1 SKIP)
- **Anti-race bouton Caisse** : `PosComponent.vue:2448-2461` `openCashSessionDialog()`
  re-dispatch `cashDrawer/loadCurrentSession` AVANT affichage (le dialog `watch session`
  re-résout son mode `open`→`active` à l'arrivée du fetch — fini « Ouvrir » alors
  qu'une session est OPEN ; fail-soft réseau).
- **Breadcrumb floorplan** : `posRoutes.js:32` `breadcrumb: "floorplan"` rendu via
  `$t('menu.'+…)` (BreadcrumbComponent:12) → clé `menu.floorplan` ajoutée :
  fr.json « Plan de salle » + en.json « Floor plan » (parité).
- **`PosV5QtyStepper.vue:20`** : fallback aria-label `'quantity'` → `'quantité'`.
- **En-tête show commande** : computed `orderDateTimeFr` (« 01:41, 10-06-2026 »
  pattern backend `AppLibrary::datetime` = TIME, DATE → « 10/06/2026 à 01:41 » ;
  pattern inattendu → chaîne brute) + **N° borne** `queue_number` (ex. A0042) ajouté
  à l'en-tête, convention `N°…` identique liste/OSS/ticket.
- **Badge attente encaissement** : `EncaissementComponent.vue:248` « 22h58 » (lisible
  comme heure de la journée) → « **attente 22h58min** » (durée explicite, option
  proposée par l'audit).
- **SKIPPED — `window.prompt` mise en attente (`PosComponent.vue:3493`)** : aucun
  modal générique de saisie texte n'existe dans le fichier ni dans
  `components/admin/components/` (seuls des dialogs spécialisés : CashDrawerSession,
  CounterCollect, ParkedOrders, addCustomer). En créer un = nouveau framework de
  modal — explicitement hors scope du cluster (« sinon SKIP et note-le »).
- Tests : bloc G7 du sentinel (5 asserts dont 2 unitaires réels sur `orderDateTimeFr`).

## SKIPPED récapitulatif
1. **G2 `.db-btn > span` encaissement #9a9a9a** — introuvable post-re-grep (le bouton
   actuel est blanc sur bg-primary ; aucun #9a9a9a dans le composant ni les CSS
   globales). Finding d'audit stale.
2. **G7 `window.prompt` mise en attente** — pas de modal générique existant ; nouveau
   framework modal hors scope (consigne du cluster respectée).

## Vitest sweep (complet)
```
Test Files  316 passed | 2 failed (318)
Tests       2162 passed | 2 failed | 3 skipped (2167)
```
Les 2 fails = `appBundleFreshnessSentinel` + `posAppBundleFreshnessSentinel`
(bundle plus vieux que les sources éditées — rebuild central requis, `npm build`
interdit aux healers ; état identique au rapport H1 `b30ed0447`).
Mon sentinel dédié : `tests/js/uiuxW2H2HealSentinel.spec.js` **13/13**.

## Tripwire frozen (limité à MES commits)
```
$ git diff --stat 7d6426271^..HEAD -- public/js/pos-wizard.js public/css/pos-wizard.css \
    resources/views/admin-pos-v4.blade.php \
    resources/js/components/admin/pos/PaymentComponent.vue \
    resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
(sortie vide — 0 fichier, 0 ligne)

$ git diff --stat 7d6426271^..HEAD -- app/Services/Fiscal app/Services/Pricing/PricingService.php \
    app/Models/Scopes/BranchScope.php app/Http/Middleware/IdempotencyKeyMiddleware.php \
    app/Domain/Order/OrderStateMachine.php
(sortie vide — 0 fichier, 0 ligne)
```
`ComposerProfileController.php` non touché (job parallèle wizard Phase C LOCK).
