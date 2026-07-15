# CAMPAGNE ULTRA E2E TOUS SYSTÈMES — STATE (2026-07-10)

> Manifest resumable. GOAL : `plans/GOAL_ULTRA_E2E_ALL_SYSTEMS_2026-07-10.md`.
> Branch `pos/category-first-caisse-2026-06-23` · NF525 baseline audit_logs=4938 hash=ffe782b9 z=25.

## W1 — BASELINE SUITES ✅ TERMINÉ (640 tests passed, 0 fail)
| Système | Tests | Résultat |
|---|---|---|
| POS Caisse | 124 | ✅ passed |
| Kiosk Borne (+Phase5) | 34 + 13 | ✅ passed |
| KDS | 47 | ✅ passed |
| Fiscal NF525 | 246 | ✅ passed |
| Order (state machine) | 50 | ✅ passed |
| OSS | 1 | ✅ passed |
| Symmetry (cross-surface) | 5 | ✅ passed |
| Branch isolation | 14 | ✅ passed |
| Security | 106 | ✅ passed |
| **TOTAL** | **640** | **✅ 0 failure** |
NF525 chain inchangée (tests = DB séparée). → **chaque fonctionnalité couverte par tests = VERTE.**

## W3 — AUDIT ADVERSAIRE (workflow wono1fchh, en cours)
5 systèmes (pos/kiosk/kds/oss/cross-sync) × prouve→vérifie. Findings → `findings/`.

## W2 — PARCOURS RÉELS e2e (à faire)
- Cross-surface : 1 commande borne → caisse + KDS + OSS cohérente (prix/compo/queue).
- POS : category→wizard→paiement→tiroir→Z.
- Web standalone : re-vérif non-régression (déjà convergé 2026-07-09).

## Fixes ce jour (non commités, à déployer VPS) — voir [[fix_borne_429_caisse_cancel_kiosk_2026-07-10]]
- borne 429 (kiosk-quote bucket) + caisse cancel (actorIsKioskMachine) + régression 7/7.

## Prochaine action
Triage findings W3 → W2 parcours réels → heal boucle → convergence.
