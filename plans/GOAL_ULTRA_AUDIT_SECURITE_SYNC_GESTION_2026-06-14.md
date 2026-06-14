# GOAL — ULTRA-AUDIT ABUSIF : Sécurité · Synchronisation · Gestion (+ caché/indirect/réactif)

**Date:** 2026-06-14 · **Slug:** `ultra-audit-securite-sync-gestion-2026-06-14`
**Tree:** spine `release/v1-integration-2026-06-12` @ HEAD `1dc5785a5` (worktree integration-v1) · clone `foodking_2dot0` @ :8780.
**Mandate (owner verbatim intent):** couvrir TOUT le caché / indirect / réactif, ultra-auditer adversairement **Sécurité (backend, historique + durcissement) · Synchronisation · Gestion (chiffres indirects)**, 4 lentilles : backend-code · frontend-code · interface-UX · sécurité-backend. La demande la plus abusive = exhaustivité maximale.

## §0 — Preamble
- **§0.1 Working-tree:** read-only AUDIT (le code campagne massive-2dot0 est déjà committé + poussé sur `heal/massive-2dot0-2026-06-14`). Heals = TDD scope-minimal, frozen=LOCK+gate.
- **§0.3 Convergence:** 2 cycles consécutifs P0+P1=0 set-identique, par système. Frozen 0, NF525 chain append-only.
- **§0.4 Pipeline:** per-finding → `ultra-audit-profond` ; dispute ≥3-skeptic ; verify-before-report (anti-hallu file:line + repro).
- **§0.5 Anti-fiction:** anchors grep-vérifiés @ 1dc5785a5 (ci-dessous). NO invented routes/products.

## §1 — Map (3 systèmes transverses + surface réactive)
| # | Système | Anchors VÉRIFIÉS | Tests lane |
|---|---|---|---|
| S1 | **SÉCURITÉ** | 7 `app/Http/Controllers/Auth/**`, 22 `app/Http/Middleware/**`, 2 `app/Models/Scopes/{BranchScope,WizardProfileBranchScope}`, **85 FormRequests dont 71 `return true;`**, Sanctum (`config/sanctum.php`), `IdempotencyKeyMiddleware`, AppServiceProvider boot guards | `tests/Feature/{Security,Branch,Auth}/**` (22 sentinels) |
| S2 | **SYNCHRONISATION (réactif)** | **39 `app/Events/**` × 46 `app/Listeners/**`**, 3 `app/Observers/**`, 4 `app/Jobs/**` (DispatchDomainEventsJob), 44 `app/Console/Commands/**` + **41 tâches schedulées**, `routes/channels.php` (8 refs), `Outbox/OutboxQuarantineService`, JS `{KdsSync,OssSync,PosSync,WebSocket}Service.js` | `tests/Feature/{Sync,Kds,Idempotency}/**` |
| S3 | **GESTION (indirect/calculé)** | 91 `app/Http/Controllers/Admin/**`, `DashboardService`, `OrderService::salesReportOverview`, `Fiscal/ZReportCashEnrichmentService`, reports/catalogue/stock/users | `tests/Feature/{Dashboard,Report,Admin}/**` |

## §2 — Map separated
Mobile + web standalone (NO wireup V1) — hors scope sauf si convergence précoce.

## §3 — S1 SÉCURITÉ — décomposition
### Sub 1.1 — AuthZ surface (le plus chaud)
- T-1.1.1 Les **71 FormRequests `return true;`** — chacun gate-t-il via policy/permission au controller, ou authz manquante (IDOR/escalade) ? anchor `app/Http/Requests/**`. accept: `tests/Feature/Security/FormRequestAuthzDriftSentinelTest` + nouveaux cas.
- T-1.1.2 Branch isolation : BranchScope sur 20 models, exemptions (`BranchScopeCoverageSentinelTest`) — fuite cross-branch via un model exempté ? IDOR par id ?
- T-1.1.3 Sanctum kiosk:order ability + TTL + revoke ; admin bypass branch_id=0 ; `tokenCan` sur 8 ctrls.
- T-1.1.4 Mass-assignment / fillable sur les models money/fiscal (Order, OrderPayment, audit_logs).
### Sub 1.2 — Auth backend (historique + robuste)
- T-1.2.1 Brute-force lockout (LOGIN_LOCKOUT prod=10), timing-oracle (kiosk healed — vérifier admin/customer), énumération.
- T-1.2.2 Idempotency (X-Idempotency-Key) dual-layer ; webhook_events UNIQUE ; replay 2xx-only.
- T-1.2.3 Secrets : 0 secret committé ; `env()` runtime hors config (NF525 AuditLogService #16 frozen) ; boot guards prod.
- T-1.2.4 Rate-limits (pos/kds/kiosk/api throttle) — bypass ? clé par user|ip correcte ?

## §4 — S2 SYNCHRONISATION (réactif) — décomposition
### Sub 2.1 — Bus events × listeners (le caché)
- T-2.1.1 **Les 46 listeners** : lesquels peuvent THROW et casser la cascade post-commit ? non-idempotents (double-fire) ? firent sur ROLLBACK ? ordre de fire ? anchor `app/Listeners/**`.
- T-2.1.2 Les 3 Observers + model lifecycle hooks : effets de bord cachés (saveQuietly backfill, recursion) ?
- T-2.1.3 DispatchDomainEventsJob queue `high,default` (PR-01 finding) ; outbox dispatch reliability + quarantine.
### Sub 2.2 — Cron / scheduled (41 tâches — réactif dangereux)
- T-2.2.1 Tâches destructives (Cleanup*/Stale*/Reject*/Purge*) — **CleanupStalePendingKioskOrders auto-reject 81 commandes** (PR-01) : déclenche mails/SMS/push ? gate ? anchor `app/Console/Commands/**` + `app/Console/Kernel.php`.
- T-2.2.2 ZReport cron (ZOpen/ZClose) + RetryFiscalAlloc (vient d'être étendu COD) — gap-free, pas de double-Z.
- T-2.2.3 Outbox monitor / staleness alert — log-only vs action ?
### Sub 2.3 — Sync client + dégradation
- T-2.3.1 soketi down → polling fallback (3 surfaces) ; listener-isolation (#8/R1 healed — vérifier PosSyncService) ; channel `branch.{id}` authz (pas de fuite cross-branch broadcast).

## §5 — S3 GESTION (indirect/calculé) — décomposition
### Sub 3.1 — Chiffres réactifs cohérents au Z
- T-3.1.1 Toutes les surfaces money (dashboard/sales/items/cash/EOD-PDF) = net-réalisé cohérent au Z signé (#12/#13/#14 healed — chercher d'AUTRES agrégats non-nettés).
- T-3.1.2 ZReportCashEnrichmentService cross-check (cash livreur drift) — exact ?
- T-3.1.3 Reports export (paginate/date-aware) ; historique snapshot frozen (mutation-probe).
### Sub 3.2 — Catalogue / stock / users réactifs
- T-3.2.1 Stock hiérarchique sync (rupture overlay) ; CRUD produit ; sous-catégories.
- T-3.2.2 RBAC nav (admin vs POS) 0 orphan ; own-branch scope (EnforcesOwnBranchScope).

## §A — Agent army (4 lentilles × dispute)
Lentilles : **backend-code**, **frontend-code**, **interface-UX**, **sécurité-backend**. Fan-out read-only parallèle ; chaque P0/P1 → ≥2-skeptic dispute (refute-by-default, ≥1 confirme) ; findings file:line+repro+evidence persistés `round-1/wave-<S>-<lens>.json`. Implementer = jamais parallèle. Verify-before-report obligatoire.

## §X — Waves
- **W0 pre-flight** (DONE: anchors, dir, harness :8780 alive).
- **W1 AUDIT adversaire** (3 systèmes × 4 lentilles + réactif, parallèle read-only, dispute). → findings confirmés.
- **W2 HEAL** (P0/P1 confirmés, TDD, frozen-gate, max 3 loops/cluster).
- **W3 RE-CONVERGE** (re-audit → 2 cycles identiques P0+P1=0).
- **W4 attestation** (suites, frozen 0, NF525 chain, BRAIN update).
**Checkpoint/interrupt:** par wave commit WIP + manifest + BRAIN §2.

## §G — Owner gates
| Gate | Desc | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G-FROZEN | heal frozen (AuditLogService #16, PricingService, ZReportService…) | Owner | LOCK countersign | `plans/LOCK_*` | PENDING-IF |
| G-CRON | activer/modifier une tâche destructive (PR-01 cleanup) | Owner | triage + transports no-op | commit | PENDING-IF |
| G-PUSH/G-OVH | push/deploy | Owner | go | — | PENDING |

## §R — Références
SYSTEM_MAP.md · SYNC_CONTRACT.md · CLAUDE.md §§7-9 · `project_massive_e2e_2dot0_2026-06-14` · `core-bulletproof/PR-01..07` · ultra-audit-profond · test-e2e.

## §F — Final rule
DONE = production-perfect : 0 P0/P1 sur Sécurité+Sync+Gestion (2 cycles identiques), tout le caché/indirect/réactif balayé (39 events×46 listeners, 41 cron, 3 observers, 4 jobs), frozen 0, NF525 chain append-only, chaque claim file:line+repro+evidence. « Presque » = REJECT.
