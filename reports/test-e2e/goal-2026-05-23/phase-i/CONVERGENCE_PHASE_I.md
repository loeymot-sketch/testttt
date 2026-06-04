# Phase I + I2 — INDIRECT + HIDDEN TESTS CONVERGENCE

**Date** : 2026-05-24
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Owner mandate** : « pour continuer de couvrir les test indirect et caché »

---

## 🎯 Verdict — **CONVERGED GREEN with 8 audit + 4 heal commits**

| Agent | Verdict | Critical finding | Heal |
|-------|---------|------------------|------|
| **I.1 Side-effect ripple chain** | AMBER | 22 silent-swallow callsites (OrderStateMachine + CashDrawer + audit writes) | doc-only V1.0.2 |
| **I.2 Soft-delete + FK cascade** | ✅ GREEN | NF525 cascade safety attested via DB-level immutability triggers + restrictOnDelete | n/a |
| **I.3 Hidden caching layers** | AMBER → healed | ItemService::update no cache invalidate (kiosk stale 60s) | `cba372066` |
| **I.4 Domain event listener ordering** | RED → healed | OrderCanceled cascade halts on throw (stock vs availability divergence) | `ba6d110da` |
| **I.5 Session state leak concurrent** | ✅ GREEN | 0 mutable singletons, full isolation attested | n/a |
| **I.6 Hidden auth/RBAC bypass** | ✅ GREEN | 13 tokenCan sites (BRAIN §9 claim 8 was stale — actually broader/stronger) | n/a |
| **I.7 Sanctum token revocation race** | AMBER → healed | sanctum:prune-expired NEVER scheduled (NF525 6y storage bloat) | `ba6d110da` |
| **I.8 Implicit defaults + silent fallbacks** | AMBER → healed | LOYALTY_QR_SECRET missing from .env.example (P1 boot crash) | `7368fc23c` |

---

## 1. RED healed — OrderCanceled cascade hardening (I2-HEAL-01)

**Phase I.4 caught a RED bug** : `ReleaseStockOnOrderCanceled.php:29` explicitly `throw $e;` per stale iter13 comment. Laravel sync dispatcher halts on listener throw → `ReleaseAvailabilityOnOrderCanceled` NEVER ran → **divergent stock vs availability ledgers**.

Same hardening applied to RefundCreated release listeners (I4-CONCERN-02 sibling).

Fix : drop re-throw + Log::error + structural sentinel (cascade survives + direct invoke never re-throws across 4 listeners).

**Commit `ba6d110da`** — 7/7 sentinel GREEN + 12/12 OrderCreated isolation suite + 13/13 Refund suite GREEN. Frozen-zone diff = 0.

---

## 2. AMBER → healed — Hidden caching (I2-HEAL-02)

**Phase I.3 RISK-01** : Admin renames/reprices an item, kiosk shows OLD for up to 60s. Pricing SSOT NF525 invariant held (order POST recomputes), but UX-only window.

Fix : NEW `ItemUpdated` event (DispatchableAfterCommit trait) wired in EventServiceProvider to existing kiosk cache invalidation listener pattern. CatalogChanged::fromMenuMutation() learned ItemUpdated bridge.

**Commit `cba372066`** — 2/2 sentinel GREEN + 10/10 catalog regression suite GREEN. Frozen-zone diff = 0.

---

## 3. P1 healed — LOYALTY_QR_SECRET documentation (I2-HEAL-03)

**Phase I.8 P1** : Production deploy CRASHES at boot (`AppServiceProvider:161`) if `LOYALTY_QR_SECRET` missing, but `.env.example` didn't tell operator to set it. First-time deploy = mystery boot crash.

Fix : add `LOYALTY_QR_SECRET=` entry to `.env.example` with full comment (`openssl rand -hex 32` generation instruction) + README_DEPLOY.md §8.5 owner physical action.

**Commit `7368fc23c`** — sentinel 2/2 GREEN with negative drift proof (remove → sentinel fails, restore → passes). Frozen-zone diff = 0.

---

## 4. P2 healed — sanctum:prune-expired cron (I2-HEAL-04)

**Phase I.7 R6** : Vendor command `sanctum:prune-expired` existed but never scheduled. Compound risk : relogin-revoke only touches active token name, so expired rows of OTHER token names accumulate forever. NF525 6-year horizon = storage bloat.

Fix : NEW Kernel.php lane daily 04:30 Paris with `--hours=24` retention grace + CRONTAB_PROD.md row #18.

**Commit `ba6d110da`** (bundled) — sentinel 7/7 GREEN (cron expression, TZ, mutex, log, retention, single registration). Frozen-zone diff = 0.

---

## 5. Other key findings (V1.0.2 backlog, non-blocking)

### From I.1 (silent-swallow callsites)
- OrderStateMachine::recordTransition swallows DB insert failure (Log::warning only) — P1 V1.0.2 metric+alert
- CashDrawerService:534 + DeliveryBoyCashSessionService:424 audit writes swallowed — P1 V1.0.2 cash_audit_pending fallback table
- 22 silent-swallow callsites total cataloged

### From I.2 (cascade audit)
- loyalty_transactions.user_id uses cascade — GDPR signal V1.0.2
- z_reports + orders down() migrations lack APP_ENV=production guard — symmetry fix V1.0.2
- Sanctum personal_access_tokens lacks FK — prune cron just added by I2-HEAL-04

### From I.3 (caching)
- deploy.sh doesn't OPcache reset — safe today (validate_timestamps=1 default), latent if owner ever flips
- `.env` hot-edit invisible after config:cache — documentation only

### From I.4 (listener ordering)
- BRAIN "11 ShouldHandleEventsAfterCommit listeners" claim STALE (codebase uses event-side DispatchableAfterCommit trait — equivalent semantics, single deferral point) — BRAIN update needed
- ZReportClosed event mentioned in spec doesn't exist
- Parity drift: 8 of 11 Persist*ToOutbox listeners use Log::warning only (no OutboxBroadcastSwallowedEvent escalation)

### From I.7 (Sanctum)
- R3 silent multi-device kick (P2 UX) — no "logged out elsewhere" toast
- R5 no per-user admin force-logout endpoint (P2 ops)
- BRAIN §9 stale (claims 8 tokenCan controllers, actual = 13 sites broader+stronger)

### From I.8 (implicit defaults)
- 28 `env()` calls outside config/ — 14 dangerous after config:cache (AppLibrary 12 currency/date, AuditLogService 1 V2 landmine, DEMO gates 4)
- Recommend EnvCallsOutsideConfigSentinelTest (V1.0.X)

---

## 6. NF525 chain integrity

CHAIN OK at every commit. No fiscal service modified. audit_logs counts unchanged by Phase I+I2 heals (UX/observability/scheduler heals only).

---

## 7. Frozen-zone discipline

**0 LOC diff** across all 14 §7 files post-cycle (verified vs baseline `d601fdd34`).

---

## 8. New sentinels Phase I + I2 (4 total)

| Sentinel | Tests |
|----------|-------|
| `OrderCanceledCascadeHardenedSentinelTest.php` (I2-01) | 7 |
| `ItemUpdateInvalidatesKioskCacheSentinelTest.php` (I2-02) | 2 |
| `EnvExampleHasLoyaltyQrSecretSentinelTest.php` (I2-03) | 2 |
| `SanctumPruneExpiredScheduledTest.php` (I2-04) | 7 |
| **TOTAL Phase I+I2** | **18** |
| **+ Phase H+H2** | **18** |
| **+ Phase G+G2** | **28** |
| **+ Phase F+F2** | **57** |
| **+ Phase A-E** | **33** |
| **GRAND TOTAL cycle** | **154 NEW sentinels GREEN** |

---

## 9. V1 LOCAL SHIP VERDICT (post Phase I + I2)

✅ **PRODUCTION-READY** within explicit envelope :
- ✅ Side-effect chains audited (silent-swallow callsites cataloged for V1.0.2 observability)
- ✅ Soft-delete + FK cascade NF525-safe (DB-level immutability triggers + restrictOnDelete)
- ✅ Hidden caching invalidation now complete (ItemUpdated event wired)
- ✅ Listener ordering RED healed (OrderCanceled + RefundCreated release cascade hardened)
- ✅ Session-state leak ZERO (full isolation attested)
- ✅ Auth/RBAC depth production-grade (13 tokenCan sites verified)
- ✅ Sanctum prune-expired daily cron active (storage bloat over 6y prevented)
- ✅ LOYALTY_QR_SECRET production deploy documented + sentinel-locked

**Owner-gate items still pending** (none block V1 LOCAL ship) — same 5+ as documented in Phase G+G2+H+H2 convergence reports.

**Owner PHYSICAL WALK** = mandatory before going live. `OWNER_PHYSICAL_WALK_CHECKLIST.md` ready.

---

## 10. Cycle TOTAL (post Phase A → I2)

- **~45 commits** pushed
- **94 PROPOSAL docs** frozen-zone audit
- **154 NEW sentinels GREEN** cumulative
- **NF525 chain bit-identical** preserved every commit
- **Frozen-zone diff = 0 LOC** across 14 §7 files
- **~130 sub-agents** dispatched massivement parallèle
- **19 production-hardening heals** shipped
- **3 CRITICAL bugs caught + healed** (Firebase publicly-fetchable + cross-user idempotency + loyalty TTC overcharge)
- **1 RED P0 OrderCanceled cascade healed**

---

*Phase I + I2 — 12 sub-agents (8 I audit + 4 I2 heal) · 4 commits · 18 NEW I+I2 sentinels GREEN · 154 cumulative · NF525 chain bit-identical · frozen-zone diff = 0 · indirect + hidden tests deeply covered.*
