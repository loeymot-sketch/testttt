# Master Review POS + KDS — Brief de finitions avant lancement — 2026-04-26

**Auteur** : cursor-claude (orchestrateur)
**Reviewer** : Claude terminal (Anthropic CLI, abonnement direct)
**Sortie attendue** : `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`
**Objectif** : audit master des finitions à corriger sur POS + KDS **avant lancement production** (= lancement opérateur réel multi-branches, pas POC interne)

---

## 1. Scope

| Surface | Fichiers principaux à auditer | Tests présents |
|---------|-------------------------------|----------------|
| **POS frontend** | `resources/js/components/admin/pos/PosComponent.vue` (3000+ lignes), `PaymentComponent.vue`, `ReceiptComponent.vue`, `ParkedOrdersComponent.vue`, `FloorplanComponent.vue`, `ItemComponent.vue`, `resources/js/store/modules/posOrder.js`, `resources/js/store/modules/kds.js`, `resources/js/pos-app.js`, `resources/js/services/KdsSyncService.js` | `tests/js/posOrderIdempotency.spec.js`, `tests/js/kdsAllergens.spec.js`, `tests/js/kdsStationFilter.spec.js`, `tests/js/kdsLineSemantics.spec.js`, etc. (112 vitest specs total) |
| **POS backend** | `app/Services/OrderService.php`, `app/Services/PosParkedOrderService.php`, `app/Services/Pricing/CompositionSnapshotBuilder.php`, `app/Http/Controllers/Admin/Pos/*Controller.php` (Floorplan, ParkedOrder, CashDrawer, CustomerNfcLookup, PosReceiptPrint), `app/Http/Controllers/Admin/PosOrderController.php`, `app/Http/Requests/PosOrderRequest.php` | `tests/Feature/Pos/DiningTableReleaseAfterPosOrderTest.php`, `tests/Feature/Pos/FloorplanControllerTest.php`, `tests/Feature/Pos/PosPurgeParkedScheduleTest.php` (3 only — coverage probably insufficient) |
| **KDS frontend** | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`, `resources/js/store/modules/kitchenDisplaySystemOrder.js`, `resources/js/helpers/kdsDisplay.js`, `resources/js/helpers/kdsLineSemantics.js`, `resources/js/helpers/kdsAllergens.js`, `resources/js/services/KdsSyncService.js` | `tests/js/kdsAllergens.spec.js`, `tests/js/kdsStationFilter.spec.js`, `tests/js/kdsLineSemantics.spec.js` |
| **KDS backend** | `app/Services/KitchenDisplaySystemOrderService.php`, `app/Services/KdsSyncService.php`, `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`, `app/Http/Controllers/Admin/KdsSyncController.php`, `app/Http/Resources/KDSOrderDetailsResource.php` | `tests/Feature/KDS/KdsAllergenAggregationSplitTest.php`, `tests/Feature/KDS/KdsSnapshotImmutableTest.php` (only 2) |
| **Sync POS↔KDS** | `app/Events/Order*.php`, `app/Listeners/Persist*ToOutbox.php`, `app/Jobs/DispatchDomainEventsJob.php`, `app/Services/Observability/SyncMetricsRecorder.php`, `resources/js/services/WebSocketService.js`, `resources/js/services/eventContract.js` | `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`, `tests/Feature/Observability/*.php`, `tests/Feature/EventContractTest.php` |

**Hors scope** : Kiosk (sauf si KioskPaymentComponent partage un contrat avec POS PaymentComponent), backend OSS, billing, fiscal/NF525 (couvert par cycles dédiés), infra/CI (couvert par W2 #3).

---

## 2. État connu (sans re-paraphraser les rapports — Claude lit s'il en a besoin)

**Cycles POS récents** (depuis le journal d'activité) :
- W0 → W2 #1 POS V4 design integration (ADR couleur, code splitting, vendor chunking, lazy admin, dedicated entry `pos-app.js`).
- POS first-paint passé de 791 KB gz → 652 KB gz (-17.6 %) sur `/admin/pos-v4`.
- Lots 2.A/2.B/2.D/2.F/2.G/2.H/2.I/2.J/2.C/2.E exécutés par cursor-composer (idempotence checkout, reçu toasts, aide TPE kiosk, KDS filtre par user, scheduler purge parked, single-flight checkout, a11y modal, libération table, son KDS, timeout loyalty).

**Cycles KDS récents** :
- KDS allergens G4-G5 (split + agrégation), KDS station filter, KDS line semantics, KDS sync service introduit (`app/Services/KdsSyncService.php` + `resources/js/services/KdsSyncService.js`), KDS adaptive polling (lot 1.C), KDS observability (lot NEW04).

**Gates humains en attente** (`docs/gates/GATE_LOG.md`) :
1. `GATE_PAYMENT_PROP_MUTATION_2026-04-26` — refactor PaymentComponent prop mutation (bloque finition POS payment).
2. `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` — 8 cycles P0 frozen (OrderService, PaymentService, pricing, idempotency, coupons).
3. `HG-W2-3` (KPI revision 220→600 KB + LCP) — pré-requis HG-W2-2 (vendor split) + HG-W2-1 (cutover).
4. `HG-W2-1` (cutover POS V4 strategy) — soft-blocked sur HG-W2-3 + LCP réel.
5. `HG-W2-2` (vendor split `vendor-pos.js`) — bloqué sur HG-W2-3.

**Couverture tests apparente** : 112 vitest specs + 175 phpunit Feature + 12 phpunit Unit. POS Feature = 3 fichiers, KDS Feature = 2 fichiers (ratio probablement insuffisant pour la complexité réelle).

---

## 3. Ce que la review DOIT produire

Format strict pour exploitation directe par master plan :

### 3.1 Verdict global de readiness POS+KDS pour lancement production
- 1 ligne : `READY` / `READY-WITH-CONDITIONS` / `NOT-READY` + score /10.
- 1 paragraphe : raisonnement.

### 3.2 Liste de finitions par priorité

Pour chaque finding, format obligatoire :

```
[ID] — [P0 BLOCKER | P1 QUALITY | P2 POLISH | P3 BACKLOG]
Surface       : POS-FE | POS-BE | KDS-FE | KDS-BE | SYNC | TEST | OPS
Description   : <1-2 phrases factuelles>
Fichier(s)    : path:line si possible (ou path générique)
Invariant     : pricing-ssot | order-status | branch-id | dispatch | symmetry | frozen | a11y | perf | sync | none
Évidence      : <comment vérifié, pas de "il semble que">
Effort        : XS (<1h) | S (1-4h) | M (1d) | L (2-5d) | XL (>1 semaine)
Risque blocage: <ce que ça empêche en prod si pas corrigé>
Fix proposé   : <plan court — pas du code complet>
Dépendances   : <autres findings ou gates dont celui-ci dépend>
```

### 3.3 Buckets attendus — minimum requis

Claude doit explicitement chercher (et reporter "OK / RAS" si rien trouvé, pas silence) :

**A. Invariants FoodKing** (`.cursor/rules/project-invariants.mdc`)
- A1 : pricing SSOT respecté côté POS (vérifier `PosComponent.vue` pricing wrap signoff-pending L1779, vérifier `PaymentComponent`, vérifier kiosk payment).
- A2 : OrderStatus enum (pas de magic int dans POS/KDS — `pos_orderstatus_guard.mjs` couvre POS, vérifier KDS).
- A3 : `branch_id` isolation (POS controllers, KDS controllers, sync queries).
- A4 : dispatch après commit (Order events, KDS sync events).
- A5 : symétrie OrderService / FrontendOrderService.
- A6 : frozen zones touchées sans gate (PaymentComponent, OrderService, etc.).

**B. Robustesse opérationnelle**
- B1 : reconnect storm (fermeture WS, F5, perte réseau opérateur — réf cycle T-NEW02).
- B2 : outbox dedupe sous concurrence (réf T-NEW01, déjà testé — vérifier si KDS sync utilise même pattern).
- B3 : retry exponentiel + circuit breaker côté `KdsSyncService.js` et `WebSocketService.js`.
- B4 : single-flight checkout POS (lot 2.H — vérifier qu'il survit aux 401 / refresh token).
- B5 : libération de table POS sur cancel/refund (lot 2.J — vérifier paths cancel/refund, voir `OrderTableChanged` event nouveau).
- B6 : purge parked orders schedule (lot 2.G — vérifier idempotence en cas de double-tick).
- B7 : son KDS (lot 2.C — vérifier autoplay browser, fallback silencieux).
- B8 : timeout loyalty kiosk (lot 2.E — vérifier propagation aux composants enfants).

**C. UX / a11y / i18n**
- C1 : focus trap modals POS + KDS (lot 2.I a11y — vérifier autres modals : NfcCustomer, PaymentChange, CashDrawer).
- C2 : i18n complet 4 langues (fr/en/ar/bn) — `lang/*/pos_payment_method.php` ajoutés, vérifier KDS, kiosk help, error messages.
- C3 : toasts reçu POS (lot 2.B — vérifier wording, durée, dédupe).
- C4 : aide TPE kiosk (lot 2.D — vérifier responsive, gestes tactiles).
- C5 : RTL (arabe) — POS+KDS direction CSS, layout floorplan.
- C6 : POS dark mode si applicable, sinon RAS.

**D. Performance / bundle**
- D1 : POS first-paint ≤ KPI révisé (HG-W2-3 pending — supposer ≤600 KB cible).
- D2 : KDS bundle (admin-kds chunk = 26 KB gz — vérifier que c'est cohérent avec usage continu opérateur cuisine).
- D3 : LCP/TTI réel (manquant — bloque HG-W2-1).
- D4 : memory leaks long-running (KDS = écran allumé 8-12h ; POS = 4-6h shift).
- D5 : Echo / Pusher reconnect cost.

**E. Couverture tests**
- E1 : POS Feature seulement 3 fichiers — quels paths critiques manquent ? (cancel order, refund, void payment, table transfer, parked order resume, cash drawer counts, customer NFC lookup, escpos print).
- E2 : KDS Feature seulement 2 fichiers — manquent : status transitions (new→preparing→ready→served), allergen aggregation runtime, station routing, snapshot regeneration on item change, concurrent state updates from WS+poll.
- E3 : Vitest POS — couverture composant `PosComponent` (3000 lignes — probablement faible).
- E4 : E2E Playwright POS+KDS si plan le déclare (sinon RAS — décision plan).

**F. Observabilité / OPS**
- F1 : `SyncMetricsRecorder` couvre-t-il toutes les métriques POS↔KDS critiques (event lag p95, outbox depth, dispatch failures) ?
- F2 : Dashboard ops affiche-t-il ces métriques (`SyncOverviewController` créé — vérifier complétude) ?
- F3 : Alarmes sur thresholds (taux 5xx POST /api/orders, WS disconnect rate, KDS sync staleness).
- F4 : Logs structurés (correlation_id propagé event→outbox→dispatch→KDS, vérifier `EnsureCorrelationIdPropagatesToMetricsTest`).

**G. Données / état persistant**
- G1 : `pos_parked_orders` table — schéma OK, migrations présentes ; vérifier index `branch_id+status+expires_at`.
- G2 : `composition_snapshot` immutable (réf migration `add_composition_snapshot_to_order_items`) — vérifier que KDS et reçu lisent la snapshot, pas l'item live.
- G3 : `release_tracking` sur order_items (migration récente) — vérifier que cancel/refund crédite bien la dispo.
- G4 : `sync_metrics` table (migration nouvelle) — vérifier rétention/purge.
- G5 : `action_logs` index composite branch+created (migration nouvelle) — bénéfice prouvé ?

**H. Risques connus restants** (déjà documentés, ne pas re-réviser sauf détection d'aggravation)
- H1 : 5 gates humains en attente (P0 frozen + Payment + W2 trio).
- H2 : KPI 220 KB infaisable (résolu via HG-W2-3 en cours).
- H3 : Codex API instable (résolu : Claude orchestre).

### 3.4 Recommandation de séquencement

Liste ordonnée des prochains cycles à lancer, en respectant gates et dépendances. Format :

```
1. [Priorité] [Cycle proposé] — [pré-requis humain | aucun] — [estimation]
2. ...
```

### 3.5 Verdict final
- 1 paragraphe : "Avant lancement production multi-branches, la condition strictement minimale est : ___"

---

## 4. Contraintes du reviewer (à respecter par Claude)

- **Pas de self-approval de gate** (`.cursor/rules/human-gates.mdc`).
- **Pas de proposition de scope expansion** non-déclarée (`.cursor/rules/scope.mdc`).
- **Token discipline** : pas de re-paraphrase de rapports déjà cités, pas de copie de code, citations `path:line` uniquement.
- **Honnêteté** : si un finding est probable mais non vérifié faute de temps, le marquer `[UNVERIFIED]` et l'ajouter au backlog plutôt que de l'omettre.
- **Pas d'invention** : si un fichier cité n'existe pas, le signaler ; ne pas halluciner du code.

## 5. Output

Écrire le résultat dans `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` au format spécifié §3.

Une fois la review livrée, cursor-claude consolidera en `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` avec attribution P0/P1/P2 + séquencement humain-friendly.
