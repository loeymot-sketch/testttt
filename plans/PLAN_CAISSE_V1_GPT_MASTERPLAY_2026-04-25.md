# PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25 — *Master plan exécutable GPT*

> **Statut autorité** : *playbook d'implémentation*. **Ne remplace pas** le DAG `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` (autoritaire), `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` (LOT 0–8 finitions), ni `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` (matrice).  
> **Rôle** : orchestrer l'exécution **GPT-5.5-pro / Codex CLI** (`codex-extension`) avec **discipline maximale** ; chaque mission est *dense, vérifiable, file:line ancrée*. **Claude audite à la fin**.  
> **Verdict cycle** : `READY_FOR_PHASE_0: YES` · `READY_FOR_PRODUCT_CODE: NO` (HUMAN_GATES_FIRST).

---

## 0. Doctrine d'orchestration — *non négociable*

### 0.1 Boucle officielle (FoodKing)

```
PLAN (Claude) → PLAN_REVIEW (codex) → EXECUTE (codex-extension) → SELF_AUDIT (codex) →
VALIDATE (PHPUnit/Vitest/Playwright/lint) → AUDIT (Claude terminal) → GPT_FINAL_AUDIT (codex) →
[GATE | CLOSE]
```

`AGENTS.md` § *Authoritative multi-agent bounded cycle* + `.cursor/commands/run-cycle.md`. **Aucune** clôture sans **double PASS** (`AUDIT_VERDICT: PASS` + `GPT_FINAL_AUDIT_VERDICT: PASS`).

### 0.2 Arbitrage de référence (déjà tranché — *ne pas re-débattre*)

> *« Codex concepts, Claude sequence »* — primitives Codex (`OrderIntent`, `OrderQuote`, `PaymentProof`, `KitchenRelease`), **séquence Claude** : sécurité/branches/POS d'abord, puis quote, puis paiement, puis fiscal, puis KDS/release, puis kiosk runtime, puis ops/canary, puis UX finitions.  
> Source : `reports/audit/CODEX_CLAUDE_MEGA_PLAN_COMPARISON_CAISSE_V1_2026-04-25.md:9` + `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md:282-300`.

### 0.3 Invariants FoodKing — *gate immédiat si violation*

1. **Pricing SSOT backend** (jamais de calcul prix produit final côté front).
2. `**OrderStatus` enum partout** — interdit littéraux numériques (`16`).
3. `**branch_id` exact** (`=`, jamais `LIKE`) — **fuite multi-tenant = P0**.
4. **Dispatch après `DB::commit*`* (pas dans la transaction, pas dans `finally`).
5. **Symétrie `OrderService` / `FrontendOrderService`** — `SYMMETRY_NOTE` obligatoire si l'un est touché.
6. **Frozen zones** : édition uniquement avec gate signé dans `docs/gates/GATE_LOG.md`.

### 0.4 Discipline Codex — *imposée à chaque mission*


| Règle                          | Application                                                                                                      |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------- |
| **Allowlist stricte**          | Champ `allowlist` dans `input.json` ; tout fichier hors liste → `SCOPE_PRESSURE` + stop.                         |
| **Off-limits explicite**       | Champ `off_limits` ; en frozen, `off_limits` dominant tant que gate non signé.                                   |
| **Diff minimal**               | Pas de refactor opportuniste, pas de renommage hors mission, pas d'optimisation collatérale.                     |
| **Pas de gate auto-approuvée** | Codex peut *rédiger* options, jamais cocher l'approbation.                                                       |
| `**SYMMETRY_NOTE`**            | Rempli si OS ou FOS modifié — résolu avant close.                                                                |
| **Tests exigés**               | Liste `mandatory_tests` dans la mission ; échec test ≠ close.                                                    |
| **Trace**                      | `EXECUTE_DELEGATION: codex-extension` dans `reports/post_execute_latest.log` + `REPORT_FILE`.                    |
| **Activity log**               | `bash scripts/agent-activity-log.sh start codex-extension <TASK_ID> execute "<files CSV>"` avant ; `done` après. |


### 0.5 Sub-agents Cursor (orchestration parallèle)

- `**explore`** : cartographier un sous-domaine *avant* d'écrire `execute_brief.md` (read-only, rapide). Déjà utilisé pour cette synthèse — résultats intégrés en §3.
- `**shell*`* : `npm run verify:boucle`, `php artisan test --filter=...`, `bash scripts/agent-activity-log.sh tail 50`.
- `**foodking-complex-implementer`** : **fallback uniquement** si `codex exec` échoue ≥2 fois (binaire indispo, auth, hang). Tracer `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:`.
- `**foodking-planner-orchestrator`** : fallback **AUDIT** si terminal Claude HS (quota / rate-limit) — tracer `AUDIT_CHANNEL: cursor-session` + `AUDIT_FALLBACK_REASON:`.

---

## 1. État des gates — *pré-requis bloquant*


| Gate                                            | Brief                                                         | Statut               | Bloque                 |
| ----------------------------------------------- | ------------------------------------------------------------- | -------------------- | ---------------------- |
| `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` | `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` | `PENDING_HUMAN_GATE` | M-06, M-09, M-10       |
| `GATE_PAYMENT_PROP_MUTATION_2026-04-26`         | `docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md`         | `PENDING_HUMAN_GATE` | M-06b, M-21b           |
| `GATE_FROZEN_ZONES_CAISSE_V1`                   | à drafter                                                     | `TO_DRAFT`           | M-06, M-09             |
| `GATE_FISCAL_KIOSK_V1`                          | à drafter                                                     | `TO_DRAFT`           | M-08, M-11             |
| `GATE_PAYMENT_LEDGER_V1`                        | à drafter                                                     | `TO_DRAFT`           | M-04A vs M-04B         |
| `GATE_KDS_BUMP_V1`                              | à drafter                                                     | `TO_DRAFT`           | M-07                   |
| `GATE_SCHEMA_MIGRATIONS_V1`                     | à drafter                                                     | `TO_DRAFT`           | M-04, M-05, M-08, M-13 |
| `GATE_OFFLINE_SCOPE_V1`                         | à drafter                                                     | `TO_DRAFT`           | M-11                   |
| `GATE_WEB_PAYMENT_SCOPE_V1`                     | à drafter                                                     | `TO_DRAFT`           | M-17                   |
| `GATE_STRIPE_CENTS_ACTIVE`                      | à drafter                                                     | `TO_DRAFT`           | M-17                   |


**Toute mission marquée `(GATE)`** ci-dessous nécessite *au moins un* gate signé. Avant signature : seuls les `(NO-GATE)` exécutent.

---

## 2. Cartographie code réelle — *évidence file:line* (ancrage GPT)

> **Cette section est l'or de cette session** : elle évite à GPT de redécouvrir le code à chaque mission. Données issues des sous-agents `explore` lancés en parallèle. Ancrage repo `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`.

### 2.1 `payment-confirm` kiosk (cible **M-06**)


| Élément       | Évidence                                                                                                                                                                        |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Route         | `routes/api.php:889-895` — group `frontend.order` (auth:sanctum) → `POST .../payment-confirm` → `Frontend\OrderController::paymentConfirm`.                                     |
| Controller    | `app/Http/Controllers/Frontend/OrderController.php:77-151` — validation **inline** (`transaction_id`, `card_type`, `payment_method`), **pas de `PaymentConfirmRequest*`* dédié. |
| Sanctum check | `app/Http/Controllers/Frontend/OrderController.php:85-96` — alignement `user_id` ; **pas de check `kiosk:order` ability ni de `KioskMachine::branch_id` resolver**.             |
| Transaction   | `app/Http/Controllers/Frontend/OrderController.php:101-118` — `DB::transaction` pose `payment_status=PAID` + `transaction_id`.                                                  |
| Service       | `app/Services/FrontendOrderService.php:791` — `finalizePaidKioskOrder` (PENDING→ACCEPT après TPE).                                                                              |
| Front         | `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:566` — `axios.post('frontend/order/${id}/payment-confirm')` (retry ×3).                                       |


**Risque** (P0, dispute §4-confirm) : *non-kiosk* avec Sanctum peut forcer `PAID`. Pas de `payment_method` de la commande revérifié vs la requête. Pas de re-vérification `branch_id` machine.

### 2.2 `OrderService` vs `FrontendOrderService` (cibles **M-09, M-10**)


| Méthode                         | OrderService  | FrontendOrderService | Remarque symétrie                                                |
| ------------------------------- | ------------- | -------------------- | ---------------------------------------------------------------- |
| `myOrderStore`                  | L291          | L123                 | bifurcation create kiosk vs admin                                |
| `posOrderStore`                 | L566          | —                    | POS-only                                                         |
| `tableOrderStore`               | L1032         | —                    | dine-in only                                                     |
| `changeStatus`                  | L1489         | L659                 | présents des deux côtés ; `SYMMETRY_NOTE` requis si modification |
| `changePaymentStatus`           | L1661         | **absent FOS**       | divergence : seul OS gère ; à formaliser dans M-10               |
| `cashBack` (via PaymentService) | L1505 / L1568 | L685                 | `OrderStatusNoopSideEffects` — risque double cashback            |
| `refundPoints` (LoyaltyService) | L1511 / L1574 | L691                 | idem                                                             |
| `destroy` (void)                | L1783         | absent               | dispatch L1793-1795 (`branch_id=0`?) — vérifier scope            |


**Dispatch après commit** : `posOrderStore` dispatch L986-993 *post* L594-984 (commit OK) ; `myOrderStore` OS L542-551 post L294-540 (OK) ; FOS `myOrderStore` L597-607 post L156-583 (OK). `**changeStatus` self-service OS L1496-1540** — dispatches L1523+ **sans** transaction englobante (à examiner si concurrence possible).

### 2.3 Branch isolation — *fuites identifiées* (cible **M-09**)


| Fichier                                             | Ligne            | Pattern                                                                                       | Risque                                |
| --------------------------------------------------- | ---------------- | --------------------------------------------------------------------------------------------- | ------------------------------------- |
| `app/Services/OrderService.php`                     | **L151**         | `where($key,'like',…)` générique sur `orderFilter` (inclut `branch_id` si query-param fourni) | **P0** — fuite substring multi-tenant |
| `app/Services/OrderService.php`                     | L194, L230, L267 | mêmes motifs LIKE                                                                             | **P0**                                |
| `app/Services/OrderService.php`                     | L1920            | `salesReportOverview` branche `else` → LIKE                                                   | **P0**                                |
| `app/Services/FrontendOrderService.php`             | L99              | `myOrder` filtre `branch_id` LIKE (hors `status`)                                             | **P0**                                |
| `app/Services/TransactionService.php`               | L33-35           | `whereHas` order = strict                                                                     | OK                                    |
| `app/Services/KitchenDisplaySystemOrderService.php` | L84-90           | cast `(int)` + `=` strict (commenté L85-87)                                                   | OK                                    |
| `app/Services/OrderStatusScreenOrderService.php`    | L65-67           | `where('branch_id', $userBranchId)`                                                           | OK                                    |
| `OrderService::posOrderStore`                       | L610             | usage `branch_id=0` (admin global ?)                                                          | à scoper M-09                         |
| `OrderService::destroy`                             | L1793-1795       | dispatch + scope `branch_id=0`                                                                | à vérifier M-09/M-10                  |


### 2.4 KDS — `OrderStatusRequest` + transition (cible **M-07**)


| Élément       | Évidence                                                                                                                                                                                                                                    |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Request rules | `app/Http/Requests/OrderStatusRequest.php:45-47` — `status: required                                                                                                                                                                        |
| Authorize     | `app/Http/Requests/OrderStatusRequest.php:15-35` — rôles + kiosk ability + statut 16.                                                                                                                                                       |
| Liste KDS     | `app/Services/KitchenDisplaySystemOrderService.php:53-54` — `whereIn('status', [ACCEPT, PREPARING, PREPARED])` — pré-filtre OK.                                                                                                             |
| Change status | `app/Services/KitchenDisplaySystemOrderService.php:117-168` — `$expectedFrom = $locked->status` (L122) + lock + comparaison L135-147 + `OrderStateMachine::allows` L150 + `recordTransition` L158-165 + dispatch *après* transaction L173+. |
| **Manque**    | `expected_status` non requis depuis le client → impossible de détecter un bump simultané sur 2 écrans avec versions divergentes. **P0 selon `GATE_KDS_BUMP_V1`.**                                                                           |
| Front         | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:130` `<Swiper dir="ltr">` (RTL cassé) ; cap **50** L786-793 ; **0 occurrence `expected_status` côté JS**.                                             |


### 2.5 Kiosk — runtime / offline / enum (cible **M-11**)


| Sujet                   | Évidence                                                                                                               |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| ID offline détection    | `KioskPaymentComponent.vue:292` — `String(orderId).startsWith('offline_')`.                                            |
| Génération offline ID   | `helpers/kioskOfflineQueue.js:135, 330` — `offline_${savedAt}_...`.                                                    |
| Réponse synthétique     | `store/modules/kioskCart.js:483-486` — `id: localKey`.                                                                 |
| Total fallback offline  | `KioskPaymentComponent.vue:297-305` — repli sur `this.cartTotal` (pas serveur).                                        |
| TPE CB / TR             | `KioskPaymentComponent.vue:393-414` + `_invokeTpe` L473-501 — bridge HW.                                               |
| Annulation `status: 16` | `KioskWaitingComponent.vue:392` — `POST .../change-status` `{ status: 16 }` *littéral* (à passer par enum).            |
| Enum source             | `KioskWaitingComponent.vue:155-159` — `STATUS_CANCELLED = orderStatusEnum.CANCELED` ✓ (mais usage incohérent vs L392). |
| Polling guards          | `KioskWaitingComponent.vue:195-198, 258-305` — gardes offline + double-poll.                                           |
| Menu source             | `store/modules/kioskMenu.js:276` — `axios.get('frontend/menu')` (bon endpoint SSOT).                                   |
| Pricing locale          | `helpers/kioskFormatPrice.js:31-32` — défauts hardcodés `'fr-FR'` / `'EUR'`.                                           |


### 2.6 POS — `PaymentComponent` mutations props (cible **M-06b**, gate `GATE_PAYMENT_PROP_MUTATION`)

Prop unique `props: { props: Object }` (`PaymentComponent.vue:124-126`). **Mutations directes** détectées :


| Ligne    | Champ muté                                                                                                                                                                                                                                                           |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| L179     | `pos_payment_note`                                                                                                                                                                                                                                                   |
| L192-193 | `pos_payment_method`, `pos_payment_note`                                                                                                                                                                                                                             |
| L205-217 | `pos_received_amount`, `pos_payment_note`                                                                                                                                                                                                                            |
| L221     | `branch_id`                                                                                                                                                                                                                                                          |
| L237-239 | `items` (normalisation JSON)                                                                                                                                                                                                                                         |
| L250-265 | reset post-succès : `token`, `subtotal`, `discount`, `delivery_time`, `delivery_charge`, `total`, `order_type`, `is_advance_order`, `source`, `address_id`, `dining_table_id`, `coupon_id`, `items`, `pos_payment_method`, `pos_payment_note`, `pos_received_amount` |


**Total ≥ 16 sites de mutation.** Refactor uniquement après `GATE_PAYMENT_PROP_MUTATION_2026-04-26` *Approved* — Option A (`emit('update:form')` + parent state) ou B (copie locale `data()`).

### 2.7 POS `PosComponent` — `discountReason` & focustrap (cible **M-21 / LOT-0**)


| Élément                  | Évidence                                                                            |
| ------------------------ | ----------------------------------------------------------------------------------- |
| `v-model` actuel         | `PosComponent.vue:423-425` — `**v-model="discount"`** (pas `discountReason`).       |
| Lecture `discountReason` | `PosComponent.vue:1668` — `(this.discountReason                                     |
| Import focustrap         | `PosComponent.vue:732` — `import focustrap from "bootstrap/js/src/util/focustrap"`. |
| Computed mort            | `PosComponent.vue:913-914` — `focustrap() { return focustrap }` non utilisé.        |


---

## 3. Vagues d'exécution — *ordre imposé*

### Vague A — *NO-GATE, parallèle, démarre immédiatement*

`M-01` matrice complète · `M-02` sentinels · `M-12` legacy guards CI · `M-16` hardware lab · `M-18` test architecture · `M-19` mémoire · `M-20` runbook squelette · `M-21a` quickwins UX (LOT-0 finitions).

### Vague B — *POST-GATE 03, séquence stricte*

`M-09` branch isolation → `M-06` POS guards (incl. `payment-confirm` durci) → `M-05` `OrderQuote` → `M-04A` *xor* `M-04B` paiement → `M-08` fiscal Z → `M-07` KDS release → `M-10` symétrie OS/FOS → `M-11` kiosk runtime → `M-17` web/Stripe → `M-13` migrations → `M-14` ops → `M-15` rollout → `M-21b` payment refactor + 401 retry → `M-22` post-launch.

> **Note** : la séquence place **branch isolation avant POS guards** (sécurité d'abord), puis quote *avant* paiement (le paiement consomme un quote signé), conforme à l'arbitrage Claude.

---

## 4. Catalogue de **missions GPT** (M-XX) — *prêtes à coller*

> Format unique pour chaque mission : un bloc `missions/<TASK_ID>/input.json` + `execute_brief.md`. Codex lit, exécute, écrit `output_codex.json` + `GPT_SELF_AUDIT_<TASK_ID>.md`. Claude audite. Voir aussi `npm run codex:prepare -- <TASK_ID>` pour bootstrap.

---

### 🟢 M-01 — `CAISSE_V1_TRACEABILITY_COMPLETE_2026-04-25` (NO-GATE)

**But** : transformer `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` (statut `INITIAL_NOT_FINAL`) en table machine-checkable `COMPLETE`.

**Allowlist** : `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md`, `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.csv` (NEW), `scripts/check-traceability.sh` (NEW).

**Off-limits** : tout code produit.

**Inputs source** : `MEGA_RAPPORT_FINAL_DISPUTE`, `AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS`, `AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP`, `MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE`, `CLAUDE_SUPER_MASTER_PLAN_REVIEW`, `MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE`.

**Sortie** : matrice avec colonnes `FK-### | Source | Description | Severity (P0/P1/P2) | PLAN-ID | TASK_ID | Sentinel | Test command | Gate | Owner | Status (planned/in_progress/verified/deferred) | Evidence`.

**Critères PASS** : `0 P0` sans `PLAN-ID` ; `0 P0` sans test ou `PREUVE_MANQUANTE` ; `scripts/check-traceability.sh` exit 0.

---

### 🟢 M-02 — `CAISSE_V1_SENTINEL_BASELINE_2026-04-25` (NO-GATE)

**But** : poser **18 sentinels fail-first**, baseline rouge documentée, mapping `finding ↔ test ↔ plan`.

**Allowlist** : `tests/Feature/Sentinels/`* (NEW), `tests/js/sentinels/*` (NEW), `tests/Playwright/sentinels/*` (NEW), `reports/sentinels/CAISSE_V1_BASELINE_RUN_2026-04-25.log` (NEW).

**Off-limits** : code produit (sauf hooks de test isolés, justifiés en mission).

**Sentinels minimaux** (cf. `PLAN-02` super master + ancrage §2 ci-dessus) :

1. `PaymentConfirmAbilitySentinelTest` (Feature, P0) — POST sur `frontend/order/{id}/payment-confirm` avec user **non-kiosk** → attendu **403/422**, `payment_status` inchangé. Ancrage : `app/Http/Controllers/Frontend/OrderController.php:85-118`.
2. `PaymentConfirmCrossBranchSentinelTest` (Feature) — utilisateur kiosk machine branche A confirme commande branche B → 403, mutation = 0.
3. `PaymentConfirmCashOrderSentinelTest` — order `payment_method=cash` → confirm refusé.
4. `PaymentConfirmConcurrencySentinelTest` (concurrency) — 2 confirms simultanés même `transaction_id` → un seul `OrderStatusChanged`, idempotent.
5. `OrderStatusNoopSideEffectsSentinelTest` — double cancel → 1 seul `cashBack` (ancrage `OrderService::changeStatus` L1505/L1568).
6. `CleanupVsConfirmRaceSentinelTest` — `CleanupStalePendingKioskOrders` rejette commande, puis confirm tardif → 422 + audit log + flag réconciliation TPE.
7. `OrderListBranchExactnessSentinelTest` (Feature, P0) — query-param `branch_id=1` ne fuit pas en LIKE vers `10/100`. Ancrage : `OrderService.php:151,194,230,267,1920` + `FrontendOrderService.php:99`.
8. `OrderShowBranchGuardSentinelTest` — `GET /admin/order/{id}` autre branche → 403.
9. `TransactionBranchExactnessSentinelTest` — `TransactionService::list` cross-branch → vide.
10. `FiscalZBranchExactnessSentinelTest` — Z branche A n'inclut pas commandes branche B.
11. `OssAdminBranchPolicySentinelTest` — `branch_id=0` réservé aux admins globaux ; staff branch ≠ 0.
12. `KdsTransitionWhitelistSentinelTest` (Feature) — chef KDS PREPARING → CANCELED **422** ; whitelist {ACCEPT, PREPARING, PREPARED}. Ancrage : `app/Http/Requests/OrderStatusRequest.php:45-47`.
13. `KdsExpectedStatusConflictSentinelTest` (concurrency, P0) — body inclut `expected_status` ; 2 chefs simultanés → 409 idempotent.
14. `PosCashEndpointSentinelTest` — POS cash kiosk *ne doit pas* appeler `kds-order/change-status` ; route POS dédiée existe.
15. `PosSubtotalForgerySentinelTest` — POST POS avec subtotal forgé → backend recalcule, refuse remise > permission.
16. `QueueNumberUniquenessSentinelTest` (concurrency) — 100 commandes simultanées même branche/jour → 100 `queue_number` uniques.
17. `KioskOfflineIdPrefixSentinelTest` (Vitest) — toute soumission offline génère `offline_<ts>_<uuid>` ; assertion sur `kioskOfflineQueue.js:135,330`.
18. `KioskCbTrOfflineRefusedSentinelTest` (Playwright) — kiosk offline + CB/TR → bouton désactivé OU API refuse 422.

**+ statiques** : `OrderStatusEnumKioskHardcodeLintTest` (zéro `status: 16` littéral), `LegacyImportGuardLintTest`, `BundleScanLegacyTest`, `PaymentComponentPropMutationVitestSentinel` (compteur ≥ 16 mutations actuelles, doit tomber à 0 après M-21b).

**PASS** : tous rouges *pour la raison documentée* ; mapping `sentinel ↔ FK-### ↔ M-XX`.

---

### 🟡 M-03 — `CAISSE_V1_GATES_DRAFT_2026-04-25` (humain final)

**But** : rédiger les briefs des **7 gates `TO_DRAFT`** de §1 (options A/B/C, recommandation Claude, plans bloqués, evidence requise). Codex *propose*, humain signe.

**Allowlist** : `docs/gates/GATE_FROZEN_ZONES_CAISSE_V1.md` (NEW) … (8 fichiers).

---

### 🔴 M-04A — `CAISSE_V1_PAYMENT_LEDGER_FULL_2026-04-25` (GATE_PAYMENT_LEDGER_V1=A + GATE_SCHEMA + GATE_FROZEN)

**But** : ledger complet `pending|authorized|captured|refunded|voided|failed`, idempotency par callback, audit immuable, Stripe cents fix si actif.

**Allowlist** :

- `database/migrations/YYYY_MM_DD_create_payment_ledger.php` (NEW)
- `database/migrations/YYYY_MM_DD_create_payment_transactions.php` (NEW)
- `app/Models/PaymentLedger.php` (NEW), `app/Models/PaymentTransaction.php` (NEW)
- `app/Services/Payment/PaymentLedgerService.php` (NEW), `app/Services/Payment/PaymentStateMachine.php` (NEW)
- `app/Services/PaymentService.php` (modify, frozen — gate)
- `app/Http/Controllers/Frontend/OrderController.php` (refactor `paymentConfirm`, frozen — gate)
- `tests/Feature/Payment/PaymentLedgerStateMachineTest.php`, `PaymentLedgerIdempotencyTest.php`, `PaymentLedgerRefundTest.php`, `PaymentLedgerVoidTest.php`, `StripeCentsConversionTest.php`.

**Tests obligatoires** : 5 tests ci-dessus + `PaymentConfirmAbilitySentinelTest` doit passer en VERT après mission.

`**SYMMETRY_NOTE`** : si `PaymentService` ou `OrderController::paymentConfirm` touche `OrderService`/`FrontendOrderService` → revue obligatoire.

**Rollback** : flag `payment_ledger_v1=off` ; runbook dans `docs/runbooks/PAYMENT_LEDGER_ROLLBACK.md`.

---

### 🔴 M-04B — `CAISSE_V1_PAYMENT_PILOT_RESTRICT_2026-04-25` (GATE_PAYMENT_LEDGER_V1=B)

**But** : refus serveur explicite hors pilote, UI désactivée, audit attempts, *aucun* branchement silencieux par `.env`.

**Allowlist** : `app/Services/PaymentService.php` (frozen — gate), `app/Http/Requests/PaymentMethodRequest.php` (NEW), routes guard, `config/payment.php`, tests `PaymentMethodRestrictedTest.php`, `PaymentMethodAttemptAuditTest.php`.

---

### 🔴 M-05 — `CAISSE_V1_ORDER_QUOTE_V1_2026-04-25` (GATE_SCHEMA)

**But** : `OrderQuoteService` autoritaire, **HMAC-SHA256** sur empreinte intent (branch + actor + items + modifiers + discounts + taxes + currency + service fees), **TTL 60s** par défaut, **idempotency consume** (replay = même réponse), **rejet altération = 401**.

**Logique GPT** (max intelligence) :

- Empreinte canonique : tri lexico des keys, JSON normalisé, encodage UTF-8 NFC, secret par device.
- Edge cases obligatoires : fuseau horaire, arrondi monnaie, items indisponibles depuis quote, multi-branch, change devise.
- **Total backend = seul payable** : POS/kiosk paient `quote.total_ttc`, jamais `form.total`.

**Allowlist** :

- `database/migrations/YYYY_MM_DD_create_order_quotes.php` (NEW)
- `app/Models/OrderQuote.php` (NEW)
- `app/Services/Order/OrderQuoteService.php` (NEW)
- `app/Services/OrderService.php` (modify — REWORK: validate/consume quote during POS commit)
- `app/Services/FrontendOrderService.php` (modify — REWORK: validate/consume quote during kiosk commit)
- `app/Http/Controllers/Admin/PosController.php` (modify — NEW endpoint `POST /api/admin/pos/quote`)
- `app/Http/Controllers/Frontend/OrderController.php` (modify — preserve quote commit HTTP errors)
- `routes/api.php` (modify — ajout route)
- `app/Services/PricingService.php` (read seulement — *PAS DE MODIFICATION* sans gate frozen)
- `resources/js/store/modules/kioskCart.js` (modify — pass quote token/signature to kiosk order submit)
- `resources/js/components/admin/pos/PaymentComponent.vue` (modify — lit `quote.total_ttc` ; **interdit pendant `GATE_PAYMENT_PROP_MUTATION` non signé** — donc ce volet attend M-21b)
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (modify symétrique)
- Tests : `QuoteExpirationTest.php`, `QuoteTamperTest.php`, `QuoteReplayIdempotencyTest.php`, `QuoteCurrencyOriginTest.php`, `QuoteDiscountAuthoritativeTest.php`.

**SYMMETRY_NOTE (REWORK GPT final 2026-04-25)** : toute quote créée par `POST /quote` doit être revalidée et consommée dans le commit réel sur les deux surfaces (`OrderService::posOrderStore` pour POS, `FrontendOrderService::myOrderStore` pour kiosk). Le commit rejette les quote expirées/tamper/cross-branch et attache `consumed_order_id`.

**Critère** : sentinels M-02 #15 (subtotal forgery) passe vert.

**Rollback** : flag `quote_v1=off` (max 7j).

---

### 🔴 M-06 — `CAISSE_V1_POS_REVENUE_GUARDS_2026-04-25` (GATE_VERIFY_P0_FROZEN + GATE_FROZEN_ZONES)

**But** : durcir `payment-confirm`, route POS cash dédiée, course cleanup/confirm, no-op side effects, anti-forge discount.

**Sous-tâches** (chaque sous-tâche = un commit isolé) :

1. `**payment-confirm` ability** : créer `app/Http/Requests/Frontend/PaymentConfirmRequest.php` ; ability check `kiosk:order` (Sanctum token abilities) ; resolver `KioskMachine` → `branch_id` réel ; vérification `order.payment_method` matche request ; vérification `order.branch_id == machine.branch_id`. Ancrage : `OrderController.php:85-118`.
2. **POS collect kiosk cash** : nouvelle route `POST /api/admin/pos/collect-kiosk-cash/{order}` + handler dédié ; **dépréciation** de l'usage de `kds-order/change-status` pour collecte cash (sentinel #14).
3. **Cleanup race** : si `CleanupStalePendingKioskOrders` a marqué REJECTED et que `paymentConfirm` arrive → 422 + audit log `payment_late_after_cleanup` + flag réconciliation TPE.
4. **No-op side effects** : `OrderService::changeStatus` (L1489) — guard idempotent : si statut déjà = target, **pas** de cashback / refund / dispatch (ancrage L1505/L1568/L1574).
5. **Discount anti-forge** : `PosOrderRequest` ne décide plus de la permission discount sur subtotal client ; `PricingService` recalcule, `PosController` applique permission sur subtotal backend.

**Allowlist (frozen — gate)** :

- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Services/FrontendOrderService.php` (`finalizePaidKioskOrder`)
- `app/Services/OrderService.php` (`changeStatus`, `changePaymentStatus`)
- `app/Services/PaymentService.php` (`cashBack`)
- `routes/api.php` (nouvelle route POS)
- `app/Jobs/CleanupStalePendingKioskOrders.php`
- `app/Http/Requests/Frontend/PaymentConfirmRequest.php` (NEW)
- `app/Http/Requests/PosOrderRequest.php`
- Tests : `PaymentConfirmAbilityTest.php`, `PaymentConfirmMachineResolverTest.php`, `PaymentConfirmCrossBranchTest.php`, `OrderStatusNoopSideEffectsTest.php`, `PaymentNoopIdempotencyTest.php`, `CleanupVsConfirmRaceTest.php`, `PosCollectKioskCashRouteTest.php`, `PosDiscountForgeryTest.php`.

`**SYMMETRY_NOTE`** obligatoire : OS et FOS tous deux touchés → revue M-10 enchaînée.

**Rollback** : flag `pos_revenue_guards=off`.

---

### 🟠 M-06b — *sous-tâche* `PaymentComponent` refactor (GATE_PAYMENT_PROP_MUTATION)

Rebadge du `LOT-6` du master finitions POS/KDS (`POS_V4_W2_PAYMENT_REFACTOR_2026-04-26`). Cf. `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md:215-253`. Ancrage des 16+ mutations en §2.6 ci-dessus. **Exécutant PRIMARY** : `codex-extension` (mettre à jour le champ `PRIMARY_MODEL` du LOT-6 si désaligné).

---

### 🔴 M-07 — `CAISSE_V1_KDS_RELEASE_TRANSITIONS_2026-04-25` (GATE_KDS_BUMP_V1)

**But** : whitelist `OrderStatus` stricte côté request, `expected_status` obligatoire dans le body, prédicat `KitchenRelease`, pagination overflow visible.

**Logique GPT** :

- `KdsOrderStatusRequest` (NEW) : enum `in:ACCEPT,PREPARING,PREPARED` ; body `expected_status` requis ; comparaison côté service (`L122` actuel) bascule sur **valeur du body** au lieu du modèle (cf. §2.4 ci-dessus — *manque actuel*).
- `OrderStateMachine::isReleasedToKitchen()` formel (NEW) — règle : `status >= ACCEPT && payment_status == PAID` (sauf cash POS où release immédiate).
- KDS pagination : si > 50 → bandeau alerte + lien « voir plus » (ancrage `KitchenDisplaySystemComponent.vue:786-793`).
- Multi-écran : `expected_status` empêche bump fantôme.

**Allowlist** :

- `app/Http/Requests/Kds/KdsOrderStatusRequest.php` (NEW)
- `app/Services/KitchenDisplaySystemOrderService.php` (modify L117-168)
- `app/Domain/Order/OrderStateMachine.php` (modify — ajouter `isReleasedToKitchen()`)
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` (route bind nouvelle request)
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (modify — envoyer `expected_status`, banner overflow)
- `resources/js/store/modules/kds.js` (NEW ou modify — passer `expected_status`)
- Tests : `KdsTransitionWhitelistTest.php`, `KdsExpectedStatusConflictTest.php`, `KitchenReleaseRuleTest.php`, `KdsPaginationOverflowTest.php`, `KdsMultiScreenPlaywrightTest.spec.js`.

**Rollback** : flag `kds_strict_release=off`.

---

### 🔴 M-08 — `CAISSE_V1_FISCAL_Z_NF525_2026-04-25` (GATE_FISCAL_KIOSK_V1 + GATE_SCHEMA)

**But** : implémenter politique fiscal kiosk retenue (A direct, B POS finalize, C bloquer paid kiosk V1) ; Z agg, refund pré/post-Z, **HMAC chain**, NF525 mapping.

**Allowlist** : `app/Services/Fiscal/ZReportService.php`, `app/Services/Fiscal/FiscalSealingService.php` (HMAC), migrations fiscales, `FrontendOrderService.php` (`finalizePaidKioskOrder` routing), tests : `ZAggregationKioskRoutingTest.php`, `RefundPreZTest.php`, `RefundPostZTest.php`, `VoidPreZTest.php`, `FiscalSealingHmacTest.php`, `FiscalArchiveTtlTest.php`, `tests/Feature/Sentinels/FiscalZBranchExactnessSentinelTest.php` (mandatory sentinel fixture alignment: rows must be fiscalized with `fiscal_sequence_no`).

**Rollback** : flag `fiscal_z_v1=off` (max 24h, fiscal critique → escalade humaine immédiate).

---

### 🔴 M-09 — `CAISSE_V1_BRANCH_ISOLATION_2026-04-25` (GATE_FROZEN)

**But** : éliminer les fuites LIKE listées §2.3 et formaliser policy `branch_id=0`.

**Tâches précises** :

1. `OrderService.php:151,194,230,267` — détecter `branch_id` dans `$orderFilter` et basculer en `where('branch_id', '=', $value)` strict ; conserver LIKE pour les autres champs textuels.
2. `OrderService.php:1920` — `salesReportOverview` `else`-branch : exact.
3. `FrontendOrderService.php:99` — exact.
4. Audit `branch_id=0` : `posOrderStore:610`, `destroy:1793-1795` — formaliser policy (admin global only) et tests `OssAdminBranchPolicyTest`.
5. Static lint : règle `phpcs`/`grep` CI bloquant `where('branch_id', 'like'`.

**Allowlist** : `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`, `tests/Feature/Branch/`* (NEW), `scripts/lint-fk-branch-isolation.sh` (NEW).

**Tests obligatoires** : sentinels #7-#11 passent vert.

`**SYMMETRY_NOTE`** : OS et FOS touchés.

---

### 🔴 M-10 — `CAISSE_V1_OS_FOS_SYMMETRY_2026-04-25`

**But** : tableau de correspondance des méthodes (création, statut, paiement, annulation), tests de contrat *golden response*. Voir §2.2 — `changePaymentStatus` absent FOS, divergence `cashBack`/`refundPoints` à formaliser.

**Allowlist** : `tests/Feature/Symmetry/OrderServicesContractTest.php` (NEW), `docs/orchestration/OS_FOS_SYMMETRY_MATRIX_2026-04-25.md` (NEW). Code produit *seulement* si gap critique détecté → escalade gate.

---

### 🔴 M-11 — `CAISSE_V1_KIOSK_RUNTIME_2026-04-25` (GATE_OFFLINE_SCOPE_V1 + GATE_FISCAL_KIOSK_V1)

**But** : remplacer `status: 16` littéral (`KioskWaitingComponent.vue:392`) par enum ; prefix `offline`_ strict sur tout ID (cf. §2.5) ; selon gate offline = A : refus CB/TR offline (UI grisée + serveur refuse 422) ; selon gate offline = B : queue signée ledger ; parité preview promo / checkout.

**Allowlist** : `resources/js/components/frontend/kiosk/*.vue`, `resources/js/store/modules/kioskCart.js`, `resources/js/helpers/kioskOfflineQueue.js`, `app/Http/Controllers/Frontend/OrderController.php` (refus offline CB selon gate), tests Vitest + Playwright sentinels #17-#18.

---

### 🟢 M-12 — `CAISSE_V1_LEGACY_GUARDS_CI_2026-04-25` (NO-GATE)

**But** : guards CI bloquants pour les chemins legacy (`kiosk_implementation/`, `borne (Remix)/`, `pos-wizard.js`).

**Allowlist** : `scripts/lint-fk-legacy.sh` (NEW), `scripts/scan-bundle-legacy.sh` (NEW), `.github/workflows/legacy-guards.yml` (NEW), `eslint.config.`* (modify règle), `phpcs.xml` (modify).

---

### 🔴 M-13 — `CAISSE_V1_MIGRATIONS_SAFETY_2026-04-25` (GATE_SCHEMA)

**But** : dry-run + rehearsal staging full-volume + backup + Up/Down testés + runbook par migration.

**Allowlist** : runbooks `docs/runbooks/MIGRATIONS_*.md`, scripts `scripts/db/dry-run.sh`, `scripts/db/rehearsal.sh`, `scripts/db/backup.sh`, tests `MigrationDryRunTest.php`, `MigrationRollbackTest.php`.

---

### 🟠 M-14 — `CAISSE_V1_OPS_PREFLIGHT_2026-04-25` (NO-GATE après M-13)

**But** : preflight queue/scheduler/workers/broadcast/cache/outbox/fiscal archive ; dashboards (payment success rate, KDS latency, fiscal Z, branch leak counter, queue depth, worker errors) ; alerting + on-call.

**Allowlist** : `scripts/ops-preflight-caisse-v1.sh` (NEW), `app/Console/Commands/PreflightProductionCommand.php` (NEW), `config/horizon.php` (modify), dashboards configs, tests `OpsPreflightCaisseV1Test.php`, `AfterCommitDispatchTest.php`, `OutboxRescueTest.php`.

---

### 🟠 M-15 — `CAISSE_V1_ROLLOUT_CANARY_2026-04-25` (NO-GATE après M-04+M-08)

**Flags** : `payment_ledger_v1`, `pos_revenue_guards`, `kds_strict_release`, `quote_v1`, `fiscal_z_v1`, `kiosk_offline_strict`.

**Canary** : 1 branche pilote → 10% → 50% → 100%. **Rollback predicates** : `payment_success_rate < 95% / 5min` ; `fiscal_anomaly > 0` ; `kds_error_rate > 5%`.

---

### 🟢 M-16 — `CAISSE_V1_HARDWARE_QUALIFICATION_2026-04-25` (NO-GATE)

**But** : checklist TPE, ESC/POS printer, drawer, kiosk hardware (touchscreen, NFC, scanner), tablet POS (Wi-Fi/4G failover, sleep recovery). Sortie : `reports/hardware/CAISSE_V1_HARDWARE_QUALIF_2026-04-25.md` signé humain.

---

### 🔴 M-17 — `CAISSE_V1_WEB_STRIPE_SCOPE_2026-04-25` (GATE_WEB_PAYMENT_SCOPE + GATE_STRIPE_CENTS)

**But** : selon gate, désactiver chemins publics (`/payment/{order}/pay` raw id) **ou** sécuriser via `PaymentIntent` signé + Stripe cents fix.

---

### 🟢 M-18 — `CAISSE_V1_TEST_ARCHITECTURE_2026-04-25` (NO-GATE)

**But** : grille de couverture POS/Kiosk/KDS (PHPUnit/Vitest/Playwright/charge) ; cibles minimales POS 80%, KDS 80%, Kiosk 70%.

---

### 🟢 M-19 — `CAISSE_V1_MEMORY_DISCIPLINE_2026-04-25` (NO-GATE)

**But** : procédure Graphiti + fallback `memory/INDEX.md` ; ingest CLOSE via `bash scripts/after-execute-memory.sh` ; verify `python3 memory/verify.py` (≥ 175).

---

### 🟢 M-20 — `CAISSE_V1_RUNBOOKS_SKELETON_2026-04-25` (NO-GATE)

**But** : squelette `docs/runbooks/CAISSE_V1`_* (ORDER_FLOW, BUSINESS_RULES, AUTHZ_MATRIX). Pas de contenu inventé — pointeurs vers code/services.

---

### 🟢 M-21a — *quickwins LOT-0* (NO-GATE)

Rebadge de `POS_KDS_FINITIONS_LOT0_QUICKWINS_2026-04-26` :

- **FIND-01** : `v-model="discountReason"` à ajouter dans `PosComponent.vue` (cf. §2.7 — actuellement absent du template).
- **FIND-09** : `<Swiper :dir="swiperDir">` dans `KitchenDisplaySystemComponent.vue:130`.

**Exécutant PRIMARY** : `codex-extension` (alignement `AGENTS.md` finishing cycles).

---

### 🟠 M-21b — *finitions UX restantes* (mix gate / no-gate)

Mappe `LOT-2`, `LOT-5a`, `LOT-3`, `LOT-7`, `LOT-8` du master finitions. Détail : `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md`.

---

### 🟠 M-22 — `CAISSE_V1_POST_LAUNCH_OBSERVABILITY_2026-04-25` (NO-GATE après M-15)

**But** : KPI LCP POS/kiosk/KDS, anomaly rules (payment-confirm sans ability, branch crossover, no-op double-trigger, Z mismatch, sceau invalid), cadence post-mortem J+1 / J+7 / J+30.

---

## 5. Template `missions/<TASK_ID>/input.json` — *à coller pour CHAQUE M-XX*

```json
{
  "task_id": "<TASK_ID>",
  "plan_file": "plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md",
  "mission_id": "<M-XX>",
  "primary_model": "codex-extension",
  "model": "gpt-5.5-pro",
  "reasoning_effort": "xhigh",
  "objective": "<copier l'OBJECTIF de M-XX>",
  "subsystems_touched": [
    {"path": "<file>", "intent": "read|write", "branch_scoped": true, "dispatch_after_commit": true}
  ],
  "subsystems_off_limits": ["<paths frozen sans gate>"],
  "invariants_at_risk": ["pricing-ssot", "order-status-enum", "branch-id-isolation", "dispatch-after-commit", "os-fos-symmetry", "frozen-zones"],
  "gate_conditions": ["GATE_*"],
  "mandatory_tests": [
    "php artisan test --filter=<TestName>",
    "npx vitest run tests/js/<spec>.spec.js"
  ],
  "self_audit_checklist": [
    "0 file outside allowlist touched",
    "0 invariant violated (LIKE on branch_id, status literal, dispatch in tx)",
    "SYMMETRY_NOTE filled if OS/FOS touched",
    "all mandatory_tests green",
    "diff minimal — no opportunistic refactor"
  ],
  "rollback": {
    "feature_flag": "<flag>",
    "max_window_days": 7,
    "predicates": ["payment_success_rate < 95% / 5min", "fiscal_anomaly > 0"]
  },
  "graphiti_query": "<topic>",
  "memory_episode_to_write_on_close": "memory/episodes/caisse_v1_<topic>.jsonl"
}
```

---

## 6. Template `missions/<TASK_ID>/execute_brief.md` — *prompt-discipline GPT*

```
Tu es l'exécuteur FoodKing pour {TASK_ID} ({M-XX} du PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md).

[INVIOLABLE]
1. Lis dans cet ordre : AGENTS.md (parcours obligatoire), missions/{TASK_ID}/input.json,
   plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md (sections 0, 2 — ancrages file:line, et la mission {M-XX}),
   .cursor/ACTIVE_CYCLE.md.
2. Respecte les invariants FoodKing (§0.3 du plan).
3. Touche UNIQUEMENT les fichiers de allowlist. Hors liste → SCOPE_PRESSURE + stop.
4. Ne signe AUCUN gate. Tu peux REDIGER des options ; humain seul approuve.
5. Diff minimal. Pas de refactor opportuniste, pas de renommage, pas d'optimisation collatérale.
6. Si OrderService OU FrontendOrderService est modifié : remplir SYMMETRY_NOTE et examiner l'autre.
7. Tout dispatch d'event/job DOIT être après DB::commit (pas dans la transaction, pas dans finally).
8. Aucun OrderStatus littéral numérique : utiliser l'enum.
9. Aucune requête branch_id avec LIKE — toujours = strict.

[LIVRABLES]
- Code modifié dans allowlist.
- mandatory_tests verts (commandes ci-dessous, lancées via shell).
- missions/{TASK_ID}/output_codex.json : { files_changed[], commands_run[], symmetry_note, gate_drafts[], notes }.
- missions/{TASK_ID}/GPT_SELF_AUDIT_{TASK_ID}.md remplissant self_audit_checklist (PASS/FAIL/EVIDENCE).

[INTERDITS]
- Toucher .cursor/routing.md, AGENTS.md, plans/PLAN_CAISSE_V1_SUPER_MASTER_*.md.
- Approuver un gate (cocher [x] Approved).
- Modifier un fichier hors allowlist sous prétexte « while I'm here ».
- Désactiver un test pour le faire passer.
- Inventer un workaround dans une zone frozen sans gate signé.

[SI BLOCAGE]
Émets ESCALATION dans output_codex.json avec : trigger, fichiers, gate suggéré, alternative envisagée. STOP.
```

---

## 7. Audit Claude — *checkpoint après chaque M-XX*

1. `bash scripts/agent-activity-log.sh tail 20` — vérifier `done`.
2. `bash scripts/foodking-claude-orchestrate.sh context` puis `audit` (ou `audit-brief`). **Fallback** Cursor Task `foodking-planner-orchestrator` + `AUDIT_FALLBACK_REASON:` si terminal HS.
3. Vérifier les 6 invariants §0.3 sur le diff.
4. Vérifier `SUBSYSTEMS_TOUCHED` ⊆ allowlist mission.
5. Vérifier `mandatory_tests` verts dans `reports/post_execute_latest.log`.
6. Émettre `AUDIT_VERDICT: PASS|REWORK|BLOCK`.
7. Si PASS : `npm run codex:final-audit -- {TASK_ID}` → `GPT_FINAL_AUDIT_VERDICT: PASS` requis pour CLOSE.

---

## 8. Calendrier indicatif (*chemin critique = M-03 gates humains*)

```
J0 (immédiat, parallèle) : M-01, M-02, M-12, M-16, M-18, M-19, M-20, M-21a
J0-J5  (humain)          : M-03 — convoquer TL+BE+QA NF525+UX+Product+DBA pour 7 gates `TO_DRAFT` + 2 `PENDING`
J5-J7  (post-gate)       : M-09 (branch isolation) ; M-13 dry-run migrations
J7-J12                   : M-06 (POS guards) en parallèle de M-05 (quote)
J12-J15                  : M-04A xor M-04B (selon gate)
J15-J18                  : M-08 (fiscal Z)
J15-J18                  : M-07 (KDS release)
J18-J20                  : M-10 (symétrie OS/FOS) — clôture après M-06+M-09
J18-J22                  : M-11 (kiosk runtime), M-17 (web/Stripe)
J22-J25                  : M-14 ops, M-15 rollout/canary
J25+ (parallèle dès M-04+): M-21b finitions, M-22 post-launch
```

**Sans bloqueur humain** : ~25 jours dev parallèle.  
**Avec bloqueur** : M-09/M-06/M-05 décalés → mais Vague A finit en 5j parallèles.

---

## 9. Critères GO/NO-GO Caisse V1 (`GATE_GO_NO_GO_CAISSE_V1`)

- M-01 matrice `COMPLETE` ; 0 P0 sans `PLAN-ID`.
- M-02 sentinels rouges → tous verts après missions correspondantes.
- 9 gates **signés** (`GATE_LOG.md`).
- M-04 (A ou B) + M-05 + M-06 + M-07 + M-08 + M-09 + M-10 + M-11 — `AUDIT_VERDICT: PASS` *et* `GPT_FINAL_AUDIT_VERDICT: PASS`.
- M-13 rehearsal staging full-volume OK ; M-14 preflight green ; M-15 canary drill exécuté.
- M-16 hardware report signé.
- Final audit Claude transversal (revue **borne → centrale → POS → KDS → fiscal**) — `PASS`.
- Graphiti ingest CLOSE + verify ≥ 200 facts (cible).

---

## 10. Audit transversal final (Claude — *à la toute fin*)

À l'issue de tous les `M-XX` PASS :

1. **Revue chaîne sync** : OrderIntent (POS/kiosk) → OrderQuote → PaymentProof → KitchenRelease → KDS → Fiscal Z → OSS. Pour chaque maillon, `file:line` + test green référencé.
2. **Revue invariants** : 6 invariants §0.3 — preuve par sentinel et lint statique.
3. **Revue gates** : 9 gates signés, traces `GATE_LOG.md`.
4. **Revue rollback** : drill exécuté, predicates testés, runbooks à jour.
5. **Revue mémoire** : Graphiti facts ingestés ; `memory/episodes/caisse_v1_*.jsonl` à jour.
6. **Verdict** : `CAISSE_V1_GO_LIVE_VERDICT: GO|HOLD` + raison.

---

## 11. Pointeurs (références non rechargées dans les missions, juste citées)

- DAG : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`
- Matrice : `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md`
- Dispute : `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- Review Claude : `reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md`
- Comparaison : `reports/audit/CODEX_CLAUDE_MEGA_PLAN_COMPARISON_CAISSE_V1_2026-04-25.md`
- POS audit : `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md`
- Kiosk audit : `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md`
- Caisse V1 source : `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md`
- Finitions POS/KDS : `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md`
- Gates log : `docs/gates/GATE_LOG.md`
- Cycle : `.cursor/commands/run-cycle.md` + `AGENTS.md`

---

`MASTERPLAY_VERSION: 1.0` · `READY_TO_LAUNCH_PHASE_0: YES` · `READY_FOR_PRODUCT_CODE: NO_UNTIL_GATES`
