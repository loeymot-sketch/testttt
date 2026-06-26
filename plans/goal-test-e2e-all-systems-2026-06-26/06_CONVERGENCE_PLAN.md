# 06 — PLAN DE CONVERGENCE (ultra-plan finalisation) — 2026-06-26

> La mission d'audit des 5 systèmes est **correctness-convergée** (0 P0, 1 P1 + 6 P2 healés,
> frozen 0, NF525 OK). Ce plan séquence le RESTE — finalisation + profondeur owed +
> ship — autour des 3 contraintes dures et des 4 décisions owner.

## CONTRAINTES (ce qui gouverne l'ordre)
- **C1 Disque** : DATA était 100% → 5,9Gi libérés (8 worktrees bruit-build). 15 worktrees
  restants = ~18Gi WIP réel d'autres sessions → **décision owner** pour libérer plus.
- **C2 Rate-limit API** : 3 workflows + 1 heal en // ont saturé l'API → re-runs adversaires
  = **séquentiels, fan-out réduit**.
- **C3 Gates owner** : 4 décisions (ci-dessous) — indépendantes du disque, résolubles MAINTENANT.

## LEDGER HONNÊTE — correctness DONE vs profondeur OWED
- ✅ **Correctness** : 5 systèmes audités, heals vérifiés (tests ciblés), NF525 OK, frozen 0.
- ⏳ **Profondeur owed** (ce que l'owner a demandé « terrain réel » et qui reste partiel) :
  - (a) **Live cross-surface click-through** Playwright (Borne→KDS→encaissement→Z) — fait par
    preuve DB/test, PAS par parcours cliqué réel.
  - (b) **Sweep visuel page-par-page** de CHAQUE surface — fait sur POS/KDS/OSS/Dashboard, pas exhaustif.
  - (c) **Re-run adversaire** des ~40 lanes rate-limitées — validées par SENTINELLE (invariants
    connus), pas par adversaire frais (découverte). Gap de profondeur, pas de correction.

---

## DÉCISIONS OWNER (recommandations pour one-shot approve)
| # | Décision | Recommandation | Pourquoi |
|---|---|---|---|
| D1 | Promo borne (panier affiche −X € jamais appliquée) | **Cacher le bloc promo en V1** (gate 1 ligne, miroir loyalty déjà masqué) | Honorer = scope business ; prix payé déjà correct ; réversible |
| D2 | Refund DELIVERED→RETURNED racine frozen | **Laisser la racine** (gate contrôleur ferme déjà le vecteur réel) | Toucher = LOCK + réécrire sentinelle pour gain marginal |
| D3 | Commit / PR (G3) | **Commit maintenant** (chemins explicites, exclure PNG 0-octet + bruit worktree) + PR | Travail vérifié ; non-committé = risque de perte |
| D4 | Ship rebuild | **`npm run dev`** (PAS production) | production strippe les guillemets CSS (leçon passée) ; dev sûr pour ces changements |
| D5 | Disque : 15 worktrees ~18Gi WIP | **Owner triage** : commit/discard les branches obsolètes | Hors mon scope (WIP d'autres sessions) |

---

## PHASES (séquencées)

### Phase A — UNBLOCK (maintenant)
- A1 ✅ Disque : 8 worktrees bruit retirés (369Mi→5,9Gi). [A2 owner : 15 restants]
- A2 Owner tranche D1–D5 (ci-dessus).

### Phase B — FINALISER LA VÉRIF (disque OK)
- B1 Rebuild bundles `npm run dev` → clôt les 2 sentinelles bundle-freshness (rouges = attendu).
- B2 Vitest COMPLET + PHPUnit smoke large (via guard DB-safe) → comptes de convergence.
- B3 **Live cross-surface click-through** (Playwright, auth ré-injectée) : Borne place commande
  → apparaît KDS → encaissement comptoir → PAID → fiscal alloué → Z. Capture chaque étape (profondeur (a)).

### Phase C — PROFONDEUR OWED (disque + API headroom)
- C1 Re-run adversaire SÉQUENTIEL des lanes rate-limitées (1 workflow, fan-out bas) :
  W2 a-attract/b-wizard/d-resilience · W3 a-board/c-sync/d-oss · W5 a-dashboard/b-catalogue/d-coupons.
  → attrape ce que les sentinelles ne couvrent pas (profondeur (c)).
- C2 Sweep visuel page-par-page exhaustif (Read+analyse chaque surface) → profondeur (b).
- C3 verify-before-report → tout nouveau finding healé TDD non-frozen.

### Phase D — CONVERGENCE + SHIP
- D1 Convergence 2-cycles (re-run jusqu'à findings identiques, P0+P1=0).
- D2 Si D3=oui : commit (chemins explicites) + PR.
- D3 Attestation finale (frozen 0, NF525 CHAIN OK, comptes tests) + BRAIN seal + Graphiti.

## CRITICAL PATH
C1 disque (✅ partiel) → B1 rebuild → B2/B3 vérif → C1/C2 profondeur → D convergence+ship.
Les décisions owner D1–D5 se résolvent EN PARALLÈLE (n'attendent pas le disque).
