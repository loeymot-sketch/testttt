# Demande d'Ultra-Review Claude (terminal Anthropic Pro) — V1 fonctionnelle FoodKing — 2026-05-02

> **Pour le user** — copier-coller le bloc « Prompt à coller dans le terminal » ci-dessous après avoir lancé `bash scripts/foodking-claude-orchestrate.sh context && bash scripts/foodking-claude-orchestrate.sh audit-brief`. Le bref disque est déjà préparé pour limiter les tokens.

> **Pour Claude (terminal)** — tu es l'auditeur final indépendant avant clôture V1. Le travail Cycles 1–2 est livré (M1 V1 + M2 V1 + M2 V2 — voir détail ci-dessous). Ta mission : un **ultra-review global** qui prouve (ou réfute) que la V1 est prête, identifie les **doublures de code**, les **dérives de synchronisation**, et liste les **fonctionnalités à supprimer** pour livrer une V1 propre.

---

## Périmètre d'ultra-review (5 axes — tous obligatoires)

### Axe 1 — Centralisation catalogue (Single Source of Truth)

**Question primaire :** y a-t-il **doublure d'écriture / lecture** du catalogue entre POS / Kiosk / KDS ?

Fichiers clés à inspecter (FQCN puis lecture ciblée) :
- `app/Http/Controllers/Admin/PosCategoryController.php` — filtre POS-only branch (M1 1.1 commit `87414d967` antérieur)
- `app/Services/Menu/PosMenuProjection.php` — projection unifiée POS (M1 1.7)
- `app/Services/Menu/MenuProjectionService.php` — projection commune
- `app/Services/Menu/KioskMenuService.php` — kiosk-specific
- `app/Services/Catalog/CatalogWarningService.php` — warnings (M1 1.4 + M2 1.5)
- `app/Services/ItemService.php::duplicate` — M2 1.7
- `config/catalog_v15.php` — feature flags par axe

**À prouver :**
1. Toute modification produit ou catégorie déclenche **une seule source d'événement** (`CatalogChanged`, `ItemCreated`, `ItemDeleted`, `ItemAvailabilityChanged`, `ComposerProfileChanged`) avec dispatch `afterCommit`.
2. Les trois surfaces (POS, Kiosk, KDS) consomment la même projection de menu — ou divergence documentée.
3. Aucune autre ligne de code ne fait `Item::query()->...` directement pour bâtir un menu utilisateur (sauf service centralisé).
4. Le drapeau `unified_projection.enabled` du flag `catalog_v15` couvre vraiment tous les chemins legacy ; sinon lister les chemins encore non couverts.

**Sortie attendue :** rapport `reports/audit/CLAUDE_ULTRA_REVIEW_V1_CATALOG_CENTRALIZATION_<DATE>.md` avec :
- Liste exhaustive des points d'écriture catalogue (controller / service / job / command / artisan).
- Liste des points de lecture par surface (POS, Kiosk, KDS, OSS).
- Doublures détectées avec **file:line + recommandation** (supprimer / unifier / déprécier).

---

### Axe 2 — Synchronisation des données (le plus important — explicit user demand)

**Question primaire :** quand un changement survient (stock, prix, disponibilité, catégorie, produit, composer profile), **arrive-t-il vraiment** dans les 3 surfaces, sans race et sans perte ?

Fichiers clés :
- `app/Events/CatalogChanged.php`, `app/Events/ItemAvailabilityChanged.php`, `app/Events/ComposerProfileChanged.php`, `app/Events/StockLevelChanged.php`
- `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php`
- `app/Listeners/PersistCatalogChangedToOutbox.php`
- `app/Listeners/NotifyStockLowOnStockLevelChanged.php` (NEW M2 2.7)
- `app/Console/Commands/StockScanRupture.php` (NEW M2 2.1)
- `app/Services/Menu/AvailabilityService.php` (M2 1.9 atomic UPDATE + M2 2.5 setMaxDailyQty)
- `resources/js/services/PosSyncService.js` (M1 1.7 — fallback polling Echo disconnect)
- `resources/js/composables/useCatalogChangeNotifier.js` (M2 1.3 + 2.3 — kiosk toast)

**À prouver :**
1. **Path A** : Admin modifie un produit → Outbox → Echo broadcast → POS reçoit → re-fetch projection → cache invalidé. Idem Kiosk + KDS.
2. **Path B** : Admin modifie `max_daily_qty` → `AvailabilityService::setMaxDailyQty` flippe `is_available` immédiatement → `ItemAvailabilityChanged` propagé partout.
3. **Path C** : Stock atteint zéro pendant un order → `decrementForOrder` (atomic UPDATE M2 1.9) → flip `is_available` exactement une fois → propagé.
4. **Path D** : Composer profile publié pendant qu'un kiosk a panier ouvert → toast + invalidation step + pruning panier.
5. **Path E** : Echo disconnect → POS fallback polling toutes les 30s. Reconnexion → polling stop, snapshot rafraîchi via Echo.

**Risque clé à challenger :** est-ce qu'un événement peut être **perdu silencieusement** (par exemple, broadcast sans listener si l'utilisateur a quitté la page entre-temps, ou outbox jamais consommé) ? Quel est le RPO (recovery point objective) effectif ?

**Sortie attendue :** rapport `reports/audit/CLAUDE_ULTRA_REVIEW_V1_DATA_SYNC_<DATE>.md` avec :
- Diagramme texte des 5 paths (A–E) end-to-end.
- Pour chaque path : preuve par code (`file:line`) à chaque hop.
- Risques résiduels classés P0 / P1 / P2 avec recommandation (gate, sentinel manquant, refactor).

---

### Axe 3 — Gérance globale (admin actions → propagation)

**Question primaire :** quand un admin **modifie** une catégorie, un produit, un stock, ou un composer profile, le système réagit-il correctement dans tous les cas (succès, échec, retry, race) ?

Cas à valider (un par un, file:line + verdict) :

| # | Action admin | Surface attendue | Sentinel actuelle | Verdict |
|---|---|---|---|---|
| 1 | Modifie catégorie (rename) | POS + Kiosk re-render | `tests/js/posComponentMenuFiltering.spec.js` | ? |
| 2 | Désactive un produit (`status=inactive`) | Disparait POS + Kiosk + KDS pour les nouveaux orders | `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` | ? |
| 3 | Change prix produit | Nouveaux ordres au nouveau prix; ordres en cours préservés (snapshot prix) | ? | ? |
| 4 | Bascule rupture manuelle (`AvailabilityService::toggle false`) | Item disparait POS + Kiosk; stock historique préservé | `tests/Feature/Menu/AvailabilityServiceTest.php` | ? |
| 5 | Modifie `max_daily_qty` | Re-évaluation immédiate is_available | `tests/Feature/Menu/MaxDailyQtyChangeReEvaluationTest.php` (M2 2.5) | ✅ M2 V2 |
| 6 | Publie un composer profile | Kiosk re-fetch ; panier ouvert reçoit toast | `tests/js/kioskWizardCatalogChangedHandling.spec.js` (M2 1.3) + `kioskComposerProfileChangeHandling.spec.js` (M2 2.3) | ✅ M2 V2 |
| 7 | Dépublie un composer profile | Symétrique #6 | `tests/Feature/Composer/ComposerProfileUnpublishTest.php` (M2 2.8) | ✅ M2 V2 |
| 8 | Hard-delete un produit avec ordres historiques | Refus (NF525) sauf `?force=true` | `tests/Feature/Catalog/ItemDeletionWithOrderHistoryTest.php` (M2 1.8) | ✅ M2 V1 |
| 9 | Duplique un produit | Copie indépendante (variations + extras + addons + composer profile draft) | `tests/Feature/Catalog/ItemDuplicationTest.php` (M2 1.7) | ✅ M2 V1 |

**Sortie attendue :** rapport `reports/audit/CLAUDE_ULTRA_REVIEW_V1_ADMIN_ACTIONS_<DATE>.md` avec verdict ligne par ligne + cas manquants à couvrir.

---

### Axe 4 — Personnalisation Wizard (POS ≠ Kiosk — décomposition page par page)

**Question primaire (utilisateur) :** « le Wizard de la caisse et celui de la borne sont trop différents. Il faut les décomposer page par page, avec un bon visuel pour la borne. »

Fichiers à inspecter :
- `resources/js/components/admin/pos/PosComponent.vue` (POS shell)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (Kiosk wizard)
- `resources/js/components/frontend/kiosk/steps/Kiosk*Component.vue` (kiosk step components)
- `resources/js/components/admin/pos/wizard/*` (POS wizard si existe)
- `app/Services/Composer/ComposerProfileService.php`, `ComposerProfileProjection.php`
- `app/Models/ItemWizardProfile.php`, `ItemWizardStep.php`
- `app/Services/Menu/PosMenuProjection.php` vs `KioskMenuService.php` — sortie wizard différente ?

**À prouver / produire :**
1. **Inventaire UI step-par-step** des deux wizards (POS et Kiosk). Capture les différences réelles (composants, layout, classes CSS, i18n keys).
2. **Comparaison sémantique** : si un step `viande` existe dans les 2, est-ce qu'il consomme les mêmes données ? Y a-t-il duplication de logique ?
3. **Recommandation décomposition** : pour chaque step, un schéma proposé qui sépare clairement :
   - Le **modèle de données** (commun, projeté côté backend)
   - La **logique de présentation** (1 composant POS, 1 composant Kiosk, partagent un composable de logique)
   - Le **visuel** (Kiosk = grandes cartes tactile fullscreen ; POS = compact list densité élevée)
4. **Gap visuel kiosk** : screenshots actuels vs cible Splash-niveau (cf. CLAUDE.md §7). Lister les améliorations concrètes par page (titre, illustration, animation, micro-interaction).

**Sortie attendue :** rapport `reports/audit/CLAUDE_ULTRA_REVIEW_V1_WIZARD_DECOMPOSITION_<DATE>.md` avec :
- Tableau step-par-step POS vs Kiosk (différences mesurées).
- Plan de refactor en N tâches bornées (idéal : 5–8 tâches de tier complex chacune ≤ 1 sprint).
- Pour chaque step kiosk, propositions visuelles concrètes (Splash-level UX).

---

### Axe 5 — Suppression de complexité inutile pour V1 (cleanup dashboard)

**Question primaire (utilisateur) :** « Je vois beaucoup de complexité et beaucoup de choses inutiles dans cette version actuelle. »

**Inventaire actuel des modules admin** (extrait `resources/js/components/admin/` et `resources/js/router/modules/`) :

```
administrators, chefs, components, coupons, creditBalanceReport,
customers, dashboard, deliveryBoys, diningTable, employees,
items, itemsReport, kitchenDisplaySystem, messages, offers,
onlineOrders, orderStatusScreen, pos, posOrders, profile,
pushNotification, salesReport, settings, stock, subscribers,
tableOrders, transactions, waiters

Settings sub-modules : company, site, branches, mail, order-setup,
kiosk-setup, loyalty-setup, otp, notification, social-media,
cookies, analytics, theme, time-slots, sliders, currencies,
item-categories, item-attributes, item-extras, item-addons,
languages, payment-methods, taxes, …
```

**À produire :**
1. **Classification V1 / V2 / V3** de chaque module avec critère explicite :
   - **V1 critique** : indispensable pour un fast-food en service (POS, Kiosk, KDS, OSS, Items, Stock, Branches, Settings indispensables, PosOrders, Transactions, SalesReport).
   - **V1 utile** : peut rester si le coût de maintenance est faible (Customers, Employees, Administrators).
   - **À supprimer V1** : pas de valeur immédiate, ajoute friction et complexité. Candidats probables (à confirmer) : `subscribers` (newsletter), `pushNotification` (notifications mobile customer app), `messages` (chat ?), `creditBalanceReport`, `deliveryBoys` (si pas livraison), `waiters` (si pas service à table), `chefs` (?), `tableOrders` / `diningTable` (si pas service à table), `coupons` (V2), `offers` (V2), `onlineOrders` (à confirmer si vente en ligne).
   - Settings : `sliders`, `social-media`, `cookies`, `analytics`, `theme`, `mail` peuvent être candidates à différer si non utilisées en service caisse.

2. **Pour chaque candidat à supprimer**, lister :
   - Routes (`resources/js/router/modules/*Routes.js`) à retirer.
   - Composants Vue à retirer.
   - Controllers Laravel à retirer (`app/Http/Controllers/Admin/*`).
   - Modèles + migrations associés (DROP TABLE = gate humain — ne PAS proposer DROP automatique, juste flagger).
   - Tests existants à retirer ou skipper.
   - Liens menu admin dans `BackendNavbarComponent.vue`.
   - Clés i18n dans `lang/{fr,en,...}/all.php`.

3. **Effort estimé** par module (S/M/L) et **valeur business** retirée (haute/moyenne/nulle).

4. **Recommandation finale** : ordre d'exécution (commencer par les S à valeur business=nulle, finir par les modules tablés DB qui demandent gate humain pour DROP TABLE).

**Sortie attendue :** rapport `reports/audit/CLAUDE_ULTRA_REVIEW_V1_DASHBOARD_CLEANUP_<DATE>.md` + un tableau machine-readable `reports/audit/CLAUDE_ULTRA_REVIEW_V1_DASHBOARD_CLEANUP_PLAN.csv` (`module, classification, files_to_remove, gate_required, effort, value_lost, priority`).

---

## Travail livré récemment (contexte pour ton audit)

| Mission | Vague | Statut | Commits |
|---|---|---|---|
| M1 — Catalog convergence | V1 (1.1–1.7) | CLOSED | inclus dans branche `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` |
| M2 — Lifecycle UX | V1 (1.1–1.9) | CLOSED | atomic decrement M2 1.9 ; ItemPreview ; duplicate ; etc. |
| M2 — Lifecycle UX | V2 (2.1, 2.3, 2.4, 2.5, 2.7, 2.8) | CLOSED | `4251ac4e0` (2.5), `5958f5911` (2.8), `0c482febb` (2.7), `a056ae95c` (2.4), `2a3fbf453` (2.1), `c2fe3b7f9` (2.3), `f4d3e3b3c` (final consolidé) |
| M2 — V2 gates | 2.2 + 2.6 | PENDING_HUMAN_GATE | `docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-05-02.md` + `GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE_2026-05-02.md` |
| M2 — V2 deferred | 2.9 XL | en attente Codex Pro | wizard admin guidé multi-step |

**Résultats tests globaux après V2** : PHPUnit 1305 passed / 30 skipped, Vitest 988 passed / 2 skipped, **0 régression**.

**Doctrine d'orchestration utilisée** : `.cursor/routing.md` § Tier-Routing 2026-05-02, `docs/orchestration/MULTI_AGENT_LOOP_2026-05-02.md`. Codex Pro a saturé pendant la V2 → fallback `foodking-complex-implementer` tracé `EXECUTE_DELEGATION` + `FALLBACK_REASON` dans `reports/post_execute_latest.log`.

---

## Format de sortie attendu (5 rapports + 1 synthèse)

1. `reports/audit/CLAUDE_ULTRA_REVIEW_V1_CATALOG_CENTRALIZATION_<DATE>.md`
2. `reports/audit/CLAUDE_ULTRA_REVIEW_V1_DATA_SYNC_<DATE>.md`
3. `reports/audit/CLAUDE_ULTRA_REVIEW_V1_ADMIN_ACTIONS_<DATE>.md`
4. `reports/audit/CLAUDE_ULTRA_REVIEW_V1_WIZARD_DECOMPOSITION_<DATE>.md`
5. `reports/audit/CLAUDE_ULTRA_REVIEW_V1_DASHBOARD_CLEANUP_<DATE>.md` + `.csv`
6. **Synthèse** : `reports/audit/CLAUDE_ULTRA_REVIEW_V1_GLOBAL_SYNTHESIS_<DATE>.md` avec :
   - Verdict V1-ready : **GO / GO_WITH_CONSTRAINT / NO_GO**.
   - Top 5 blockers V1 (P0).
   - Plan d'orchestration des fix bornés (TASK_ID candidats par axe).
   - Score sur 100 par axe (centralisation, sync, gérance, wizard, cleanup).

---

## Prompt à coller dans le terminal

```
Tu es Claude Anthropic Pro en terminal sur le dépôt FoodKing.

Lis d'abord :
1. reports/audit/_TERMINAL_CONTEXT_BRIEF.md (préparé par foodking-claude-orchestrate.sh context)
2. reports/audit/CLAUDE_TERMINAL_ULTRA_REVIEW_REQUEST_V1_FUNCTIONAL_2026-05-02.md (cette demande)

Mission : produire les 6 rapports listés en §"Format de sortie attendu" de CLAUDE_TERMINAL_ULTRA_REVIEW_REQUEST_V1_FUNCTIONAL_2026-05-02.md.

Contraintes :
- Tu inspectes le code en lecture seule. Aucune modification.
- Tu cites file:line pour chaque affirmation forte.
- Tu respectes la token-discipline `.cursor/rules/global.mdc` § Token Discipline (qualité d'abord, pas raccourci sur la substance).
- Tu fournis un verdict GO / GO_WITH_CONSTRAINT / NO_GO sur la V1 dans la synthèse finale.
- Tu écris chaque rapport sous reports/audit/ avec le suffixe de date 2026-05-02.

Commence par l'axe 1 (catalogue centralization). Ne saute pas un axe pour économiser des tokens.
```

---

**Date :** 2026-05-02
**Auteur :** Claude in-session (orchestrator)
**Branche :** `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
