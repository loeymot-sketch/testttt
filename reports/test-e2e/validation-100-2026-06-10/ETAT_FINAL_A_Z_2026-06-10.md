# ÉTAT FINAL A→Z — GOAL ULTRA-AUDIT TOTAL (2026-06-10)

> Spine validée : `heal/pre-cloud-exec-2026-06-05` (worktree `pre-cloud-exec`), du HEAD `5682fe06e` aux commits de cette session.
> Toutes les mutations sur le clone jetable **:8766 `foodking_e2e`** — l'opérante n'a JAMAIS été servie ni touchée (aucun serveur :8765 démarré).

## Tableau système-par-système

| Système | Technique | Sync | UI/UX visuel | Preuves |
|---|---|---|---|---|
| **Backend (suite complète)** | ✅ PHPUnit **3085/0 ×2 cycles identiques** (505s/509s, foodking_test, DEVDB-GUARD) | — | — | `tmp/phpunit-full.log`, `tmp/phpunit-cycle2.log` |
| **Frontend (suite complète)** | ✅ Vitest **2096/0 ×2 cycles identiques** (+1 heal i18n `08943edfb`) | — | — | `tmp/vitest-*.log` |
| **Borne (kiosk)** | ✅ 20 commandes variées ×2 runs (wizards multi-steps, snapshots scellés, fiscal NULL correct en PENDING_COUNTER) | ✅ commande→outbox dispatché | ✅ sweep 4/4 (0 crash/label brut/console err) ; rupture stock = filtrage « Épuisé » correct (vérifié live) | `volume-run3/4.log`, sweeps |
| **Caisse (POS/encaissement)** | ✅ encaissement 10/10 + **mixte CASH/CARD+réf/TICKET 6/6 DB-prouvé** (pos_payment_method 1/2/5, réf SumUp tracée `pos_payment_note`) | ✅ payment_confirmed events dispatchés | ✅ surface encaissement + modal par mode capturés | `encaissement-mixed/`, ENC10 |
| **KDS** | ✅ bump 2-temps Démarrer(4→7)→Prêt(7→8) persisté serveur ×2 cycles | ✅ carte fraîche visible <20s post-commande ; cap overflow « +N en attente » fonctionne | ✅ cartes propres FR, palette ; P2 divulgués (« ATTENT » tronqué) | `sync/02*,03*` |
| **OSS** | — | ✅ commande fraîche même-jour rendue colonne « Prêt » (**gap Wave-V fermé**) ×2 cycles | ✅ mur 2 colonnes propre | `sync/04-oss-ready.png` |
| **Dashboard gérant** | ✅ intégrité numérique DB==UI exacte (CA jour, 10 tx du jour IDs+montants, caisse unifiée 68,90 €) | — | ✅ **32/32 pages** 0 crash/0 pageerror/0 HTTP≥400/0 console err ; 6 P2 + 4 P3 divulgués (commit `837f16b4a`, 39 captures analysées) | `dashboard/` |
| **Sync (outbox/realtime)** | ✅ **172 events jour, 100% dispatchés, 0 erreur** ; régime établi : **latence moyenne 3,6 s** (cadence worker sleep 3s), max 10 s sous burst | ✅ chaîne kiosk→KDS→OSS prouvée live ×2 | — | `monitor-outbox.jsonl`, métriques SQL |
| **Fiscal NF525** | ✅ séquences **gap-free prouvées** (2073→2082, 2089→2100…) ; `fiscal:verify-chain --all` = **CHAIN OK** (×4 exécutions) ; Z-close/open cycle OK ; **race double-encaissement = 1 txn/1 fiscal/0 dup** (idempotency replay prouvé) | ✅ | — | sorties artisan + `race-results.json` |
| **Stress (volume+concurrence)** | ✅ **50/50 mixed** (POS quotée SSOT + kiosk), concurrence 10 — 0 dup fiscal, 0 dup queue, 0 fuite cross-branch (+ heal harnais lane POS committé) | ✅ | — | `stress-50-mixed.md` |
| **Impression (SIMULATION)** | ✅ **62/62 tests verts** sur `feat/pos-printer-saga-autoprint` (HEAD `b27365295`) : double-voie post-commit, renderer NF525 complet, claim atomique + release-on-failure, allowlist host ; **merge spine = 0 collision** | — | — | rapport agent W-P (GATES PRINT-1) |

## Transversales
- **Intégrité numérique cross-surface** : montant commande == transaction == bandeau caisse == dashboard (68,90 € / IDs exacts) ; total kiosk == DB (volume specs assert items+totaux+snapshots).
- **Adversarial** : round 1 = 14 findings → 1 réel healé (TIME_FORMAT env clone), 2 réfutés sur preuve, 4 gaps d'évidence harnais healés, reste P2/P3 divulgués. **Round 2 = verdict EXHAUSTED (échec à casser)** : les 6 verdicts r1 tiennent sur preuves nouvelles (confirmation kiosk « Rendez-vous en caisse #A0113 » réelle, carte KDS élément, apiStatus=201, timers mm:ss prouvés par progression +3s exacte inter-captures, PNG non recyclés) ; 3 P2 nouveaux divulgués : ADV2-1 coupons i18n résiduel (formulaire), ADV2-2 divergence env↔DB du format horaire (`site_time_format` DB = « 12 Hour » — data-op owner), ADV2-3 collision visuelle de codes journaliers « N°A0001 » ×2 dans la file encaissement (commandes stale inter-jours sans désambiguateur) ; + 2 P3 (« ATTENT » tronqué ; replay 2xx silencieux pour le 2e caissier d'une race).
- **Frozen zones** : 0 ligne modifiée sur les 15 fichiers §7 (tous les commits de session = lang/tests/specs/harnais/détecteur non-frozen + rapports).
- **Découverte P1 (gate)** : **Z-GAP-1** — l'agrégateur Z (frozen) ne couvre pas les commandes créées hors fenêtre Z ; détecteur `fiscal:verify-z-membership` healé (classe NO-Z-GAP, 4/4 tests) ; chantier aggregateur = gate owner M6-002/S13-02 (cf. GATES).

## Heals livrés cette session (commits)
1. `08943edfb` — i18n `studio.product_composer_button` de/bn (Vitest 2094→2096).
2. `…sync spec…` — preuve sync fraîche + heals évidence ADV-1/2/6.
3. `…mixed/race…` — spec encaissement mixte + race idempotency.
4. `…stress…` — heal lane POS du harnais stress (quote SSOT signée).
5. `…Z-GAP-1…` — détecteur fiscal NO-Z-GAP + 2 tests régression.
6. `837f16b4a` — spec parcours dashboard 32 pages + 39 captures (agent pilote).
7. `.env.e2e` TIME_FORMAT 12h→24h (fichier gitignoré, non commitable).

## ⚠️ Ce qui reste (UNIQUEMENT des décisions owner — voir GATES_PROPRIETAIRE_2026-06-10.md)
1. **GATE-PRINT-1** : merger la branche impression (validée 62/62, merge sans collision) — sans elle, « il ne reste que brancher l'imprimante » est impossible.
2. **GATE-INT-2** : porter les 22 fixes satellites validés (KDS/OSS 17 + borne 5) depuis le checkout principal (non commités) vers la spine.
3. **GATE-Z-GAP-1** : exécuter le détecteur healé sur OVH + trancher le fix agrégateur (frozen).
4. **GATE-W6 / GATE-DATA-1 / GATE-PUBLISH-1 / GATE-PUSH-1** : hérités, inchangés.
5. **Données owner** : seed upgrades frites (active CAISSE-01), `TIME_FORMAT=H:i` sur OVH, « Crudite »→« Crudités ».
6. **MATÉRIEL (l'unique reste physique)** : brancher l'imprimante SAGA (LAN:9100) + `pos:configure-receipt-printer <IP>` + ticket réel — **après GATE-PRINT-1**.

## P2/P3 divulgués non-bloquants (détail dans le rapport dashboard + adversarial)
Coupons 8 labels i18n bruts · montant brut en ligne OnlineOrderList (fix jumeau déjà appliqué sur PosOrderList, à porter) · datepickers US/EN (5 composants) · CashOverview date UTC (fenêtre 00h-02h) · `site_time_format` 12h en DB settings · troncatures stock-rupture/« ATTENT » KDS · N°4225 fallback id brut footer KDS · observability `toLocaleString()` sans fr-FR.
