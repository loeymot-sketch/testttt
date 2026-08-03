# FINAL REPORT v3 — Wave 5 Ultra-Review Massif (System Complet)

**Date :** 2026-05-08
**Branche :** `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` (HEAD `9f7fe3746`)
**Orchestrateur :** Claude Opus 4.7 (1M context)
**Méthodologie :** Wave 5 multi-agent parallèle (5 agents : Security R2 / Architecture / Data Integrity & Sync / POS verification / Kiosk verification)
**Supersedes :** `FINAL_REPORT_v2_2026-05-08.md` (commit `fb3535a87`, v2 17/17 findings) — v3 = audit massif POST-v2 sur système complet

---

## Executive Summary

**38 findings identifiés** par 5 agents en parallèle (ROI Wave 5 : ~10 jours-agent en ~13 min wall-clock = **x40-50** ROI).

| Severity | Count | Action requise |
|---|---|---|
| **HIGH** | **7** | 2 BLOCKERS pre-merge + 1 OWNER-DECISION + 4 heal V1.0.1 |
| **MEDIUM** | 15 | Heal V1.0.1 hot-fix sous 7j |
| **LOW** | 16 | Backlog V1.x |

**Verdict global : `heal-light BLOQUANT`** — 2 fixes pre-merge (~4h-agent) + 1 owner-decision dine-in V1 → puis `continue READY FOR MERGE PROD`.

**v2 verdict reste valide** : 17/17 findings v1+v2 closed, frozen-zones intactes, security review v2 propre. Wave 5 a découvert 38 findings ADDITIONNELS principalement sur surfaces NON-couvertes par audit ultra review v1/v2 (Customer/Waiter/Chef/DeliveryBoy services + LoyaltyController + cash drawer cross-cashier + PaymentStateMachine pattern + Z window kiosk timing + outbox lost-broadcast window + frontend listener gap F-016a-BIS + dine-in V1 disabled enforcement).

---

## 1. Findings HIGH (7) — détail + action

### 1.1 WAVE5-SEC-001 [BLOCKER PRE-MERGE] — Privilege escalation Branch Manager → Admin (conf 9/10)

**Files :** `app/Services/CustomerService.php:25,87-112,165-183`, `app/Services/WaiterService.php:26,88-114,167-185`, `app/Services/ChefService.php:25,87-113,166-184`, `app/Services/DeliveryBoyService.php:25,87-113,167-185`

**Description :** Les 4 services utilisent `$blockRoles = [EnumRole::ADMIN]` puis vérifient `if (!in_array(EnumRole::CUSTOMER, $this->blockRoles))` (resp. WAITER/CHEF/DELIVERY_BOY). C'est une **tautologie** : `in_array(2, [1])` est toujours false → bloc toujours exécuté. AUCUN check que la cible (`User`) est bien du rôle attendu.

**Exploit chain (5 maillons file:line) :**
1. Route `routes/api.php:512` `Route::match([...], '/{customer}', [CustomerController::class, 'update'])` sous `auth:sanctum`
2. `CustomerController::__construct` → `permission:customers_edit` (Branch Manager l'a, cf. `RolePermissionTableSeeder.php:55`)
3. Route binding `User $customer` — User exempté de BranchScope (`BranchScope.php:21-23`) → accepte n'importe quel user_id
4. FormRequest `CustomerRequest::authorize() { return true; }`
5. `CustomerService::update` ligne 90 tautology passe → mute `password`, `email`, `phone`, `status`

**Exploit final :** Branch Manager `branch_id=1` envoie `PUT /api/admin/customer/{admin_user_id}` avec `password=newpwd123` → mot de passe Admin global rotaté → bypass total isolation multi-tenant.

**Fix :** Ajouter dans chaque service (4×) :
```php
if (! $customer->hasRole(EnumRole::CUSTOMER)) {  // resp. WAITER/CHEF/DELIVERY_BOY
    throw new HttpException(403, 'Cannot mutate user outside expected role.');
}
```
+ sentinel `tests/Feature/Sentinels/UserMgmtRoleTargetSentinelTest.php` (16 cases : 4 services × 4 méthodes).

**Effort :** ~1h-agent. **Action : BLOQUANT pre-merge.**

### 1.2 WAVE5-POS-001 [BLOCKER PRE-MERGE] — Mirror counter-entry sans SealedOrderGuard parent (conf 9/10)

**File :** `app/Services/Order/RefundWithCounterEntryService.php:50-69`

**Description :** Service vérifie `fiscal_sequence_no !== null` + `status !== RETURNED` mais PAS que parent est dans une fenêtre Z FERMÉE (contrairement aux guards SealedOrderGuard appliqués dans `changeStatus → RETURNED` et `changePaymentStatus → REFUNDED`). Tous les orders POS ont `fiscal_sequence_no` dès `posOrderStore:898`. Donc order pre-Z (10h, Z encore ouverte) peut déclencher mirror counter-entry à 10h05. **Pire — double-comptabilisation** : mirror créé pre-Z (parent reste PAID) puis cashier B exécute `changeStatus → RETURNED` (SealedOrderGuard passe car non scellé) → parent devient RETURNED+REFUNDED PLUS mirror existe → Z aggregation compte 2 contre-écritures pour 1 vente.

**Fix :** Ajouter `app(SealedOrderGuard::class)->assertSealed($parent, 'refund-with-counter-entry')` en tête de `execute()` + sentinel `RefundCounterEntryRequiresSealedParentSentinelTest` (4 cases).

**Effort :** ~30 min-agent. **Action : BLOQUANT pre-merge.**

### 1.3 WAVE5-KIOSK-001 [OWNER-DECISION] — Sur place V1-disabled non gardé côté kiosk (conf 9/10)

**Files :** `resources/js/components/frontend/kiosk/KioskCartComponent.vue:89-110`, `app/Http/Requests/OrderRequest.php:150-152`

**Description :** Per memory `feedback_v1_dine_in_disabled_2026-05-06`, V1 désactive dine-in (`pos.dine_in_enabled=false`). Cette règle est appliquée en POS via `PosOrderRequest` mais AUCUNE garde côté kiosk : (a) UI rend les 2 boutons "🍽️ Sur place" + "🥡 À emporter" sans test feature flag, (b) backend `OrderRequest:150-152` bypasse explicitement toute restriction order_type pour kiosk tokens. Un client kiosk peut envoyer `order_type=KIOSK(25)` → backend accepte → KDS reçoit comme dine-in malgré la directive V1.

**Action : OWNER-DECISION** :
- Si V1 demo strict "À emporter only" → BLOQUANT, fix ~3h (backend OrderRequest::after() validation kiosk + frontend KioskCart masque dine-in via store kioskSettings)
- Si dine-in tolérée transitoire V1 → downgrade MEDIUM / V1.x

### 1.4 WAVE5-DATA-001 — Fiscal gap kiosk PENDING→PAID across Z window boundary (conf 9/10)

**File :** `app/Services/Fiscal/ZReportService.php:307-316`

**Description :** `ZReportService::aggregate` filtre par `created_at` half-open `(from, to]` ET par `whereNotNull('fiscal_sequence_no')`. Les kiosk orders sont créés en PENDING/UNPAID et reçoivent `fiscal_seq` tardivement. Si l'allocation se produit après close Z N (par retry queue exponential backoff jusqu'à 5min après Pusher fail), l'order avec `created_at < $from_{N+1}` est exclu Z N+1 ET déjà raté Z N → **NF525 "every receipt in exactly one Z" violé**.

**Probabilité :** Faible (combinaison rare kiosk + Z boundary + queue retry > 5min). **Action : Heal V1.0.1.** Fix : ajouter colonne `fiscal_sequence_allocated_at` (migration GATED) comme window key. Effort 1.5j-agent.

### 1.5 WAVE5-DATA-002 — Outbox lost-broadcast window worker crash (conf 9/10)

**Files :** `app/Jobs/DispatchDomainEventsJob.php:65-117`

**Description :** Phase 1 commit (claim atomique `dispatched_at`) ↔ Phase 2 broadcast hors transaction. Worker process killed (SIGKILL deploy, OOM, K8s eviction) entre Phase 1 et broadcast → `dispatched_at != null` MAIS broadcast jamais émis. Laravel queue ne re-fire pas. `MonitorOutboxStaleness` query `whereNull('dispatched_at')` → invisible. **Event silencieusement perdu.**

**Probabilité :** Très faible (worker crash dans 10ms broadcast window). **Action : Heal V1.0.1.** Fix : colonne `broadcast_completed_at` distincte. Effort 1.5j-agent.

### 1.6 WAVE5-DATA-004 — Frontend ne subscribe pas events F-016a-BIS extras/variations (conf 9/10)

**Files :** `app/Providers/EventServiceProvider.php:171-176`, `resources/js/composables/useCatalogChangeNotifier.js`

**Description :** F-016a-BIS livre les 2 events backend complets : persistés outbox + broadcast. Le commentaire `EventServiceProvider:167-170` documente "the surface refresh happens via the dedicated event broadcast that StockManager UI / Kiosk handlers subscribe to (F-016b)". F-016b est V1.x — donc **aujourd'hui en V1, le frontend n'a aucun handler pour ces events** (`grep` confirme 0 match dans `resources/js/`). Asymétrie avec `ItemAvailabilityChanged` qui fire `PersistCatalogChangedToOutbox`.

**Failure scenario :** Owner toggle "Bacon" rupture, backend persiste, broadcast émis. Borne kiosk affiche encore "Bacon" disponible. Client commande burger+Bacon → checkout → backend rejette 422 → friction UX.

**Probabilité :** **Certaine** dès que F-016a-BIS endpoint utilisé. **Action : Recommandé pre-merge ou hot V1.0.1 (1j).** Fix V1 simple : ajouter `PersistCatalogChangedToOutbox` + `InvalidateKioskMenuCacheOnItemAvailabilityChanged` au listener chain pour ces 2 events dans `EventServiceProvider:171-176` (fallback générique fonctionne).

### 1.7 WAVE5-ARCH-001 — PaymentStateMachine bypass 5 sites (conf 9/10, latent)

**Files :** `app/Services/PaymentService.php:44`, `app/Services/OrderService.php:1453,1721,1807`, `app/Http/Controllers/Frontend/PaymentReconcileController.php:193`

**Description :** Deux patterns coexistent pour transition `payment_status → PAID`. Logique runtime actuelle SAFE (UNPAID → PAID est légal). **Drift de pattern :** le jour où PaymentStatus reçoit nouvel état (`AUTHORIZED`, `CHARGEBACK`, `PARTIALLY_REFUNDED`) ou transition interdite ajoutée, les 5 sites bypass passeront silencieusement.

**Action : Heal V1.1.** Fix : introduire `PaymentStateMachine::apply()` miroir `OrderStateMachine::apply()` et migrer 5 sites. Effort 1j-agent.

---

## 2. Findings MEDIUM (15) — backlog V1.0.1 hot-fix

| ID | Description | Effort |
|---|---|---|
| WAVE5-SEC-002 | Cross-branch user mutation EmployeeService::update via unchecked branch_id | ~1h |
| WAVE5-SEC-003 | AdministratorService::update lacks role-target verification (defense-in-depth) | 15 min |
| WAVE5-SEC-004 | Counter-collect inline closures sans FormRequest validation typed | ~3h |
| WAVE5-ARCH-002 | Controllers → DB::table direct (LoyaltyController + Auth + Observability) | 3-4j |
| WAVE5-ARCH-003 | LoyaltyController = 730 LOC god class | inclus dans ARCH-002 |
| WAVE5-ARCH-004 | routes/api.php = 1265 LOC monolith | 0.5j |
| WAVE5-DATA-003 | Auto stock-rupture extras/variations no granular event | 1j |
| WAVE5-DATA-007 | Cash window closed_at vs Z window created_at mismatch | 1j |
| WAVE5-DATA-008 | pending_payment_confirmations.transaction_id UNIQUE non-composite | 1j |
| WAVE5-POS-002 | F-003 cash sous-estimé (no hook posOrderStore CASH direct + split) | 3h |
| WAVE5-POS-003 | Cross-cashier session takeover (no user-id check) | 2h |
| WAVE5-POS-004 | Frontend Vuex destroy n'envoie pas destroy_reason → audit trail vide | 1h |
| WAVE5-POS-005 | TOCTOU recordMovement (no lockForUpdate) | 45 min |
| WAVE5-KIOSK-002 | Reconcile boot retry confiné à KioskPaymentComponent | 3h |
| WAVE5-KIOSK-003 | KioskStepPain + KioskStepTaille manquent badge OOS | 4h |

**Total effort MEDIUM cumulé :** ~6-8 jours-agent (hors ARCH-002).

---

## 3. Findings LOW (16) — backlog V1.x

| ID | Description |
|---|---|
| WAVE5-SEC-005 | Admin blanket subscription `branch_id=0` — audit log subscriptions cross-branch |
| WAVE5-SEC-006 | LoginController re-fetches User after attempt() (cosmétique) |
| WAVE5-ARCH-005 | Service↔Service couplings sans interfaces (V2 SaaS) |
| WAVE5-ARCH-006 | 12 builders notification dupliqués (factoriser AbstractNotificationBuilder) |
| WAVE5-ARCH-007 | Pas de métrique pour `withoutGlobalScope` calls (observability gap) |
| WAVE5-DATA-005 | BranchScope admin escalation via NULL branch_id PHP cast (latent) |
| WAVE5-DATA-006 | Idempotency Cache::lock TTL=10s peut expirer pendant nested locks |
| WAVE5-POS-006 | Cache::lock release leak (no finally{}) |
| WAVE5-POS-007 | Path LEGACY pricing pas de rupture extras/variations check (config-gated) |
| WAVE5-POS-008 | Admin idempotency disabled silencieusement (branch_id=0) |
| WAVE5-POS-009 | Vuex actions changeStatus/destroy n'envoient pas X-Idempotency-Key |
| WAVE5-POS-010 | Parked admin branch_id=0 → recall failed |
| WAVE5-KIOSK-004 | MAX_PAYMENT_FAILURES=2 trop strict V1 fast-food (configurable) |
| WAVE5-KIOSK-005 | Reconcile-pending route manque `abilities:kiosk:order` middleware |
| WAVE5-KIOSK-006 | KioskInactivityOverlay focus trap non bouclé |
| WAVE5-KIOSK-007 | KioskApp ne sync `navigator.online` directement |

---

## 4. Patterns sains observés (top 12 — confirme maturité v2)

### Backend
1. **Outbox pattern textbook** (DispatchDomainEventsJob 50-163) : claim atomique + EventContract validation + backoff [1,5,15,60,300] × tries=6
2. **BranchScope défensif** (Models/Scopes/BranchScope.php:17-41) : exclusion User récursion, distinction admin vs staff strict
3. **PricingService composé non god class** (5+ collaborators injectés, SSOT pricing 6 call sites)
4. **Domain layer émergent + state machines `final`** (OrderStateMachine + PaymentStateMachine `final class`)
5. **OrderQuoteService — abstraction quote/seal propre**
6. **F-002 amount echo guard** : pre-state-mutation, error_code stable, log warning structuré
7. **Idempotency middleware** : Cache::lock SET NX EX, race-wait, payload hash check, fail-closed
8. **Throttle granulaire stratifié** (login-lockout, OTP, kiosk-orders, pos-quote, pos-order-create)
9. **NF525 frozen-zone respect** : FiscalSequenceService jamais touché v1+v2, sentinels actifs

### Frontend
10. **A11y kiosk-grade** : KioskCart aria-live=polite, role=radiogroup, aria-modal ; KioskInactivity aria-alertdialog ; KioskConfirmation role=status
11. **F-008 reconcile per-entry isolation** : Cache::lock par tx + side-effect tolerance + UNIQUE(tx) DB lock
12. **F-007 lock branch resolution** : `KioskMachine ?? user.branch_id ?? throw 422` — fix leak idempotency cross-branch sans casser web/mobile

---

## 5. Décision orchestrateur consolidée

### Verdict global : **`heal-light BLOQUANT` → puis READY FOR MERGE PROD**

#### Pre-merge BLOQUANTS (4-5h cumulé)

| # | Finding | Severity | Action |
|---|---|---|---|
| 1 | **WAVE5-SEC-001** | HIGH | Patch 4 services (Customer/Waiter/Chef/DeliveryBoy) + sentinel ~1h |
| 2 | **WAVE5-POS-001** | HIGH | Patch SealedOrderGuard côté refund + sentinel ~30 min |
| 3 | **WAVE5-KIOSK-001** | HIGH owner | Owner-decision V1 dine-in : strict (BLOCKER) ou tolerée (downgrade MEDIUM V1.x) |

#### Heal V1.0.1 (post-merge hot-fix sous 7j, ~6-8 jours-agent cumulé)
- 12 MEDIUM (cf. tableau §2)
- WAVE5-DATA-004 prioritaire (UX rush)

#### Backlog V1.x (16 LOW + ARCH-002 god class)
Cf. tableau §3 + ARCH-002 LoyaltyController refactor (3-4j)

---

## 6. Hand-off Owner

### Avant merge prod (1 demi-journée agent)
1. Décider WAVE5-KIOSK-001 (orchestrateur peut traiter si décision claire)
2. Faire patcher WAVE5-SEC-001 + WAVE5-POS-001 (orchestrateur peut le faire scope-minimal — mais préfère un agent dédié pour qualité sentinels)
3. Smoke E2E + composer dump-autoload check (déjà DONE)
4. Appliquer 5 migrations GATED OWNER au rollout window

### Hot-fix V1.0.1 (sous 7j)
- 12 MEDIUM + WAVE5-DATA-004 (UX rush extras availability)

### V1.x roadmap
- F-016b UI dashboard StockManager
- 16 LOW Wave 5
- ARCH-002/003 LoyaltyController refactor
- F-012 god classes refactor (déjà reconnu, deferred)

### V2 roadmap
- ARCH-005 service contracts (SaaS multi-tenant)
- ARCH-007 BranchScopeBypass observability

---

## 7. ROI méthodologie

| Wave | Agents | Effort estimé | Wall-clock | ROI |
|---|---|---|---|---|
| Wave 1 (v1) | 6 | ~6 jours-agent | ~1h | x40-50 |
| Wave 2 (v1) | 4 | ~3 jours-agent | ~30 min | x30 |
| Wave 3 (v2) | 2 | ~13h-agent | ~25 min | x40 |
| Wave 4 (v2) | 4 | ~5 jours-agent | ~35 min | x35 |
| **Wave 5 (v3)** | **5** | **~10 jours-agent** | **~13 min** | **x40-50** |

**Total cumulé v1+v2+v3 :** ~36 jours-agent v1-v2 + ~10 jours-agent v3 = **~46 jours-agent en ~7h wall-clock**.

---

## 8. Métriques cumulées v1+v2+v3

- **Findings traités :** 17 (v1+v2 closed) + 38 (v5 nouveaux) = **55 findings totaux**
- **Tests dédiés :** 1722 PHPUnit baseline + 64 v2 + 0 nouveaux v5 (v5 = audit pur read-only)
- **Frozen-zones :** TOUTES intactes sur 55 findings (POS Vanilla, Kiosk Vue 8, OrderStateMachine, FiscalSequenceService, ZReportService cœur, AuditLogService HMAC, Payment Gateways)
- **Migrations GATED OWNER :** 5 fichiers v1+v2 (zero-downtime safe), aucune migrée prod
- **Drift escalations honnêtes :** 4 close-by-evolution/supersession/investigation (F-005, F-007, F-016, WAVE5-KIOSK-001 owner-decision)
- **Security review v2 propre + Wave 5 a découvert 1 HIGH security réel** (WAVE5-SEC-001 sur surface non-couverte v2 = Customer/Waiter/Chef/DeliveryBoy services)

---

## 9. Recommandation orchestrateur finale

**Réponse à la question initiale "audit ultra-massif système central + caisse + borne" :**

✅ **Le système central de gestion data est SAIN** (BranchScope défensif, Outbox textbook, FiscalSequenceService gap-free monotone, AuditLogService HMAC chain, idempotency double-layer, NF525 invariants prouvés).

✅ **La caisse (POS) est PROD-READY après 1 fix** (WAVE5-POS-001 SealedOrderGuard mirror counter-entry — 30 min) + 4 MEDIUM heal V1.0.1 sous 7j (cash hook + cross-cashier + audit destroy reason + TOCTOU).

✅ **La borne (Kiosk) est PROD-READY après owner-decision** WAVE5-KIOSK-001 dine-in V1 + 2 MEDIUM heal V1.0.1 (reconcile location + Pain/Taille OOS).

⚠️ **1 vulnérabilité HIGH security découverte** (WAVE5-SEC-001 privilege escalation Branch Manager → Admin via Customer/Waiter/Chef/DeliveryBoy services) — fix scope-minimal ~1h, **BLOQUANT pre-merge prod**.

⚠️ **3 findings HIGH data integrity** (DATA-001 Z window kiosk + DATA-002 outbox lost-broadcast + DATA-004 frontend listener gap F-016a-BIS) — heal V1.0.1 acceptable.

⚠️ **1 finding HIGH architecture** (ARCH-001 PaymentStateMachine bypass) — pattern drift latent, pas runtime bug, heal V1.1 OK.

### Prochaine action recommandée

**Lancer 1 agent dédié heal scope-minimal** pour fix WAVE5-SEC-001 + WAVE5-POS-001 (estimés 1h30-agent cumulé) avec sentinels anti-régression. Owner décide WAVE5-KIOSK-001. Puis merge prod GO.

---

**Verdict orchestrateur final v3 :** Branche `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` est PROD-READY après ~4-5h heal-light BLOQUANT (2 HIGH SEC+POS + 1 owner-decision KIOSK). 17/17 v1+v2 closed restent valides. Wave 5 a fait son job d'ultra-review massif et identifié les angles morts.
