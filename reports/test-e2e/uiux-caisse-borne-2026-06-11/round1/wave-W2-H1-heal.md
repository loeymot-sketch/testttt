# Wave W2 — HEALER H1 — GOAL UIUX caisse+borne (2026-06-11)

Worktree : `.claude/worktrees/release-v1-2026-06-10` (branche `release/v1-2026-06-10`)
Base tripwire : `178114a5f` · HEAD post-heal : `fcf530982`
Discipline : TDD (spec red→green quand unit-testable), commits par chemins explicites
(`git commit -- <paths>`), 0 `git add .`/`-A`, 0 frozen touché, 0 `php artisan`, 0 build.

## Clusters healés (6/6 — aucun SKIPPED)

### F5 — Bouton « Écran client » inerte — commit `f15d44397`
- **Root-cause confirmée** : `PosV5Button.vue:6` bindait `:href="tag === 'a' ? href : null"`.
  Pour `as="router-link"`, le `href: null` tombait en fallthrough attr sur le `<a>` rendu
  par router-link et **écrasait le href calculé depuis `to`** → ancre sans href, clic no-op.
- **Fix** : `v-bind="tag === 'a' && href ? { href } : {}"` — `href` ne transite QUE pour un `<a>` brut.
- **Régression vérifiée** : 3 seuls usages `as=` dans le repo (PosComponent.vue:142/155/175),
  tous `as="router-link"` = exactement le chemin réparé ; aucun `as="a"`.
- **Test** : `tests/js/posV5ButtonHrefRouterLink.spec.js` (3 tests, red→green prouvé).

### F1 — Formats monétaires FR (sans toucher les frozen) — commit `0320d7996`
- `resources/js/services/appService.js:71` : `currencyFormat` passait de `toFixed + concat`
  (EN-US `€2.50`/`2.50€`) à `Intl.NumberFormat('fr-FR', {style:'currency', currency:'EUR'})`
  en honorant le paramètre `decimal`. Le modal de paiement POS **frozen**
  (`PaymentComponent.vue:535` délègue à appService) est corrigé **sans toucher le fichier frozen**.
  Grep exhaustif des ~56 usages : tous en interpolation template, **aucun re-parse** de la sortie.
  Surfaces web/table également alignées FR (mandat FR V1, cohérent backend `AppLibrary` fr_FR).
- `PosLoyaltyRedeemModal.vue:113,313` : `−2.50 €` → `formatPriceFr()` (helper canonique
  `helpers/formatPrice.js`) → `−2,50 €`. **Format-only, zéro layout** (modal design-LOCKED).
- `ReceiptComponent.vue:520` (`formatReceiptAddonPrice`) : seul montant frontend `toFixed` du
  ticket → `formatPrice()` ; cohérent avec les `*_currency_price` backend (fr_FR « 2,50 € »).
- **Tests** : `tests/js/appServiceCurrencyFormatFr.spec.js` (5 tests TDD red→green) ;
  2 assertions stale EN-US mises à niveau FR (mêmes valeurs, format) :
  `posLoyaltyRedeemModal.spec.js` (/2,50/) et `sentinels/receiptAddonsRenderingSentinel.spec.js`
  (`+1,20&nbsp;€` — le sentinel NF525 line_total≠catalog_price reste intact).

### F2 — Dates FR — commit `8a0a78a75`
- `PosOrderListComponent.vue` + `HistoriqueListComponent.vue` : Datepicker @vuepic v3.6.8
  (défaut MM/dd/yyyy EN-US vérifié dans le dist) → `locale="fr" format="dd/MM/yyyy"
  select-text="Valider" cancel-text="Annuler"` (props confirmées présentes dans la version
  installée). Preset « Cette année » **dédoublonné** (entrée plain supprimée, version slot
  #yearly conservée) dans les 2 composants.
- `CashSessionReportListComponent.vue:21,25` + `CashOverviewComponent.vue:30,34` : inputs
  natifs `type="date"` (mm/dd/yyyy navigateur) remplacés par le même vue-datepicker FR avec
  `model-type="yyyy-MM-dd"` → `filters.from/to` restent des strings ISO, **contrat API +
  sérialisation route-query inchangés** (`params.from/to`, `buildRouteQuery`).
- **Test** : `tests/js/cashFiltersFrDatepicker.spec.js` (2 tests : 0 input natif restant,
  4 datepickers FR, modèle ISO). Rendu popup/typo : **visuel W2 re-capture**.

### F3 — Statuts commande (show) — commit `9d863a78f`
- `PosOrderShowComponent.vue:567-575` : `orderStatusEnumArray` n'avait pas
  PENDING(1)/CANCELED(16)/REJECTED(19) → badge + libellé vides sur le show d'une commande
  pending. Aligné sur le mapping complet FP-25 de la liste (mêmes clés `label.pending`,
  `label.canceled`, `label.rejected` — présentes en FR/EN).
- **Test** : `tests/js/posOrderShowStatusEnumArray.spec.js` (enum-complet : CHAQUE valeur de
  `orderStatusEnum` doit avoir un libellé — sentinel anti-régression si l'enum grandit ;
  red→green prouvé).

### F4 — Tracker borné à aujourd'hui — commit `46e751943`
- `PosOrdersTrackerComponent.vue` `_todayRange()` (from=to=jour courant) → les ACTIVES d'hier
  soir disparaissaient à minuit. **Choix scope-minimal documenté** :
  - fetch élargi à 48h : `_activeWindowRange()` from=J-1, to=J (même param API
    `from_date`/`to_date`, per_page=100 newest-first ⇒ actives récentes servies en priorité) ;
  - la lane « Terminées » (DELIVERED) garde sa **sémantique JOUR** via filtre client
    `_isCreatedToday()` — les livrées d'hier n'inondent pas le board.
- **Tests** : `tests/js/posOrdersTrackerActiveWindow.spec.js` (3 tests : fenêtre 48h,
  PREPARING d'hier conservée, DELIVERED d'hier filtrée/du jour conservée — red→green) ;
  spec existante `PosOrdersTrackerComponent.spec.js` toujours verte (6 tests).

### F6 — Toasts/feedback — commit `fcf530982`
- `PosCounterCollectModal.vue:589` : sur 429, le toast local relayait le message backend brut
  anglais « Too Many Attempts. » EN DOUBLON du toast global FR (bootstrap.js bucket 'rl',
  avec Retry-After réel). **Géré localement** : branche 429 → pas de second toast, bouton
  libéré (`submitting=false`). Résultat : 1 seul toast, FR, avec délai exact. bootstrap.js
  non édité (consigne).
- `EncaissementComponent.vue:272` : toast « Commande N° encaissée » au numéro VIDE supprimé —
  c'était un doublon de celui de `PosCounterCollectModal:554` qui porte le vrai numéro.
  Refresh de la file conservé ; import `alertService` devenu mort nettoyé.
- `PosOrderShowComponent.vue:777,781` : `$t('label.error')` → clé absente. Ajouté
  `label.error` = « Une erreur est survenue » dans `fr.json` + parité `en.json`
  ("An error occurred"). JSON re-parsé OK.
- **Test** : `tests/js/toastFeedbackFr.spec.js` (3 tests red→green). Toast 429 lui-même :
  **visuel W2 re-capture** (déclenchement rate-limit non unit-testable proprement).

## Vitest

Commandes ciblées par cluster (toutes vertes, détails ci-dessus). Sweep final complet :

```
npx vitest run --reporter=basic
→ Test Files  2 failed | 315 passed (317)
→ Tests       2 failed | 2149 passed | 3 skipped (2154)
```

Les **2 seuls échecs = sentinels de fraîcheur de bundle**
(`appBundleFreshnessSentinel`, `posAppBundleFreshnessSentinel`) — **attendus** : j'ai modifié
des sources et le rebuild est centralisé APRÈS W2 (npm build interdit à ce healer).
Ils repasseront verts au rebuild central. Zéro autre régression sur 2 149 tests.

## Tripwire frozen (preuve)

```
$ git diff --stat 178114a5f..HEAD -- public/js/pos-wizard.js public/css/pos-wizard.css \
    resources/views/admin-pos-v4.blade.php \
    resources/js/components/admin/pos/PaymentComponent.vue \
    resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
 public/js/pos-wizard.js | 336 +++++++++++++++++++++++++++++++++++++++++++++++-
```

⚠️ Le delta `pos-wizard.js` du RANGE n'est **pas de moi** : un job parallèle (wizard Phase C,
LOCK contresigné) a commité dans ce même worktree pendant W2 :
`c1fc7aa52 feat(pos): renderer générique composer dans pos-wizard.js [FROZEN §7]`.
Preuve d'attribution :

```
$ git log 178114a5f..HEAD --format='%h %s' -- public/js/pos-wizard.js
c1fc7aa52 feat(pos): renderer générique composer dans pos-wizard.js [FROZEN §7] — clôt GAP#1

$ for c in f15d44397 0320d7996 8a0a78a75 9d863a78f 46e751943 fcf530982; do
    git show --stat $c -- <les 5 frozen + Fiscal/* + PricingService + BranchScope \
      + IdempotencyKeyMiddleware + OrderStateMachine + ComposerProfileController>; done
→ sortie VIDE pour les 6 commits (zéro ligne frozen, ComposerProfileController intouché)
```

**Mes 6 commits = 0 ligne frozen.** 22 fichiers touchés, tous non-frozen (liste exhaustive
dans les `git show --name-only`).

## Incidents partagés (worktree commun)

1. Le 1er commit F1 (`54fb57aba`, annulé) a balayé 3 fichiers **pré-stagés par le job
   parallèle** (`master.blade.php`, 2 specs posWizard*) → reset --soft + restore --staged +
   re-commit propre `0320d7996`. Tous les commits suivants utilisent `git commit -- <paths>`
   (immunisé contre l'index partagé). Le travail du job parallèle est préservé intact.
2. `public/js/pos-wizard.js` apparaissait aussi modifié non-stagé dans le working tree
   (travail du job parallèle, depuis commité par lui en `c1fc7aa52`) — jamais touché ni commité par moi.

## Récap commits H1

| Cluster | Commit | Test |
|---|---|---|
| F5 écran client | `f15d44397` | posV5ButtonHrefRouterLink.spec.js 3/3 |
| F1 monnaie FR | `0320d7996` | appServiceCurrencyFormatFr 5/5 + 2 specs mis à niveau |
| F2 dates FR | `8a0a78a75` | cashFiltersFrDatepicker 2/2 + visuel re-capture |
| F3 statuts show | `9d863a78f` | posOrderShowStatusEnumArray 2/2 |
| F4 tracker 48h | `46e751943` | posOrdersTrackerActiveWindow 3/3 + tracker existant 6/6 |
| F6 toasts | `fcf530982` | toastFeedbackFr 3/3 + visuel re-capture (429) |
