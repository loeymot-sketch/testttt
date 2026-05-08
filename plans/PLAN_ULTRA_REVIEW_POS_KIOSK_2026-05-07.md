# PLAN_ULTRA_REVIEW_POS_KIOSK_2026-05-07
**Audit chef d'orchestration — flux prise de commande POS + Kiosk → paiement (simulé)**

> Rôle : Claude orchestrateur (chef d'équipe). Pas de modification code. Plans seulement.
> Méthodologie : GSTACK pipeline (Think → Plan → Build → Review → Test → Ship → Reflect).
> Audit limité à la **technique** : business logic, security, sync, state machine, fiscal, idempotency. **Pas de visuel/UX.**
> Verrouillé sur : **vrais chemins du code** (toutes routes/méthodes inventées par l'agent Explore initial ont été purgées).

---

## 0. CONTEXTE DE L'AUDIT

### 0.1 Périmètre vérifié (post-fact-check)

| Surface | Entrée HTTP | Service | Modèle |
|---|---|---|---|
| **POS** | `POST /api/admin/pos` (PosController::store, throttle:pos-order-create) | `OrderService::posOrderStore` (1888 LOC) | `Order` |
| **POS state** | `POST /api/admin/pos-order/change-status/{order}` | `OrderService::changeStatus` | `Order` |
| **POS payment** | `POST /api/admin/pos-order/change-payment-status/{order}` | `OrderService::changePaymentStatus` | `Order` |
| **Kiosk create** | `POST /api/frontend/order` (throttle:kiosk-orders) | `FrontendOrderService::myOrderStore` (846 LOC) | `FrontendOrder` |
| **Kiosk payment confirm** | `POST /api/frontend/order/{order}/payment-confirm` | `OrderController::paymentConfirm` + `FrontendOrderService::finalizePaidKioskOrder` | `FrontendOrder` |
| **Kiosk cancel** | `POST /api/frontend/order/change-status/{order}` | `FrontendOrderService::changeStatus` | `FrontendOrder` |
| **Fiscal** | `POST /api/admin/fiscal/z-report/{open,close}` | `ZReportService` | `Order` (via table partagée) |

### 0.2 Cas d'usage simulé (zéro hardware)

- Stub TPE retourne toujours `approved=true` — [`KioskPaymentComponent.vue:466-469`](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:466).
- Stub `openDrawer()` retourne `{ok: true}` — bridge Electron absent.
- Stub `printReceipt()` est un no-op si bridge absent.
- L'audit traite la simulation comme un **substitut fonctionnel valide**, mais flagge tout point où le *contrat de simulation* masque un défaut métier (ex. amount echo manquant).

### 0.3 Zones gelées respectées

- `public/js/pos-wizard.js` (5769 LOC) — POS wizard Vanilla JS : audité en **lecture seule** (mémoire `feedback_wizard_popup_pos_protected`).
- `public/css/pos-wizard.css` (1987 LOC) — non touché.
- Gateways de paiement (Stripe/Paypal/Credit), Push Notifications natifs, Admin Analytics, Delivery Boy — confirmés gelés.

---

## 1. MATRICE DES PROBLÉMATIQUES TECHNIQUES (sévérité décroissante)

| ID | Sévérité | Finding | Surface | Plan dédié |
|---|---|---|---|---|
| **F-001** | **P0** | Kiosk orders ne reçoivent **jamais** `fiscal_sequence_no` → exclues des Z reports → **violation NF525** sur tout chiffre d'affaires kiosk | Kiosk | `PLAN_AUDIT_F001_KIOSK_FISCAL_SEQUENCE_2026-05-07.md` |
| **F-002** | **P0** | `payment-confirm` ne vérifie pas l'**amount** retourné par TPE (ni montant attendu vs. tx_ref) → fraude possible dès branchement TPE réel | Kiosk | `PLAN_AUDIT_F002_TPE_AMOUNT_ECHO_2026-05-07.md` |
| **F-003** | **P0** | `payment_status` est posé à `PAID` **avant** encaissement physique (POS espèces & kiosk cash) sans hook de réconciliation Z explicite — risque d'écart caisse → facturation ghost | POS + Kiosk | `PLAN_AUDIT_F003_CASH_RECONCILIATION_2026-05-07.md` |
| **F-004** | **P1** | Annulation kiosk au TPE pousse `status=16 (CANCELED)` **sans `reason`** alors que la state-machine documente reason obligatoire ; `FrontendOrderService::changeStatus` ne l'enforce pas non plus | Kiosk | `PLAN_AUDIT_F004_CANCEL_REASON_ENFORCE_2026-05-07.md` |
| **F-005** | **P1** | Fallback queue number sur `LockTimeout` utilise `microtime % 9999` — non monotonique, collision possible avec séquence légitime du jour | POS + Kiosk | `PLAN_AUDIT_F005_QUEUE_NUMBER_FALLBACK_2026-05-07.md` |
| **F-006** | **P1** | `OrderService::posOrderStore` : check idempotency **hors transaction** ET pas de fallback `QueryException 23000` (kiosk en a un, pas POS) → race produit 422 générique au lieu de retour idempotent | POS | `PLAN_AUDIT_F006_POS_IDEMPOTENCY_PARITY_2026-05-07.md` |
| **F-007** | **P1** | `lockBranchId` calcule un fallback à `0` si Auth perd le contexte → idempotency lock sur branch=0 (collision cross-branche) | Kiosk | `PLAN_AUDIT_F007_KIOSK_LOCK_BRANCH_FALLBACK_2026-05-07.md` |
| **F-008** | **P1** | `confirmBackendPayment` retry 3× puis abandonne ; le TPE a déjà encaissé mais l'order reste PENDING — pas de queue de réconciliation côté backend | Kiosk | `PLAN_AUDIT_F008_PAYMENT_CONFIRM_RECONCILE_2026-05-07.md` |
| **F-009** | **P1** | `processCashPayment` kiosk n'appelle **aucun endpoint backend** post-cash : se base sur le `payment_status=PAID` posé à la création → impossible d'auditer "drawer ouvert mais pas de cash" | Kiosk | `PLAN_AUDIT_F009_KIOSK_CASH_BACKEND_HOOK_2026-05-07.md` |
| **F-010** | **P2** | `BranchScope` ne s'applique pas en console hors `runningUnitTests()` — un job de queue worker sans contexte Auth peut lire cross-branche silencieusement | Transverse | `PLAN_AUDIT_F010_BRANCHSCOPE_QUEUE_CONTEXT_2026-05-07.md` |
| **F-011** | **P2** | SSOT pricing fallback non-SSOT duplique 100 % de la logique de calcul (POS + Kiosk) ; flag `pricing.use_ssot_service` permet drift silencieux entre les 2 paths | POS + Kiosk | `PLAN_AUDIT_F011_PRICING_SSOT_DUPLICATION_2026-05-07.md` |
| **F-012** | **P2** | `OrderService` = 1888 LOC (god class) + `PosComponent.vue` = 2078 LOC + `pos-wizard.js` = 5769 LOC (frozen) → maintenabilité dégradée, tests difficiles | POS | `PLAN_AUDIT_F012_GOD_CLASSES_REFACTOR_2026-05-07.md` |
| **F-013** | **P3** | `finalizePaidKioskOrder` enregistre la transition PENDING→ACCEPT via `recordTransition` (audit-only) sans passer par `OrderStateMachine::allows()` — pattern frozen documenté mais aucun guard sur états terminaux (CANCELED, REJECTED) | Kiosk | `PLAN_AUDIT_F013_FINALIZE_STATE_GUARD_2026-05-07.md` |
| **F-014** | **P3** | Stub TPE simulation **toujours approved=true** ; pas de toggle QA pour forcer `declined` → le path `KioskErrorPaymentRefusedComponent` n'est jamais exercé en dev | Kiosk | `PLAN_AUDIT_F014_TPE_STUB_QA_TOGGLE_2026-05-07.md` |

> **14 findings actionnables. 3 P0, 5 P1, 3 P2, 3 P3.**
> Total estimé d'effort : **~12-18 jours-agent** sur P0+P1 ; P2-P3 répartis dans backlog.

---

## 2. ORCHESTRATION GSTACK — DÉLÉGATION PAR AGENT

### 2.1 Pipeline retenu

```
THINK (Claude — fait)
   ↓
PLAN  (Claude — ce document)
   ↓
BUILD (agents Codex/Cursor — séquentiel par finding)
   ↓
REVIEW (Claude orchestrateur — gate par finding)
   ↓
TEST  (Playwright + PHPUnit — gate obligatoire)
   ↓
SHIP  (commit isolé par finding, label `audit-2026-05-07`)
   ↓
REFLECT (Claude — mise à jour Graphiti `foodking` group)
```

### 2.2 Allocation suggérée

| Agent | Findings | Justification |
|---|---|---|
| **Agent A — Fiscal/NF525** | F-001, F-013 | Domaine sensible HMAC chain ; doit être un seul cerveau pour cohérence |
| **Agent B — Payment integrity** | F-002, F-008, F-009 | Bout-en-bout confirmation TPE ↔ backend ↔ Z |
| **Agent C — Cash reconciliation** | F-003 | Croisement métier opérationnel — peut nécessiter input owner avant code |
| **Agent D — State machine & idempotency** | F-004, F-006, F-007, F-005 | Domain logic + concurrency ; un agent pour cohérence |
| **Agent E — Architecture** | F-010, F-011, F-012 | Refactor — backlog P2/P3 |
| **Agent F — QA enablement** | F-014 | Outil QA, faible risque |

### 2.3 Règles strictes pour les exécutants (non-négociables)

1. **Pas de modification de zones gelées** sans gate explicite Claude orchestrateur. Liste : `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, gateways paiement (Stripe/Paypal/Credit), Push Notifications, Admin Analytics, Delivery Boy.
2. **Tests AVANT code** : chaque finding a un test rouge à écrire avant la correction. Le test doit échouer sur le bug actuel.
3. **Pas de scope drift** : si l'agent découvre un bug adjacent, il l'enregistre dans le PLAN, ne le corrige pas. Bloqué = escalade.
4. **Validation atomique** : un commit par finding. Format `audit(F-XXX): <résumé>`.
5. **Re-audit obligatoire** : après chaque correction P0/P1, Claude orchestrateur revérifie via `grep`/lecture directe avant de marquer `done`.
6. **Documentation Graphiti** : chaque finding clos pousse un node `foodking` (Episode + Entity) avec UUID ↔ commit hash.
7. **Zéro `--no-verify`, zéro skip de hook**, zéro amend. Nouveaux commits seulement.
8. **Frozen-zone override** = `feedback_frozen_zone_override_2026-05-06`-style : autorisation explicite user, séquentiel, tests obligatoires.

### 2.4 Décisions de gates (par cycle)

| Décision | Critère | Action |
|---|---|---|
| **continue** | Test rouge → vert, no regression, branche isolée OK | Merger PR, pousser Graphiti |
| **heal** | Test partiellement vert ou regression sur un test périphérique | Bloquer ce finding, créer un sub-plan correctif (max 3 cycles) |
| **block** | Régression sur Z report, fiscal chain ou pricing SSOT | Stop session, escalade owner |
| **escalate** | Décision business floue (ex. F-003 cash flow design) | Demander input user + référence métier |
| **human** | NF525 reseal, secret rotation, suppression données fiscales | User uniquement |

---

## 3. PHASE BUILD — CALENDRIER PROPOSÉ

| Sprint | Findings ciblés | Durée estimée | Pre-condition |
|---|---|---|---|
| **S1** | F-001 (kiosk fiscal) + F-002 (TPE amount echo) | 4 j | Lecture `FiscalSequenceService` + lock plan business F-003 |
| **S2** | F-003 (cash reconcile) — **gated user** | 2 j (après input) | Décision owner sur opérationnel cash |
| **S3** | F-004 + F-005 + F-006 + F-007 (state/idempotency cluster) | 3 j | S1 mergé |
| **S4** | F-008 + F-009 (payment-confirm reconcile + cash hook) | 3 j | S1 + S2 |
| **S5** | F-010 + F-014 | 1 j | — |
| **Backlog** | F-011, F-012, F-013 | rolling | — |

**Total fenêtre : 13 jours-agent + S2 gating user.**

---

## 4. INVARIANTS POST-CORRECTION (à enforcer)

Après l'audit, ces invariants devront tenir :

1. **NF525-Kiosk** : tout `FrontendOrder` avec `payment_status != UNPAID` possède `fiscal_sequence_no IS NOT NULL`.
2. **NF525-Z** : `Z.aggregate.orderCount == count(orders WHERE branch_id=X AND created_at IN (Z.from, Z.to] AND payment_status != UNPAID)` — aucun écart.
3. **TPE-amount** : `payment-confirm` rejette toute confirmation où `tpe.amount != order.total` (à 1 cent près).
4. **Cancel-reason** : aucune transition vers `CANCELED/REJECTED/RETURNED` sans `reason` non vide en DB (DB constraint + service guard).
5. **Idempotency-parity** : POS et Kiosk ont le même comportement face à un duplicate idempotency_key (return existing 200, jamais 422).
6. **Queue-monotonic** : `queue_number` est strictement croissant pour `(branch_id, date)` ; le fallback ne casse pas l'invariant.
7. **BranchScope-job** : tout job de queue worker traite explicitement `Auth` ou utilise un service `WithBranchContext` qui rejette sans contexte.

---

## 5. OBSERVABILITÉ À AJOUTER

Pour détecter récurrence ou drift post-fix :

| Métrique | Source | Seuil alerte |
|---|---|---|
| `fiscal.kiosk.missing_sequence` | Listener post-payment-confirm | `> 0` |
| `tpe.amount_mismatch_rejected` | `OrderController::paymentConfirm` | `> 0` (toléré 0) |
| `cash.drawer_open_no_payment_status` | Counter | new metric |
| `queue.lock_timeout_fallback_used` | Log warning existant | `> 5/h` |
| `idempotency.collision_caught_22000` | Log info existant | tracking only |
| `state.transition_without_reason` | Listener `OrderStateMachine::recordTransition` | `> 0` |

---

## 6. RÉFÉRENCES VÉRIFIÉES (citations directes du code)

| File | Line | Snippet evidence |
|---|---|---|
| [app/Services/OrderService.php:862](app/Services/OrderService.php:862) | 862 | `$this->order->fiscal_sequence_no = app(FiscalSequenceService::class)->next(...)` |
| [app/Services/FrontendOrderService.php](app/Services/FrontendOrderService.php) | full | **0 occurrence** de `fiscal_sequence_no` dans le fichier (`grep -c = 0`) |
| [app/Services/Fiscal/ZReportService.php:209](app/Services/Fiscal/ZReportService.php:209) | 209 | `->whereNotNull('fiscal_sequence_no')` |
| [app/Http/Controllers/Frontend/OrderController.php:80](app/Http/Controllers/Frontend/OrderController.php:80) | 80-115 | `paymentConfirm` valide `transaction_id` + `card_type` mais **pas `amount`** |
| [resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:471](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:471) | 471 | `kioskHardware.tpeCharge(amountCents, method)` envoyé mais retour ne re-déclare pas amount |
| [resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:545](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:545) | 545 | `axios.post(.../change-status/...) {status: 16}` sans `reason` |
| [app/Services/FrontendOrderService.php:399](app/Services/FrontendOrderService.php:399) | 399 | `microtime(true) * 10) % 9999 + 1` — fallback collisionnable |
| [app/Services/FrontendOrderService.php:125](app/Services/FrontendOrderService.php:125) | 125-126 | `$lockBranchId = ... ?? 0` — fallback branch 0 |
| [app/Services/OrderService.php:553](app/Services/OrderService.php:553) | 553-559 | Idempotency check **hors transaction** |
| [app/Models/Scopes/BranchScope.php:27](app/Models/Scopes/BranchScope.php:27) | 27 | `(!App::runningInConsole() || App::runningUnitTests()) && Auth::check()` |
| [app/Services/FrontendOrderService.php:200](app/Services/FrontendOrderService.php:200) | 200 | `payment_status = PAID` pour kiosk cash sans encaissement |
| [app/Services/OrderService.php:591](app/Services/OrderService.php:591) | 591 | `payment_status = PAID` pour POS unconditionnel |
| [resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:494](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:494) | 494-513 | `processCashPayment` ouvre drawer, pas de call backend |
| [resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:551](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:551) | 551-566 | `confirmBackendPayment` retry 3× + abandon |

---

## 7. NEXT STEPS POUR L'OWNER

1. Lire ce plan + chaque `PLAN_AUDIT_F0XX_*.md` (14 sub-plans).
2. **Décider F-003** (cash reconciliation design) — ce gate bloque S2.
3. Autoriser le sprint S1 (F-001 + F-002) en frozen-zone override style si nécessaire.
4. Désigner les agents (Codex/Cursor) par lettre A→F selon allocation §2.2.
5. Mettre à jour Graphiti `foodking` avec le pointeur `audit_ultra_review_2026-05-07`.

---

## 8. SIGNATURE DU CHEF D'ORCHESTRATION

- **Audit conduit par** : Claude (orchestrator role per CLAUDE.md §2)
- **Date** : 2026-05-07
- **Méthodologie** : GSTACK pipeline + advisor cross-check
- **Évidence** : 100 % référencée, 0 fabrication post-purge agent Explore
- **Mémoire mise à jour** : Graphiti `foodking` group (pending)
- **Décision globale** : `block` sur le périmètre kiosk-fiscal tant que F-001 + F-002 ne sont pas verts. POS reste opérationnel sous monitoring F-003.

— *Vision d'abord, correctness ensuite, vélocité après.*
