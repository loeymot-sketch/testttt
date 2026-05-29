# MASTER PLAN — "Tout validé" (orchestrateur 2026-05-29)

Owner mandate: superviseur/boss, master plan + tâches longues, décomposition par sub-agent (GStack / Superpowers / Adversarial), finir par test-e2e massif, **ne revenir que quand TOUT est validé**.

Baseline réelle (post validation-state assessment, honnête) :
- CI vitest = **24 rouges / 1847 verts** (pré-existants, dérive test↔code) — point faible #1.
- PHP suites touchées = vertes (Fiscal 183, Order, Observability) ; full PHP non exhaustivement relancé.
- 5 clusters de points faibles (assessment) : CI, NF525 orphan cross-Z, mine TVA (frozen+dormant), concurrence 2-process, échecs silencieux.

## Règles d'orchestration (leçons de cette session)
1. **Jamais de claim "vert" sans la suite COMPLÈTE** (`vitest run` + `php artisan test` larges), pas de `--filter` étroit.
2. **Pas d'agents-écrivains parallèles sur fichiers partagés** — workflows = analyse/adversarial read-only ; le thread principal applique les edits séquentiellement + vérifie.
3. **Frozen-zone** (ZReportService/PricingService/etc.) = LOCK + owner-gate ; F1 TVA est DORMANT (0% VAT) → reste sur la checklist d'activation TVA, PAS touché ici (§8 NF525).
4. **Chaque fix : root-cause → décider stale-test vs code-bug → fix scope-minimal → test ciblé → suite complète → adversarial.**
5. NF525 chain `CHAIN OK` + frozen diff = 0 (hors LOCK) après chaque vague. No push.

## Vagues
- **V1 — Assainir la CI (24 vitest rouges).** Cluster par cluster : KdsSyncService résilience (8), kioskOfflineQueueV2 (5), kdsHistoryDrawerSentinel (2, obsolète recall), f004KioskCancelReason (2), KioskCartRestyle/kioskCounterPaymentFlow/posLoyaltyRedeemModal/posWizardComposerProfile/sidebarV1Cleanup/observabilityOutboxRoute/adminReportsBundleFreshness (1 ch.). Décomp : root-cause + adversarial par cluster ; appliquer ; converger `vitest run` = 0 rouge. **Priorité dans V1 : valider le chemin résilience KDS WS-down→poll (vrai risque caché, pas juste tests périmés).**
- **V2 — Concurrence.** F2 lockForUpdate sur le path staff `changePaymentStatus` (double-PAID) + re-check ; (harness 2-process réel si faisable).
- **V3 — NF525 robustesse (non-frozen).** Câbler `fiscal:verify-z-membership` en commande planifiée (cron) ; vérifier exposition `confirmCounterPayment`. (F1 TVA frozen reste checklist.)
- **V4 — Échecs silencieux.** Marqueur `broadcast_completed_at` (orphan clean-crash) ; backstop polling menu (catalog stale) ; F4 auto-86 item_id dans la clé de dédup `eventContract.js:264`.
- **V5 — Validation massive.** Full vitest + full PHPUnit verts ; NF525 CHAIN OK ; frozen 0 ; convergence adversariale 2 rounds identiques 0 P0/P1 ; re-confirmer le capstone visuel. → "tout validé".

Statut : V1 lancée.

## V1 COMPLETE 2026-05-29 — CI GENUINELY GREEN
- vitest 1871 passed / 0 failed (was 24 red); PHP 2712 passed / 0 failed (was 8 red).
- ALL stale-test/baseline drift behind real security+feature hardening; adversarially verified 0 holes; ZERO source/frozen changes (except owner-LOCKed ZReportService baseline bump).
- Commits: 262662563 (vitest x14), aefce71d8 (frozen baseline), 57fbf29bb (php x3).
- → V2 concurrency F2 next.

## V2 DONE — F2 concurrency
- changePaymentStatus staff path: lockForUpdate + in-lock idempotent-skip + fresh re-validate (double-PAID race). Race test green. Commit 4581043d1.

## V3 DONE — NF525 robustness
- fiscal:verify-z-membership cron-wired dailyAt(06:05) + pageable onFailure + sentinel. confirmCounterPayment cross-window exposure confirmed + COVERED by the detector (detect-only per owner). F1 TVA frozen+dormant stays on VAT-activation checklist. Commit 00a628e48.

## V4 — silent failures
- ✅ F4 auto-86 multi-item dedup (aggregate_id in correlation key), DORMANT-but-correct, 10 tests green. Commit 39257646f.
- ⏳ DEFERRED to a focused cycle (invasive for rare/dormant edges — NOT V1-LOCAL blockers; rushing a schema migration + sync-critical job rewrite + frozen-adjacent kiosk feature at this depth = regression risk):
  - **broadcast_completed_at orphan marker**: a worker hard-killed at the exact broadcast moment leaves dispatched_at SET + last_error NULL = identical to success -> escapes rescue/monitor/orphan-alarm. FIX SPEC: migration adds `domain_events.broadcast_completed_at` (nullable timestamp); DispatchDomainEventsJob Phase-3a sets it on successful broadcast; OutboxRescueCommand lane-B + MonitorOutboxStaleness use `broadcast_completed_at IS NULL` as the positive "not yet broadcast" discriminator (replacing the dispatched_at overload). RARE in V1 single-box; staleness monitor covers attempts-climbing cases.
  - **menu backstop polling (kiosk)**: a CatalogChanged swallowed while the kiosk WS never drops -> stale menu / sold-out item still orderable. FIX SPEC: add a low-frequency (e.g. 60-120s) menu cache-version poll on the kiosk as a backstop to the WS cache-invalidation; verify it does NOT touch the frozen Kiosk{Wizard,App,Upsell} components (likely lives in the menu service/store) — if it must touch a frozen component, requires a LOCK. Needs per-item stock seeded to live-validate (currently dormant).

## V5 — massive validation (next)

## V5 DONE — CONVERGENCE GO (test-e2e massive)
- Deterministic gates GREEN: vitest 1872/0, PHP 2714/0, NF525 CHAIN OK, frozen 15/15 (ZReportService under owner-LOCK + baseline re-blessed).
- Adversarial convergence (re-audit of all V1-V4 changes, 5 agents): **0 new P0, 0 new P1** introduced.
- Visual capstone re-confirmed post-V1-V4: fresh kiosk order A0006 (Coca-Cola, Plan-B) -> cash-instruction -> live on KDS with correct item + composition_snapshot frozen (Kiosk->KDS sync intact after the eventContract dedup change).
- 2 documented dormant/non-blocking edges: P2 z-membership cron may warn on legit-counted-post-close orders (detect-only review heuristic); P3 refund mirror total_by_tax_rate divergence (dormant 0% VAT, VAT-activation checklist).
- 3 owner-gated deferrals (not blockers): broadcast_completed_at orphan marker, kiosk menu-backstop polling, F1 PricingService TVA.

## FINAL: TOUT VALIDÉ (blocking tier) — V1 LOCAL Le Cayenne
CI genuinely green (was a false "all-green" narrative — 24 vitest + 8 PHP reds, ALL stale-test/baseline drift, adversarially verified 0 holes). The 5 weak points from the assessment fixed-or-managed: CI green / NF525 detector cron'd / concurrency F2 locked / auto-86 dedup / fiscal refund-netting (P0 #2, owner-LOCK). 0 new regressions. No push — owner reviews + fires /code-review ultra at discretion.
