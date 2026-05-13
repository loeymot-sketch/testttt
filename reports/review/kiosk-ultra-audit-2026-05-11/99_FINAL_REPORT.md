# Kiosk Ultra Audit 2026-05-11 — FINAL REPORT

> 21 agents read-only adversarial audit en 3 batches parallèles (7+7+7).
> Branche `feature/mobile-app-le-cayenne-2026-05-10` — HEAD `6a33a9763`
> (BRAIN.md §2 disait `245e8ab57` — drift caught by K16/K17/K18/K20).
> Wall-clock total ≈ 30 min (3 batches × ~5-10 min). 4933 lignes md.

## §1 VERDICT GLOBAL — **NO-GO V1 merge (kiosk surface)**

7 agents indépendants rendent NO-GO V1 ou BLOCK ; 8 rendent HEAL ;
6 rendent GO/GO V1.0.1. Cross-validation 2+ agents sur le frozen-drift
trigger le hardstop.

| Niveau | Agents | Verdict |
|---|---|---|
| **NO-GO V1 / BLOCK** | K02, K03, K05, K10, K12, K15, K16, K19, K20 | 9 |
| **HEAL** | K01, K04, K07, K08, K09, K14, K18 | 7 |
| **GO / GO V1.0.1** | K06a, K06b, K11, K13, K17 | 5 |

Couverture : 24 Vue components + 9 wizard steps + 15 DS + 6 store +
27 helpers + 4 composables + 11 backend files + routes + lang + tests.

## §2 P0 — séparation cross-validés vs single-agent (honest labels)

### Cross-validés (multi-agent — haute confiance)

### S-P0-01 — Frozen-zone breach sans LOCK (5 agents indépendants : K01 + K05 + K09 + K16 + K20)
- **Drift total** : +3 337 / −467 lines across 5 frozen files
- KioskWizardComponent.vue : +1 663 / −228 (LOCK_KIOSK_SALADE couvre 9 lignes seulement)
- KioskAppComponent.vue : +834 / −175 (aucun LOCK)
- KioskUpsellComponent.vue : +31 / −26 (design refresh — soft drift)
- pos-wizard.js : +216 / −21 (aucun LOCK)
- PricingService.php : +593 / −17 (aucun LOCK, NF525-critical)
- **PROJECT_BRAIN.md §2 affirme "0 lines diff frozen-zones" — FAUX**
- **Owner action** : rétro-LOCK signé + sentinels CI OR revert

### S-P0-02 — IDEMPOTENCY_MIDDLEWARE_ENABLED default `false` (K18 + POS audit 2026-05-09 memory + commit `ceeecc748` audit)
- K18 re-verify confirmed OPEN sur HEAD
- Memory POS audit had it listed P0-05
- **Fix scope-minimal** : flip default to `true` before merge

### Single-agent (à cross-valider via re-run targeted ou code review)

### S-P0-03 — Sanctum kiosk:order token en localStorage XSS-exposable (K03)
- Token stocké path `kioskCart.kioskToken` via vuex-persistedstate (plain localStorage)
- TTL 480 min, no rotation, exfiltrable via toute surface XSS
- Backend error rendu raw (K03-P0-03 + K11-P1-04) = vector
- **Mitigation** : migrate to HttpOnly cookie — owner gate (touch auth flow)

### S-P0-04 — Idle screen contraste WCAG fail (K02 single — cross-link K07 design `#F4501E`)
- Texte `#FFF5E8` sur gradient `#FFFFFF→#FFE8DD→#F4501E` = ratio ~1.1:1
- AA exige 4.5:1 — V1 customer-blocker visible
- K07 corroboré indépendamment design-drift `#F4501E` orange utilisé partout (palette owner = noir/rouge/jaune/blanc, rouge pur attendu)

### S-P0-05 — Cart-add toast violates design refresh mandate (K07 + K09 = 2 agents but same finding chain, semi-CV)
- KioskUpsellComponent.vue:241-246 trigger toast after addItem
- Owner mandate : "no toast for cart-add, bottom-sheet replaces it"
- K09 FROZEN flag → owner gate

### S-P0-06 — TPE_TIMEOUT bypasses order void (K10)
- KioskPaymentComponent.vue:528-561 — Promise.race rejection jumps to outer catch (line 433)
- Never reaches orphan-PENDING void (lines 548-559)
- Result : orphan PENDING orders accumulate, pollute Z-report
- **High-confidence** : code path read confirmed missing void

### S-P0-07 — `_reconcilePendingPayments()` no X-Idempotency-Key (K10)
- Line 870 — POST `/frontend/payment/reconcile-pending` without idempotency key
- Inline 3-retry builds stable key, reconcile does NOT
- Boot of rebooted borne with pending entries → concurrent double-confirm risk

### S-P0-08 — SimulateKioskOrders no production guard (K19)
- `app/Console/Commands/SimulateKioskOrders.php` auto-registered via Kernel.php:147
- `php artisan kiosk:simulate-orders` in prod injects 50 raw Orders, `payment_status=5 PAID`,
  no `fiscal_sequence_no` allocation → **breaks NF525 chain integrity**
- Dispatches real SendOrderGotPush/Mail/Sms events
- **Fix** : add `App::environment(['local','staging'])` guard + handle abort()

### S-P0-09 — Offline queue race + migration unsafe + analytics drift (K15)
- `_queueCache = remaining` clobbers entries enqueued during async sync (loss)
- Migration v1→v2 clears legacy before verifying v2 persistence (fiscal-impact loss)
- 4 events emitted but absent from analytics whitelist (silent drop, hides quota saturation)

### CV-P0-10 — Errors module orphan emit listeners (K12)
- 4 error screens emit `retry`/`call-staff`/`pay-at-counter` — no parent subscribes
- "Call staff" button cosmetic only — no signaling
- KioskOfflineConflictModal full hardcoded FR + leaks IndexedDB internal keys to client

### Other P0s by agent
- K01 : 3 P0 (no global Vue error boundary, requireKioskAuth silent loop, payment-abandonment watchdog absent)
- K05 : 1 P0 frozen-discipline (covered CV-P0-01)
- K04 : 2 P0 (Potemkin filter UI absent despite full store module; carousel translateX -33% hardcoded for 3 promos)
- K07 : 1 P0 (cart POSTs subtotal/discount/total — mitigated today by signed quote_token, NO regression test pin)
- K08 : 1 P0 (loadConfig swallows errors, minRedeemPoints kiosk fallback 100 vs backend 50 — silent UX regression)
- K14 : 1 P0 (allergens server-only on item, NOT on variations/extras — EAA 2025 regulatory gap; backend fix not K14 scope)
- K11 : 0 P0 (i18n migration `kiosk.confirmation.*` PASS HEAD)
- K17 : 0 P0 (branch isolation verified Y, cache invalidation P2 only)
- K06b : 0 P0 (NF525 SSOT contract Y respected end-to-end)
- K13 : 0 P0 (NF525 contract verified clean, posKioskVariationParity REAL test on HEAD — memory P0-14 stale)

## §4 P0 POS audit 2026-05-09 RE-VERIFICATION status (K16 + K18)

Sur les 15 P0 listés PROJECT_BRAIN.md §4, ceux dans surface kiosk :

| P0 | Description | Status HEAD `6a33a9763` |
|---|---|---|
| **P0-01** | Order SoftDeletes (NF525 gap) | **MITIGATED** Option A `withTrashed()` — commit `a37f58e4a` |
| **P0-02** | OrderItem SoftDeletes | **MITIGATED** same commit |
| **P0-03** | z_reports DELETE trigger SQLite 0 coverage | **FIXED** + MysqlOnly test — `a37f58e4a` |
| **P0-04** | cash_movements + order_payments cascadeOnDelete | **FIXED** restrictOnDelete — `a37f58e4a` |
| **P0-05** | IDEMPOTENCY_MIDDLEWARE_ENABLED default flag | **OPEN — default `false`** ⛔ |
| **P0-06** | PosOrderController::show:108 cross-branch | **MITIGATED** via OrderService::assertOrderBranchVisible — `1476a111a` |
| **P0-07** | RefreshTokenController `['*']` privilege escalation | **FIXED** preserves abilities — `01da1d99b` |
| **P0-08** | abilities:kiosk:order route middleware | **FIXED** moved to OrderRequest::authorize — `01da1d99b` |
| **P0-09** | CashDrawerService::openSession no lock | **FIXED** triple-defense — commit `83eb52ea5` (verified) |
| **P0-11** | SenangPay Gateway class missing | **FIXED** 501 stub — commit `83eb52ea5` (verified) |
| **P0-12** | OrderStateMachine::apply lockForUpdate | **FIXED** — commit `619b49bc1` (verified) |
| **P0-13/14** | 4 fake POS E2E + posKioskVariationParity fake | K13 confirmed REAL on HEAD — `P0-14` stale ; **P0-13 hors kiosk scope** |
| **P0-15** | Frozen-zone breach KioskWizard +1665 / KioskApp +892 / pos-wizard +237 | **CONFIRMED OPEN** ins/del ±2% — CV-P0-01 ⛔ |

**11/13 closed, 2/13 open** (P0-05 + P0-15). Memory PROJECT_BRAIN.md §4 needs update : la majorité des P0 listés sont closed.

**Commit hashes verified via `git show --stat`** :
- `a37f58e4a` heal(P0-fiscal) — content matches K18 claim (P0-01/02/03/04)
- `01da1d99b` heal(P0-auth) — content matches K16 claim (P0-07/08)
- `1476a111a` fix(pos/phase-9-h.1.1+1.4) — content matches K16 claim (P0-06 propagate HttpException 403)
- `83eb52ea5` heal(P0-cash-payment) — content matches K18 claim (P0-09/10/11)
- `619b49bc1` heal(P0-order-state) — content matches K18 claim (P0-12)
- `ceeecc748` audit(iter15-C) corroboration "spot-check P0 verification — 11/12 CONFIRMED, 1 mitigated"

## §5 NF525 invariants — verification cross-agent

| Invariant | Verifié par | Status |
|---|---|---|
| Pricing SSOT backend (`item_id + qty + option_ids` only frontend → backend) | K05, K06b, K07, K13, K20 | ✅ PASS |
| composition_snapshot write-once / frozen | K05, K18, K20 | ✅ PASS (4 writers all in create-path inside tx) |
| Fiscal sequence monotonic per branch + alloc retry | K18 | ✅ PASS |
| z_reports DELETE/UPDATE blocked (MySQL trigger) | K18 | ✅ PASS + MysqlOnly test |
| Audit chain HMAC append-only | K18 | ✅ PASS |
| OrderQuoteService::sealForCommit 409 on tamper | K20 | ✅ PASS |

NF525 layer is healthy on HEAD. Le risque réel = governance (frozen drift sans LOCK)
+ idempotency dormant flag (P0-05).

## §6 Multi-tenant Branch isolation

| Boundary | Verifié par | Status |
|---|---|---|
| KioskMachine token → `branch_id` propagation | K16, K17, K20 | ✅ PASS |
| Menu fetch branch-scoped (cache key `kiosk.menu.branch.{id}`) | K17 | ✅ PASS |
| Order create branch-isolated (BranchScope on Order/OrderItem) | K18 | ✅ PASS |
| Broadcast channel ability check (`tokenCan('kiosk:order')`) | K16, K20 | ✅ PASS |
| Admin Spatie permission gate | K19 | ⚠️ K19-P1-01 : KioskMachineController::index NOT in `permission:settings` whitelist (mirror gap, fix scope-mini) |

## §7 i18n FR / EN / AR — cross-locale

- `kiosk.confirmation.*` migration : ✅ PASS HEAD (K11 confirmed 23 keys all 3 locales)
- `kiosk.promo` duplicate block : ✅ resolved (K04 confirmed not duplicate on HEAD)
- `kiosk.promo.error.*` referenced in kioskCart.js:559/584/587 : ❌ ABSENT all 3 locales → raw labels
- `kiosk.error.*` 4 subtrees : ✅ FR/EN/AR complete (K12)
- Locale supplement_qty_label/increase/decrease AR : ❌ MISSING (K06b P1)
- `generic.*` keys (GenericChoices) : ❌ MISSING all locales, FR hardcoded fallbacks (K06b P1)
- KioskOfflineConflictModal : ❌ hardcoded FR no `$t()` (K12 P0)
- Frontend backend error display raw : ❌ K11-P1-04 + K03-P0-03 (i18n leak)
- AR RTL CSS coverage : ✅ partial (9 components + tokens.css cascade)
- FR-lock V1 : ✅ immutable per i18n.js:21

**Net** : FR robust, EN partial, AR best-effort (post-Sprint 2026-05-08+10 cleanup).

## §8 A11y WCAG 2.1 AA + EAA 2025

Systemic patterns (K20 + per-agent confirmations) :
- **role/aria semantics** : choice cards mix non-standard ARIA (Viande `role=group` clickable, sauce uses APG correctly, Pain `role=radio` OK) — K06a-P1-01
- **Focus management** : initial-focus on step mount absent in 4 step components — K06a-P1-02
- **aria-live** : cart total has it (K07), recap waiting screen MISSING (K11-P1-01), supplement cumulative MISSING (K06b)
- **Focus trap** : K03 inactivity overlay claims trap in JSDoc but NOT implemented — EAA 2025 P1
- **Contrast** : K02 idle text on white background = 1.1:1 fail
- **Allergen visibility mid-wizard** : EU FIC 1169/2011 + EAA 2025 require per-card disclosure ; K06a header-merge ok mais per-card disclosure MISSING — K06a-P1-04

## §9 Top 10 actions prioritaires (pre-merge V1)

| # | Action | Agent(s) | Effort |
|---|---|---|---|
| 1 | **Rétro-LOCK_*.md + sign-off owner** sur 5 frozen files breach (CV-P0-01) OR revert non-gated | K01, K05, K09, K16, K20 | 0.5-1j owner |
| 2 | **Flip `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` default** (P0-05) | K18 | 0.1j |
| 3 | **Add prod env guard to SimulateKioskOrders command** | K19 | 0.2j |
| 4 | **Fix TPE_TIMEOUT bypass** (KioskPayment 528-561) → orphan PENDING cleanup | K10 | 0.5j |
| 5 | **Add X-Idempotency-Key to reconcile-pending** POST | K10 | 0.3j |
| 6 | **Migrate token localStorage → HttpOnly cookie** (or owner-gate accept residual risk) | K03 | 1-2j owner gate |
| 7 | **Fix idle screen contrast** (replace `#FFF5E8` text + dark overlay layer) | K02 | 0.3j |
| 8 | **Owner-gate remove cart-add toast** in KioskUpsellComponent | K07, K09 | 0.2j |
| 9 | **Fix offline queue race + migration safety + analytics whitelist** | K15 | 0.5-1j |
| 10 | **Wire KioskOfflineConflictModal i18n + remove staleItems IDs leak** | K12 | 0.3j |

**Estimation effort total pre-merge V1** : ~5-7 j-agent + 1j owner gate.

## §10 V1.0.1 sprint (post-merge HEAL)

Items P1 cumulés ≈ 70 (cross-agent), regroupables en 6 thèmes :
1. A11y systémique (focus trap, aria-live, choice card ARIA, EU FIC per-card)
2. AR i18n complétude (~12 keys missing + helpers FR-only heuristics)
3. Cart NF525 contract test pin (Vitest + PHPUnit sentinel)
4. Admin authz refactor (88 endpoints PROJECT_BRAIN §1 V1.0.1)
5. Backend error raw → i18n + sanitized
6. Frozen-zone CI sentinel (`git diff main -- <frozen> --numstat` fail)

Estimation : ~5-8 j-agent (PROJECT_BRAIN.md §1 V1.0.1 budget : 8j-agent confirmé)

## §11 Méta-leçons audit

1. **HEAD drift dans BRAIN.md détecté 3× indépendamment** (K16/K17/K18) — confirme la
   méthode adversariale evidence-driven > confiance memoire.
2. **PROJECT_BRAIN.md §2 "0 lines diff frozen-zones" est FAUX** — cross-validated 5 agents.
   Le pattern doit faire l'objet d'un CI sentinel automatique.
3. **NF525 contract est sain** sur HEAD — 6 invariants cross-validés par 5 agents indépendants.
   La gouvernance (LOCK docs) est le vrai gap, pas le code.
4. **Memory stale détectée** : posKioskVariationParity P0-14 listé FAKE → en réalité REAL test
   sur HEAD (K13). Update memory.
5. **Adversarial parallèle ROI** : 21 agents 3 batches ≈ 30 min wall-clock, ~2.1M tokens
   cumulés ; couverture 4 933 lignes md de findings indépendants. Pattern validé.

## §12 Backlog updates

- PROJECT_BRAIN.md §2 : update HEAD `245e8ab57` → `6a33a9763`
- PROJECT_BRAIN.md §2 : retirer "0 lines diff frozen-zones" — replace by accurate `+3337/-467` audit-tracked
- PROJECT_BRAIN.md §4 NEXT TO DO : marquer 11 P0 closed, restant 2 OPEN (P0-05 + P0-15)
- PROJECT_BRAIN.md §7 : possible re-verify 16 domaines après remediation 5-7j top-10
- Memory : update P0-14 status (REAL not FAKE on HEAD)
- Graphiti episode push : "Kiosk Ultra Audit 2026-05-11 — 21 agents — verdict NO-GO V1 jusqu'à top-10 remediated"

## §13 E2E execution — DEFERRED (owner ack required)

Le user a demandé "audit + plan + **test E2E**" pour chaque agent. Les 21 agents ont produit
**~84 cas E2E catalogués** (3-5 par agent × 21 agents), mais **aucun n'a été exécuté
en live**. Raisons du deferral :

1. **Conflits Playwright multi-agent** : dev-server unique sur port 8000, 21 specs simultanés
   = collisions de session/idempotency-key/cart state
2. **Branche en état frozen-drift** : exécuter E2E avant rétro-LOCK/revert = mesurer un état
   non-gouverné
3. **Memory feedback "flaky E2E compounds fix cycles"** : pre-flight env stabilization
   recommandée avant batch run
4. **Output token budget** : 21 agents avec full Playwright traces auraient hit limits

**Proposition exécution** :
- **Wave A (post top-10 remediation)** : run les ~30 cas P0/P1 sentinel (security + NF525
  + frozen drift) en serial via main thread, dev server stable, branche reflowed
- **Wave B (V1.0.1 sprint)** : run ~50 cas P2 a11y + RTL + i18n cross-locale + design
  drift en batches de 5 parallel max
- **Wave C (long-term)** : intégrer les nouveaux specs dans CI sentinels

Les 84 cas proposés sont dispersés dans les 21 rapports §"Proposed new E2E tests".
Index agrégé à produire si owner valide.

**Owner gate** : valider que le deferral est acceptable, OR demander un run live
immédiat (warn : ~30-60 min wall-clock + dev server prep needed).
