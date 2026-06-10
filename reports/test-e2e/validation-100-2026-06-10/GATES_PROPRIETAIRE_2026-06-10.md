# GATES PROPRIÉTAIRE — GOAL ULTRA-AUDIT TOTAL 2026-06-10

> Chaque décision ci-dessous t'appartient (design / frozen / NF525 / data / intégration).
> Le goal continue sur tout le reste ; rien ici ne bloque la validation des systèmes existants.

## GATE-PRINT-1 — Intégrer la chaîne d'impression dans la spine
- **Constat (prouvé)** : la spine déployable `heal/pre-cloud-exec-2026-06-05` ne contient AUCUNE chaîne d'impression (aucune migration `print_jobs`, aucun command print, pas de `tools/print-agent/`). Toute la chaîne (renderer NF525 + listeners post-commit + claim atomique anti-double-impression + `pos:configure-receipt-printer` + agent Node ESC/POS) vit sur la branche **`feat/pos-printer-saga-autoprint`** (e446a2084, 18 tests, 0 frozen), jamais mergée.
- **Conséquence** : l'objectif « il ne restera que brancher l'imprimante » est IMPOSSIBLE sans intégrer cette branche (ou ré-implémenter). Le branchement physique présuppose le software d'impression déployé.
- **Options** : (A) merger `feat/pos-printer-saga-autoprint` → spine, re-valider (tests print + régression ciblée) — recommandé ; (B) cherry-pick minimal ; (C) reporter l'impression à V1.0.X (le restaurant n'imprime pas de ticket → non conforme à l'usage caisse réel).
- **Recommandation** : A. **Validation W-P EXÉCUTÉE (2026-06-10)** : SIMULATION VALIDÉE ✅ — 62/62 tests verts sur la branche (HEAD `b27365295`, 1 heal au-delà de e446a2084 : netting TVA remise) ; claim atomique = `UPDATE … WHERE receipt_print_count=0` (`PosReceiptAutoPrinter.php:79-87`) + release-on-failure (l.102-110) ; double-voie post-commit (POS inline payé + comptoir ; kiosk/web/unpaid exclus) ; renderer NF525 complet (SIRET/TVA intra/Opérateur/TVA par taux nettée) ; allowlist host sentinel. **Merge spine = 0 collision** (merge-base `ad29e7875`, `git merge-tree` sans conflit). Précision : la voie `print_jobs`+agent Node (hybride cloud) vit sur une 3e branche `massive-e2e-0604-wt` — la présente = voie directe LAN:9100, suffisante pour V1 LOCAL. Après merge, il ne reste QUE : `pos:configure-receipt-printer <IP>` + test ticket physique + bypass OFF en prod.

## GATE-INT-2 — Intégrer les fixes satellites validés (KDS/OSS + Borne)
- **Constat** : 17 fixes KDS/OSS (validés 105 tests, sessions 06-09) + 5 fixes Borne (69 Vitest + 27 PHPUnit) ont été portés dans le **checkout principal `testttt` (branche `heal/cms-pr1-quickwins`, NON COMMITTÉS)** — pas dans la spine. La spine n'a PAS ces correctifs (P0 KDS bump 422/500 sur notification-throw, allergènes numériques droppés, race offline-queue borne, etc.).
- **Options** : (A) porter les 9 fichiers KDS/OSS + 6 fichiers borne + leurs 12 fichiers de tests sur la spine (clean-apply à vérifier), re-run leurs suites ; (B) committer dans le checkout principal sur une branche dédiée et merger ; (C) ignorer (perte de 22 fixes validés).
- **Recommandation** : A — je peux l'exécuter sur ton GO (aucun frozen touché par ces fixes).

## GATE-Z-GAP-1 — Agrégateur Z : fenêtre par created_at vs gaps sans Z ouvert (NOUVEAU, P1)
- **Constat (prouvé empiriquement 2026-06-10 sur le clone)** : `ZReportService::aggregate` (FROZEN) fenêtre par `created_at ∈ (opened_at, closed_at]`. Une commande numérotée créée pendant qu'AUCUN Z n'est ouvert (gap entre close N et open N+1) n'est agrégée par AUCUN Z signé — silencieusement. Repro : Z seq 20 fermé à **0,00 € / 0 commande** alors que **10 commandes numérotées PAYÉES (22,70 €)** étaient settled dans sa fenêtre (créées dans le gap 06-08→07:04).
- **Mitigation livrée (non-frozen)** : détecteur `fiscal:verify-z-membership` healé (classe NO-Z-GAP) + 2 tests régression (4/4). Sur le clone : 2110 orphelins-gap détectés (artefact data de test ; les Z n'étaient ouverts que sporadiquement).
- **Décisions owner** : (1) exécuter le détecteur healé sur **OVH prod** (combien d'orphelins réels ?) ; (2) trancher le fix d'altitude dans le FROZEN `ZReportService` (rattacher le gap au Z suivant : borne basse = max(closed_at précédent, opened_at) — chantier M6-002/S13-02 déjà gated) ; (3) en attendant : garantir la continuité open/close par cron (jamais de gap pendant le service).

## GATE-W6 (hérité, re-confirmé) — Parité wizard CAISSE
- La caisse ne rend les wizards dynamiques que derrière `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true` ET il faut écrire un renderer `generic_choices` dans le FROZEN `pos-wizard.js`. Doc : `reports/test-e2e/wizard-parity-2026-06-09/W3_CAISSE_GATE_W6_DECISION.md`. Path A = LOCK + parité ; Path B = dette V1.0.X.

## GATE-DATA-1 (hérité) — Reset DB opérationnelle pour go-live
- Plan écrit : `reports/release/GO_LIVE_DB_CLEAN_STATE_PLAN.md`. Partie destructive = exécution owner uniquement. NB : la DB locale dev `foodking` est toujours cassée (audit_logs/z_reports droppées par un job concurrent le 06-09) — la prod OVH n'est PAS affectée.

## GATE-PUBLISH-1 (hérité) — 29 mentions LCEN web standalone
- À fournir avant publication du site web (SIRET, hébergeur, directeur de publication…).

## GATE-PUSH-1 — Push de la spine
- La spine locale est en avance de 60+ commits sur origin (et porte maintenant les commits de validation de cette session). Push + déploiement OVH = décision owner (§10).

## Data owner (non bloquant, divulgué)
- « Crudite » sans accent dans la sidebar catégories (data catalogue, pas code).
- Seeding catalogue des upgrades frites (ItemExtra Grande/Cheddar + étapes wizard max-1) pour activer la facturation CAISSE-01 en opérationnel.
- `.env` OVH : `TIME_FORMAT="H:i"` (24h) à reporter (le défaut code est déjà H:i ; seul un .env qui force "h:i A" produit du 12h — c'était le cas du clone e2e, corrigé).
