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
