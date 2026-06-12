# W-REM — RED adversarial, voie R2-central (i18n/format FR + drawer)
Date : 2026-06-12 · Adversaire : RED R2 (read-only code) · Branche : `release/v1-integration-2026-06-12`
Span audité : `75d02c628..d173939f9` · Commits R2 : `1fb277727`, `43130e2ad`, `ad5cb85fa`, `3c2ab924c`, `fe9199aeb`, `d173939f9`

## Méthode
- Re-run de CHAQUE test par moi-même (Vitest spec par spec + PHPUnit ciblé, DB `foodking_test` vérifiée DEVDB-GUARD).
- `git diff <c>^..<c> --stat` sur les 6 commits (scope-minimal, frozen, cross-voie).
- Frozen-diff span complet `75d02c628..HEAD` sur les 15 fichiers §7 → **0 ligne** (sortie vide).
- Re-preuve RÉELLE sur :8770 (token admin tinker créé puis révoqué, x-api-key .env.e2e) + cross-check DB `foodking_e2e` + lecture/analyse des 3 captures commitées.
- Sweep régression : **66 fichiers spec language-dependent (tous ceux qui importent en/fr.json) = 612 passed / 2 skipped pré-existants / 0 failed**.
- Spot-check redis db5 : `LLEN queues:default` = 0 (db5 et db0) — aucun backlog broadcast fuité (vérif primaire = RED R1).

## Verdicts par fix

### 1. T-R2.0/Q-2 (D-B2) montants online-orders + statut « Acceptée » — **CONFIRMED**
- Re-run : `onlineOrdersMoneyFr.spec.js` → **7/7 verts** (re-exécuté moi-même).
- Diff `1fb277727` : scope exact — `formatPrice(order.total_amount_price)` via mixin partagé WT-D-R1-F4 ; clé statut `label.accepted` swappée dans les DEUX arrays d'affichage (List + Show) ET le filtre dropdown ; le dropdown d'ACTION de ShowComponent garde le verbe `label.accept` (vérifié ligne 389 du fichier courant). `fr.json label.accept='Accepter'` / `label.accepted='Acceptée'` vérifiés par script indépendant.
- Observation NON-bloquante : `en.json label.accepted='Acceptée'` (valeur FR dans le fichier EN) — suit la dérive PRÉ-EXISTANTE du fichier (`"accept": "Acceptée"`, `"from_date": "Date début"` déjà là avant R2) ; V1 FR-only, pas une régression R2.
- Live : .vue non visible avant rebuild Mix (bundle 18:36 < commit 18:58) — spec-proof = bon niveau de preuve, à re-vérifier visuellement en W-VAL.

### 2. T-R2.0/Q-7 (DB5-05 + KPI 1280) coupons REMISE typée — **CONFIRMED**
- Re-run : `couponDiscountTyped.spec.js` → **8/8 verts** (module pur 12,00 €/12 %/12,5 %/no-NaN + wiring composant `formatTypedDiscount(coupon.discount, coupon.discount_type)` + interdiction du `{{ coupon.flat_discount }}` brut).
- « Sentinelle CSS KPI » élucidée : ce n'est PAS un edit CSS (le commit ne touche pas ItemListComponent.vue) mais une assertion read-only dans le même spec qui VERROUILLE le CSS pré-existant (`white-space: normal` + `overflow-wrap: break-word` + `hyphens: auto` dans `.catalog-control-plane__metric small`) — cohérent avec « déjà healé sur ce tronc » (A-009/NEW-R4-04 mergé en W-INT, donc DANS le bundle 18:36).
- Capture `r2-q7-items-kpi-1280.png` lue/analysée : « INDIS-PO-NIBLES » wrap hyphéné 3 lignes, valeur 0 visible, zéro clipping, layout intact.

### 3. T-R2.1 (163 clés label.* fr) — **CONFIRMED**
- Re-run : `labelEnFrParity.spec.js` → **2/2 verts** (ratchet réel : scan complet en→fr, échouerait sur toute future clé non-miroir).
- Vérif INDÉPENDANTE par script python : en label = 895, fr label = 1023, **manquantes = 0**, **orphelines = 128** (le « 153 » du plan était bien un compteur dérivant — décompte réel confirmé).
- Qualité des 165 lignes insérées échantillonnée sur ~35 clés : français propre (« Clé secrète Firebase », « Logo de l'écran de démarrage », « Clé de hachage Easypaisa »), pas de franglish, noms de gateways conservés tels quels (conforme « pas de polissage gateway V1 »).
- `R2_FR_ORPHAN_LABEL_KEYS.md` existe, liste les 128, **aucune suppression** (vérifié : fr.json ne perd aucune clé dans le diff).
- Sweep régression 66 specs language-dependent : 612/0 — l'insertion massive n'a rien cassé.

### 4. T-R2.2 (datepickers FR, 20 tags / 15 fichiers) — **CONFIRMED**
- Re-run : `datepickerFrLocale.spec.js` → **4/4 verts** ; sentinelle = vrai ratchet (walk récursif de TOUT `resources/js/components/admin`, 0 tag sans `locale="fr"`, 0 `:is24="false"` résiduel).
- Display-only vérifié : le seul hit « model-type » du diff entier est le COMMENTAIRE du spec ; hunks = ajout `locale`/`format`/`:is24` uniquement, `v-model` + `@update:modelValue` intacts (TimeSlot vérifié hunk complet : `time-picker` pré-existant).
- Justification inline-props acceptable : pattern déjà CI-locké par `cashFiltersFrDatepicker.spec.js` (re-run par moi : 2/2 verts).
- L'écart « 17 fichiers » plan vs 15 réels (2 imports morts sans tag) est documenté et plausible.

### 5. T-R2.3/Q-1 (D-B3-01 caisses livreur) — **CONFIRMED**
- Re-run : `DeliveryBoyCashSessionControllerTest` → **20/20 OK (60 assertions)**.
- Diff backend propre : eager-load `['deliveryBoy','branch']` index ET show (anti-N+1), resource ships `delivery_boy_name`/`branch_name` via `optional()`, relation `branch()` additive avec note BranchScope-exempt correcte (Branch = self-reference exemptée, conforme §9).
- **Re-preuve live :8770 par moi-même** : `GET /api/admin/delivery-boy/cash-sessions` → `"delivery_boy_name": "Livreur E2E"`, `"branch_name": "Le Cayenne (principal)"` sur les rangées qui rendaient « 10 »/« 1 ».

### 6. T-R2.3/Q-5 (DB5-03/04 outbox) — **CONFIRMED**
- Re-run : `outboxFrFormatConfirm.spec.js` → **2/2 verts** ; `observabilityOutboxRoute.spec.js` → **6/6 verts** (pas de régression montage).
- Spec non-vacuous : interdit tout `toLocaleString()` nu + assertion d'ORDRE (confirmation AVANT le POST drain-failed).
- `appService.confirmation(message, title, confirmText)` : signature vérifiée dans appService.js (resolve confirm / reject cancel) — le `catch → return` annule bien sans POST ; même garde que l'envoi push masse, claim exact.

### 7. T-R2.3/Q-6 (F4 TypeError + F1 franglish) — **CONFIRMED**
- Re-run : `settingsKioskMachineGuards.spec.js` → **4/4 verts**.
- Diff : computed `userOptions` fallback `#id — compte hors liste (sans rôle)` (lecture seule, n'altère pas la liste store) + `.catch` toast FR sur les 2 dispatchs ; `alertService` était DÉJÀ importé (ligne 122) — pas de ReferenceError.
- `fr.json ios_app_link = 'Lien application iOS'` vérifié par script.

### 8. T-R2.3/Q-8 (D-B1 top clients) + D-B3-03 téléphones — **CONFIRMED**
- Re-run : `TopCustomersNonZeroSentinelTest` → **2/2 OK (5 assertions, dont exclusion du miroir-refund)** ; suite `tests/Feature/Dashboard/` → **30/30 OK** (pas de régression module).
- Diff : `whereHas('orders', …)` clauses STRICTEMENT identiques au `withCount` (whereNull parent_order_id + branch) — portable SQLite, scope-minimal.
- **Re-preuve live + DB par moi-même** : `foodking_e2e` contient 2 clients à 0 commande (id 28, 29 « E2E Client EDIT ») → `GET /api/admin/dashboard/top-customers` ne retourne QUE « Client passage » order=1419. Le delta 1419 vs 1420 bruts DB = exclusion du miroir-refund `parent_order_id` (héritage heal 2026-06-01), cohérent.
- Téléphones : `phoneFormatFr.spec.js` → **8/8 verts** ; helper sain (dé-glue `+330…`, groupes de 2, non-FR `+32 …`, jamais NaN/undefined) ; **exactement 15 surfaces** portent `adminPhoneMixin` (grep), `PosOrderShowComponent` INTOUCHÉ (absent de tous les stats R2 — exclusion voie caisse respectée et documentée).

### 9. T-R2.4 (D-B1-01 drawer ingrédients menteur) — **CONFIRMED**
- Re-run : `IngredientUsageDrawerParityTest` → **3/3 OK (17 assertions)** ; suite `tests/Feature/Ingredients/` → **22/22 OK**.
- Diff : fallback by-name PUREMENT additif (`!$extra` early-return conservé, chemin `group_label`→wizard-steps INTACT — le 3e test le verrouille), `withTrashed` nommage « (archivé) », dédup par item, symétrie count/rows.
- **Re-preuve live :8770 par moi-même** : `GET /api/admin/ingredients/extra:1/usage` → `used_by_count: 8` + 8 owners NOMMÉS (« Boursin (archivé) », « Fromage supplémentaire (archivé) », …). Capture `r2-t24-drawer-jambon-parite.png` lue/analysée : drawer « Jambon de dinde — Utilisé dans 8 produit(s) » + 8 cartes nommées = parité liste/drawer visuelle.
- Edge théorique NON-bloquant : la liste compte les ROWS (`$group->count()`) et le drawer dédup par ITEM — si un même item portait 2 rows homonymes sans group_label, 8≠N réapparaîtrait. Données réelles : parité 8=8 prouvée ; à garder en tête si un seed crée des doublons.

## Scope / discipline
- **Frozen §7 : 0/15 touché sur le span complet** (`git diff 75d02c628..HEAD --stat -- <15 fichiers>` = vide).
- Cross-voie : intersection fichiers R2 ∩ (R1+R3) = `en.json`/`fr.json` UNIQUEMENT — et c'est R3 (`d673c4226`, clé kiosk `continue_menu`) qui a écrit dans le fichier-domaine de R2, pas l'inverse ; hunks additifs, clés disjointes, aucun conflit. R2 est resté dans sa voie.
- Commits : chemins explicites, 1 message/fix avec IDs findings — conforme.
- Tooling `tools/i18n/fill-missing-fr-labels.py` : dev-only, hors runtime, acceptable.
- Token tinker RED révoqué post-audit.

## Réserves transmises à W-VAL (aucune ne refute)
1. **Bundles stale** : `public/js/app.js` (18:36) prédate TOUS les commits R2 (≥18:58) → les fixes .vue (Q-2, Q-7-coupons, datepickers, Q-5 UI, Q-6) sont invisibles au DOM :8770 jusqu'au rebuild Mix groupé. W-VAL DOIT re-vérifier visuellement ces 5 surfaces post-rebuild (la sentinelle fraîcheur + specs suffisent d'ici là).
2. `en.json` contient des valeurs FR (dérive pré-existante, aggravée d'1 clé miroir volontaire) — arbitrage backport/nettoyage EN = décision séparée hors V1 FR-only.
3. Les 128 orphelines fr.json restent à arbitrer (documentées, aucune supprimée — correct pour W-REM).
4. Claims « RED d'abord » : vérifiés LOGIQUEMENT (les assertions échouent mécaniquement sur l'état pré-fix : tags sans locale, clés absentes, `{{ coupon.flat_discount }}`, ghost 0-commande, fallback []) — non re-exécutés sur l'état ancien (re-checkout source = interdit à l'adversaire read-only).

## VERDICT GLOBAL : **9/9 CONFIRMED — voie R2 tenue, aucune réfutation.**
