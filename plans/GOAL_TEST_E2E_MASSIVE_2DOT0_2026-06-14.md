# GOAL — TEST-E2E MASSIF « 2.0 » (parcours complet par système + inter-systèmes synchro réelle)

**Date:** 2026-06-14 · **Mission slug:** `massive-2dot0-2026-06-14`
**Branch under test:** `release/v1-integration-2026-06-12` @ `7b3f14feb` (worktree `.claude/worktrees/integration-v1-2026-06-12`)
**Owner mandate (verbatim intent):** tester **chaque système seul en profondeur maximale**, PUIS le **parcours complet inter-systèmes avec synchros réelles** (borne → encaissement caisse → affichage KDS → sortie/livraison client → ticket caisse + système de gestion + tout l'indirect/caché), **point par point, en boucle, avec agents adversaires qui disputent au max**, jusqu'à **2 tests finaux 100% verts / zéro faute** — technique + sécurité + raisonnement de fonctionnement + affichage + UX. But = projet **2.0**.

---

## §0 — Preamble

### §0.1 Working-tree decision
- Tree = **existing spine worktree** (the validated GO superset, 530 commits ahead of quickwins, 1718 ahead of origin/main).
- Audit = **read-only** (no source change). Heals (Wave H) = scope-minimal, TDD, **frozen-zone = LOCK+gate**, committed to spine with explicit paths (never `git add -A`).
- **No push** (owner gate §G). Spine branch is protected.

### §0.2 Harness (hermetic — built Wave 0, VERIFIED)
- Isolated DB **`foodking_2dot0`** (clone of `foodking_e2e`, tronc schema) — **NEVER** the operating `foodking`.
- Dedicated serve **`http://127.0.0.1:8780`** · `APP_ENV=2dot0` · `.env.2dot0` (same-origin APP_URL, own Redis DB 7/8). Detached (`nohup … & disown`) → survives session resets.
- Admin login: `admin@lecayenne.fr` / `123456`. Confirmed DB=foodking_2dot0, ENV=2dot0, **NF525 CHAIN OK**.
- **Baseline drift anchors (capture at start, re-check at end):** `audit_logs` count=**4342** (max id 4346) · `z_reports`=**20** · max `fiscal_sequence_no`=**2426**. Any UNEXPLAINED decrease = REJECT + escalate.

### §0.3 Convergence criteria (the «2 tests finaux»)
- **Test A — Per-system depth:** every system's full PHPUnit lane + Vitest lane GREEN + adversarial dispute finds 0 NEW P0/P1.
- **Test B — Cross-surface live journey:** the full borne→caisse→KDS→OSS→delivery→receipt→gestion journey runs end-to-end with **real sync**, technically + visually + fiscally correct, 0 P0/P1.
- **DONE = two consecutive cycles with P0+P1=0 AND identical findings set** (flake guard) on BOTH Test A and Test B. Frozen diff=0. NF525 chain appended-only. Visual evidence Read+analyzed.

### §0.4 Pipeline reference
- Per-task execution → `~/.claude/skills/ultra-audit-profond` (14-step). Cross-surface dual-team → `~/.claude/skills/test-e2e`. Frozen override → `~/.claude/skills/lock-plan`. Parallel fan-out → `superpowers:dispatching-parallel-agents`. Heal = TDD (`superpowers:test-driven-development`).

### §0.5 Anti-fiction attestation
All anchors in §1–§7 grep/ls-verified at spine HEAD `7b3f14feb` (Wave 0). Menu = real DB (Sandwich Cayenne, Galette, Sandwich Classique, Burgers, Tacos, Bols Gourmands, Frites, Suppléments, Desserts, Boissons, Menu enfant). NO invented products.

---

## §1 — Map principal (5 systems, anchors VERIFIED @ 7b3f14feb)

| # | Système | Maturité | Anchor primaire (verified) | Tests lane |
|---|---|---|---|---|
| S1 | **BORNE** (kiosk self-order) | GO-validated, 3 frozen comps | `components/frontend/kiosk/**` (24 vue), `KioskMachineLoginController.php`, `kioskCart.js`, `kioskOfflineQueue.js` | `tests/Feature/Kiosk/**`, Vitest kiosk |
| S2 | **CAISSE** (POS pay/cash/fiscal) | GO-validated, pos-wizard FROZEN | `Admin/PosController.php`, `PaymentService.php`, `public/js/pos-wizard.js`(frozen), `components/admin/pos/**` | `tests/Feature/Pos/**`, Vitest pos |
| S3 | **KDS+OSS** (kitchen + status screen) | GO-validated, P1-history zone | `KitchenDisplaySystemOrderService.php`, `KdsSyncController.php`, `kitchenDisplaySystem/**`, `orderStatusScreen/**`, `kdsCustomization.js`, `OssSyncService.js` | `tests/Feature/Kds/**`, Vitest kds |
| S4 | **CENTRAL** (gestion/dash/hist/reports) | GO-validated, 91 Admin ctrls | `DashboardController.php`, `OrderHistoryController.php`, reports cluster, `admin/**` (non-POS/KDS) | `tests/Feature/{Dashboard,Admin,Report}/**`, Vitest admin |
| S5 | **SYNC bus + Fiscal + Order core** (shared §6) | NF525-critical, mostly FROZEN | `PricingService`(frozen), `Fiscal/{FiscalSequence,ZReport,AuditLog}Service`(frozen), `OrderStateMachine`(frozen), `OrderService`/`FrontendOrderService`, `Events/{OrderCreated,OrderStatusChanged,KdsOrderRecalled}`, `channels.php` `branch.{id}`, outbox | `tests/Feature/Fiscal/**`, `tests/Feature/Sync/**` |

## §2 — Map separated (standalone, NO API wireup V1)
- `/Users/1millnonstop/Downloads/web/**` (web standalone) + `mobile/**`. **Out of the central cross-surface E2E** (owner mandate: standalone, no backend wireup). Audited only if Test A/B converge early (optional Wave OPT). Palette mobile = NOIR/ORANGE/JAUNE/BLANC (NOT `#F4501E`).

---

## §3 — S1 BORNE — decomposition

### Sub 1.1 — Commande self-service (wizard composer-first)
- T-1.1.1 Catalogue render: catégories réelles, images, prix backend (NF525 SSOT, **0 prix calculé front**) — anchor `kiosk/KioskMenuComponent*`, `Services/Kiosk/PricingPreviewService`. accept: `tests/Feature/Kiosk/` quote test PASS + live :8780/kiosk visual 0 raw-label.
- T-1.1.2 Wizard composition (item+variation+extras+suppléments), modal allergènes — anchor `KioskWizardComponent.vue`(FROZEN, audit-only), `kdsCustomization` mirror. accept: prix étape = jamais affiché (SSOT) ; allergènes string-coerce (food-safety) OK.
- T-1.1.3 Panier + offline-queue race (snapshot+merge) — anchor `kioskOfflineQueue.js:534-602`. accept: `(test TO BE CREATED at tests/Feature/Kiosk/KioskOfflineQueueRaceTest.php)` OR existing — re-confirm the 2026-06-09 P1 is healed in spine.
- T-1.1.4 Login borne anti-énumération (Hash::check AVANT state-checks) — anchor `KioskMachineLoginController:71-75`. accept: timing-safe, 422 generic.

### Sub 1.2 — Paiement borne (Plan B → caisse)
- T-1.2.1 Routing paiement borne → encaissement comptoir (`kiosk.payment_route_all_to_counter`) — accept: commande borne crée order PENDING_COUNTER, PAS de paiement front.
- T-1.2.2 Fidélité borne facturée (points débités + ledger + outbox `LoyaltyBalanceChanged`) — re-prove empirically (regression du dispute 12/06).

### Sub 1.3 — Sync borne→KDS (produce side)
- T-1.3.1 `OrderCreated` émis → KDS reçoit ; dégradation gracieuse polling si soketi down. accept: `tests/Feature/Sync/**`.

---

## §4 — S2 CAISSE — decomposition

### Sub 2.1 — Encaissement unifié (le cœur owner)
- T-2.1.1 `PosCounterCollectModal` (non-frozen) Espèces/TR/Terminal-manuel — anchor `components/admin/pos/encaissement/**`, `PaymentService.php`. accept: 1 OrderPayment/method, card/TR=ref-only (pas de CashMovement → pas de sur-compte tiroir), `tests/Feature/Pos/` encaissement PASS.
- T-2.1.2 Garde Entrée pass-through (F1, a11y/fiscal) — re-confirm port #17 présent. accept: re-run.
- T-2.1.3 Anti-double-remboursement pré-Z (R-1, 409 MIRROR_ALREADY_EXISTS) — re-confirm port #17.

### Sub 2.2 — Tiroir + Z-report (NF525)
- T-2.2.1 Cash drawer session hydrate + reconcile (expected_cash = opening + Σ signed CashMovement scoped session) — anchor `CashDrawerService`. accept: `tests/Feature/Pos/Cash*` PASS.
- T-2.2.2 Z close = somme exacte UI, séquence gap-free, chaîne HMAC append-only — anchor `ZReportService`(FROZEN). accept: `php artisan fiscal:verify-chain` OK after a live Z.
- T-2.2.3 Sous-facturation frites/upgrade (CAISSE-01 history: +2€ shown/0 charged in frozen pos-wizard.js) — re-confirm resolved-or-DATA. accept: live order charges = backend price.

### Sub 2.3 — Wizard POS (FROZEN — audit only)
- T-2.3.1 `pos-wizard.js` rendu generic-choices + composer-aware flag — audit-only, frozen diff=0.

---

## §5 — S3 KDS+OSS — decomposition

### Sub 3.1 — Bump / release discipline (P1-history zone)
- T-3.1.1 `changeStatus` board-release predicate: DELIVERY+UNPAID & POS+UNPAID+non-cash = 422 (the 2026-06-14 quickwins P1) — **CRITICAL: confirm present-or-port into spine** — anchor `KitchenDisplaySystemOrderService`, `KitchenReleaseRule`. accept: `tests/Feature/Kds/KdsUnreleasedOrderBumpP1Test.php` (port if absent).
- T-3.1.2 Allergen string-coerce food-safety (`kdsCustomization:155`) — **CRITICAL confirm present in spine** (flagged ABSENT 06-12). accept: KDS card shows allergens never `[object]`/empty when present.
- T-3.1.3 Recall cap sliding-window + 409-only badge — anchor `:338`. accept: Vitest kds recall spec.
- T-3.1.4 Notif-fail resilience (Throwable post-commit ne re-wrappe pas un bump réussi en 422) — anchor `:463-483`.

### Sub 3.2 — OSS status screen
- T-3.2.1 OSS feed public `GET /api/frontend/oss-order` no-PII — accept: `tests/Feature/` oss test.
- T-3.2.2 OSS 4xx-backoff + listener isolation (poll-freeze évité) — **confirm present in spine** (flagged 06-12). anchor `OssSyncService:307-316,453-468`.
- T-3.2.3 OSS badge dégradé bas-centre (no overlap order#) — confirm `b19f151e0`.

### Sub 3.3 — KDS display correctness
- T-3.3.1 Visible == bumpable by construction (`applyBoardReleaseFilter` SQL mirror). accept: live KDS shows only released orders.

---

## §6 — S4 CENTRAL — decomposition

### Sub 4.1 — Dashboard + chiffres (indirect/caché — owner emphasis)
- T-4.1.1 CA net réalisé = `Order::scopeRealizedRevenue` (exclut annulées-payées, nette refunds, hors mirrors) cohérent avec Z signé — anchor `DashboardService`. accept: dashboard total = Z somme.
- T-4.1.2 Historique commandes: snapshot frozen (mutation-probe price ignoré), cross-branch 403, no-PII OSS — anchor `OrderHistoryController`.
- T-4.1.3 Reports (sales/items): SUM unités vendues, date de VENTE, realized-only, export date-aware/paginate. anchor `SalesReportController`,`ItemsReportController`.

### Sub 4.2 — Catalogue + stock (CMS gestion)
- T-4.2.1 CRUD produit + sous-catégories + stock hiérarchique sync — anchor `ItemController`. accept: catalogue 45+ items render, prix €.
- T-4.2.2 Stock rupture dashboard `/admin/stock/rupture` (real route) — accept: visual clean.

### Sub 4.3 — Users/RBAC + settings
- T-4.3.1 RBAC: admin 29 nav vs POS 11 (no orphan route, 0 dead button) — anchor `BackendMenuComponent.vue`. accept: 25/25 nav→working page.
- T-4.3.2 Own-branch scope enforce (EnforcesOwnBranchScope trait) — anchor Employee/Chef/Waiter/DeliveryBoy services.

---

## §7 — S5 SYNC + FISCAL + ORDER CORE (shared §6 — NF525-critical)

### Sub 5.1 — Fiscal chain (FROZEN)
- T-5.1.1 fiscal_sequence_no monotonic gap-free per branch, alloc kiosk-paid@creation / POS-cash@close — anchor `FiscalSequenceService`(FROZEN). accept: `tests/Feature/Fiscal/**` + live.
- T-5.1.2 audit_logs + z_reports HMAC chain append-only, BEFORE DELETE guard — accept: `fiscal:verify-chain --all` OK; baseline drift check.

### Sub 5.2 — Pricing SSOT (FROZEN)
- T-5.2.1 100% prix via `PricingService::calculateOrder`, composition_snapshot frozen@creation never overwritten, front sends item_id/qty/options only — accept: `tests/Feature/` pricing.

### Sub 5.3 — Sync bus + degradation
- T-5.3.1 `branch.{id}` channel pub/sub, OrderCreated/OrderStatusChanged/KdsOrderRecalled — accept: live event received cross-tab.
- T-5.3.2 Degradation gracieuse (soketi down → polling) sur 3 surfaces (KDS, PosOrdersTracker, ConnectionStatusBanner). accept: opt-out default-true.

### Sub 5.4 — Order state machine (FROZEN)
- T-5.4.1 Transitions correctes/contrôlées, no invalid transition — anchor `OrderStateMachine`(FROZEN).

---

## §A — Agent army map + fan-out

Roles (read-only audit unless Implementer): Architect · Security · UX/A11y · DBA · SRE/Sync · Implementer(TDD) · RED-team · QA-Visual · RED-Visual.

| Task type | Arch | Sec | UX | DBA | SRE | Impl | RED | QAv | REDv |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Frontend visual | x | x | x | . | . | x | x | x | x |
| Backend logic | x | x | . | x | . | x | x | . | . |
| Sync cascade | x | x | . | x | x | x | x | . | . |
| Fiscal NF525 | x | x | . | x | . | x | x | . | . |
| Cross-surface E2E | x | x | x | x | x | x | x | x | x |

**Dispatch discipline:** read-only specialists = single-message parallel (Workflow). Implementer NEVER parallel with implementer. RED-team dispute ALWAYS after fix, before DONE. **Adversarial = MAX**: each P0/P1 finding gets ≥3 independent skeptic verifiers (refute-by-default); survives only if ≥2 confirm real. Findings persist to `reports/test-e2e/massive-2dot0-2026-06-14/<round>/wave-<W>-<role>.json`.

**Live cross-surface E2E = MAIN THREAD + Playwright MCP** (single browser, can't parallelize) — sequential journey driving. Code/logic/security/UX per-system audits = MASSIVE parallel via Workflow.

---

## §X — Convergence waves

- **W0 Pre-flight — DONE.** Harness hermetic, baselines captured, anchors verified, GOAL written.
- **W1 — Per-system DEEP audit (Test A round-1).** 5 systems, parallel specialist fan-out (Workflow), adversarial ≥3-skeptic dispute. Read-only. Output: confirmed P0/P1/P2/P3 per system. Sequential default; read-only fan-out parallel within.
- **W2 — Cross-surface LIVE journey (Test B round-1).** Main thread + Playwright on :8780. Real sync: place borne order → caisse encaissement → KDS display → OSS → delivery/output → receipt (caisse) + gestion reflection + indirect (loyalty, stock decrement, Z, dashboard CA). Each step technical+visual+fiscal verified. RED-Visual disputes each capture.
- **W3 — HEAL.** Fix confirmed P0/P1 (TDD, scope-minimal). Frozen-zone → LOCK+gate (owner). Max 3 heal loops/cluster → else convergence-failure protocol (STOP+Plan agent+escalate).
- **W4 — Re-converge (Test A + Test B round-2).** Re-run both. DONE when 2 consecutive cycles P0+P1=0 identical set.
- **W5 — Final attestation.** Full PHPUnit + Vitest + frozen diff=0 + NF525 chain + baseline drift + visual gallery. BRAIN update. Owner-gate surface.

**Checkpoint (each wave):** all tasks PASS/documented · frozen diff=0 · NF525 chain OK · visual Read+analyzed · RED dispute done · BRAIN §2/§3 updated · WIP committed. **Interrupt-resume:** commit WIP `wip(<wave>):` + write `INTERRUPT_<wave>_<ts>.md` + update BRAIN; on resume read manifest + smoke last task.

---

## §G — Owner gates (WHO/WHAT/WHERE)

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G-FROZEN | Any frozen-zone heal needed (pos-wizard, PricingService, ZReportService, OrderStateMachine, PaymentComponent…) | Owner | LOCK doc countersign | `plans/LOCK_*.md §10` | PENDING-IF-NEEDED |
| G-PORT | If KDS allergen / offline-race / OSS 4xx P1 ABSENT from spine → port from quickwins | Owner (heal authorize) | confirm port-not-divergent | commit msg | PENDING-IF-NEEDED |
| G-PUSH | Push spine to remote | Owner | explicit go | — | PENDING |
| G-OVH | Deploy to OVH | Owner | deploy go + creds | — | PENDING |
| G-DATA | Prod data (VAT/images/promo flips) | Owner | values | — | PENDING |

**Gate-waiting:** Claude runs all non-gated waves (W1–W4 audit+local heal) while gates pending. No push, no OVH, no frozen-logic-change without countersign.

---

## §R — References
SYSTEM_MAP.md · SYNC_CONTRACT.md · CONSTITUTION.md · CLAUDE.md §§7-9 · `reference_e2e_harness_foodking_e2e` · `project_delta_validation_go_2026-06-14` · `project_kds_p1_and_stale_sentinels_2026-06-14` · ultra-audit-profond · test-e2e skills.

## §F — Final rule
DONE = **production-perfect**, not «almost». Test A (per-system depth) AND Test B (cross-surface live journey) both 100% green, 2 consecutive identical cycles, 0 P0/P1, frozen diff=0, NF525 appended-only, every claim evidence-backed (file:line + repro + screenshot Read). Any «good enough» = REJECT. Frozen/NF525 touch = STOP + owner gate. Project 2.0 = the integrated spine, independently re-proven end-to-end with real synchronizations.
