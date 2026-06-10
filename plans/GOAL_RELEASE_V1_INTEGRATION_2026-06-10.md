# GOAL RELEASE V1 — intégration unique + build/test réel + test-e2e en boucle + adversaires (2026-06-10)

> Exécution du PLAN SUPERVISEUR (`reports/audit/supervisor-2026-06-10/`). Owner /goal : ultra-planifier, test-e2e en boucle, agents adversaires, max raisonnement.
> Branche release dédiée `release/v1-2026-06-10` (worktree isolé, base spine `059c20db7`). 0 push (gate owner). Frozen §7 = 0 ligne sans gate. Mutations e2e = clone jetable :8766 `foodking_e2e` uniquement.

## Tâches (chemin critique exécutable — P-3/P-5 = gates owner, hors scope autonome)

### T-0 — PRÉSERVER (P-0)
- T-0.1 ✅ patch du fix `source_ref` sauvegardé (`reports/audit/supervisor-2026-06-10/AT-RISK-*.patch`).
- T-0.2 appliquer ce patch sur la release (le fix appartient à la release : guard affordance W6) + committer chemins explicites.

### T-1 — RELEASE UNIQUE (P-1) : merges dans `release/v1-2026-06-10`
- T-1.1 merge `feat/pos-printer-saga-autoprint` (**0 conflit** vérifié) → débloque l'impression NF525 + fix netting TVA.
- T-1.2 merge `goal/cms-gestion-2026-06-10-spine` → résoudre conflits attendus : **i18n ×5 (union)**, **routes/api.php (additif)**, **bundles public/js + app.css + mix-manifest (NE PAS merger → rebuild après)**, PROJECT_BRAIN (union).
- T-1.3 décision owner différée : `heal/mobile-update-2026-06-10` (apps client standalone) = branche produit séparée, NON mergée backend (gate). NE PAS merger `heal/dashboard-redeep` (STALE, régression FR-locale).

### T-2 — BUILD + TEST RÉELS (P-2) : sur l'arbre intégré, en BOUCLE jusqu'au vert
- T-2.1 `npx mix --production` (rebuild bundles post-merge — résout la collision bundle de T-1.2).
- T-2.2 PHPUnit complet (foodking_test, DEVDB-GUARD) — loop heal jusqu'au vert, transcript committé.
- T-2.3 Vitest complet — loop heal jusqu'au vert, transcript committé.
- T-2.4 Sentinelles vertes (BranchScope, FormRequestAuthz, frozen-SHA, KdsTodayWindowTz) + frozen diff = 0.

### T-3 — TEST-E2E EN BOUCLE + ADVERSAIRES (P-2 suite) : sur :8766
- T-3.1 stand up :8766 (clone foodking_e2e) + soketi + worker `--queue=high,default` depuis la release.
- T-3.2 e2e cross-surface sur l'arbre INTÉGRÉ : sync kiosk→KDS→OSS, encaissement mixte+race, impression simulation (nouveau — printer mergé), CMS gestion (nouveau — cms mergé). 2 cycles identiques.
- T-3.3 adversaires par vague (refute file:line) → heal loop → adversaire épuisé.

### T-4 — PRÉPARER LES GATES (non destructif)
- T-4.1 GATE-DATA-1 : documenter le plan DB fiscale propre + catalogue 45 (déjà esquissé GO_LIVE_DB_CLEAN_STATE_PLAN) — exécution = owner.
- T-4.2 GATE-PUSH-1 : la release prête à pousser ; vérifier deploy.sh hot-patch warning ; push = owner GO.

## Convergence
Livrer quand : release-branch = superset prouvé (printer+cms+fix intégrés) · build prod OK · PHPUnit+Vitest verts (transcripts) · e2e 2 cycles identiques + adversaire épuisé · frozen 0 · 0 P0/P1 ouvert. Reste = gates owner (DATA-1, PUSH-1, mobile-merge) + matériel.
