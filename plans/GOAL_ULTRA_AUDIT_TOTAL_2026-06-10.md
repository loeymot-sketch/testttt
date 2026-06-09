# GOAL ULTRA-AUDIT TOTAL — plan maître d'exécution (2026-06-10)

> Source : `~/foodking-review/app/insights/GOAL_2026-06-10_ultra-audit-total-tous-systemes.md` (GOAL maître v2 owner).
> Cible : spine `heal/pre-cloud-exec-2026-06-05` (worktree `pre-cloud-exec`), HEAD départ `5682fe06e`.
> Harnais : **:8766 = clone jetable `foodking_e2e`** (mutations) · soketi :6001 · worker queue `high,default` · PHPUnit→`foodking_test` (DEVDB-GUARD).
> Op DB `foodking` / :8765 : **JAMAIS touchée** (aucun serveur :8765 démarré cette session).

## État d'entrée (vagues déjà exécutées cette session — preuves committées)

| Vague | Résultat | Preuve |
|---|---|---|
| W-T technique | **PHPUnit 3085/0** (505s, full suite, foodking_test) · **Vitest 2096/0** après heal i18n | `commit 08943edfb` (heal studio.product_composer_button de/bn) |
| W-S sync fraîche | kiosk #4325 → outbox `order.created` dispatché → KDS A0006 → Démarrer(4→7) → Prêt(7→8) → `order.status_changed` dispatché → OSS « Prêt » · 0 JS err | `tests/e2e/zz-sync-fresh-order-kds-oss-2026-06-10.spec.js` + `reports/test-e2e/validation-100-2026-06-10/sync/` |
| W-U sweeps | Central 8/8 · Kiosk 4/4 (0 crash, 0 label brut, 0 console err) | `tests/e2e/__screenshots__/central-sweep-2026-06-07/` (re-run 06-10) |
| W-F fiscal | Encaissement 10/10 → séquence **2073→2082 gap-free** · `fiscal:verify-chain --all` = **CHAIN OK** | sortie artisan + DB dump |
| W-ADV r1 | 14 findings adversaires → triés : 1 réel env (ADV-4 TIME_FORMAT 12h sur `.env.e2e` → fixé `H:i`), 4 gaps harnais d'évidence, 2 réfutés (mm:ss mal lu ; "recyclage" = rendu déterministe), reste P2/P3 divulgués | agent a078582ec62a69522 |

Leçon harnais committée : `DispatchDomainEventsJob` → queue **high** ; worker doit écouter `--queue=high,default`.

## Vagues restantes (triade Pilotes / Moniteurs / Adversaires)

- **W-A VOLUME ≥40 + MONITEURS** : ≥40 commandes variées sur :8766 (zz-kiosk-10-orders ×2 incl. wizards multi-viandes, + 6 déjà créées, + encashments cash/carte-réf) pendant qu'un moniteur continu capture KDS/OSS/dashboard + latences outbox (occurred_at→dispatched_at). Intégrité : items, snapshots, fiscal gap-free, historique==DB.
- **W-B PARCOURS 100%** : pilotes par système (Borne, Caisse, KDS+OSS, Dashboard gérant) — breakdown + galerie captures de chaque écran/état, lues + analysées (Read).
- **W-C RACES** : double-encash same order (idempotency replay), kiosk+caisse simultanés, Z-close pendant commandes en vol.
- **W-D CLÔTURE Z** : `fiscal:close-all-active-branches` sur clone, buckets corrects, chain re-OK, historique/archives cohérents.
- **W-P IMPRESSION (SIMULATION)** : ⚠️ la chaîne print (print_jobs/ESC-POS/claim/`pos:configure-receipt-printer`) **n'est PAS dans la spine** — elle vit sur `feat/pos-printer-saga-autoprint` (e446a2084, 18 tests). Validation sur SA branche + **GATE-PRINT-1 propriétaire** (intégrer ou non avant branchement imprimante).
- **W-ADV r2 + CONVERGENCE** : adversaires re-disputent chaque système ; cycle 2 complet (PHPUnit re-run [déjà lancé bg], Vitest re-run, specs e2e re-run) → 2 cycles identiques.
- **W-LIVRAISON** : rapports par système + consolidé + `GATES_PROPRIETAIRE_2026-06-10.md` + `ETAT_FINAL_A_Z_2026-06-10.md` + BRAIN §2/§3 + Graphiti + insight session + verdict ship.

## Gates propriétaire identifiés (à date — détail dans GATES_PROPRIETAIRE)
- GATE-PRINT-1 : intégration branche printer-saga dans la spine (seul reste matériel = brancher l'imprimante + ticket réel).
- GATE-W6 (hérité) : parité wizard caisse (FROZEN pos-wizard.js, renderer generic_choices absent).
- GATE-DATA-1 (hérité) : reset DB go-live (destructif = owner).
- GATE-PUBLISH-1 (hérité) : 29 mentions LCEN web standalone.
- INTÉGRATION satellites : 17 fixes KDS/OSS + 5 fixes borne validés (105+96 tests) vivent NON COMMITTÉS dans le checkout principal `testttt` (branche heal/cms) — à intégrer à la spine (décision owner, cf. consolidated state).

## Sévérités & convergence
P0/P1 bloquent ; P2/P3 divulgués. Convergence = 0 P0+P1 sur 2 cycles identiques + adversaire épuisé (2 rondes) + intégrité cross-surface + CHAIN OK + 0 frozen non-gaté + captures 100% parcours analysées.
