# CONVERGENCE FINALE — GOAL convergence-golive (2026-07-07)

Base `24e8a09c3` → HEAD (voir git). Branche pos/category-first-caisse-2026-06-23. NON poussé (gate owner).

## Verdict : CONVERGÉ — cycle 1 GREEN + cycle 2 REDO CLEAN après heal d'une P0

## Parcours de convergence (honnête)
1. **W2 cycle 1** : 4 surfaces GREEN (caisse/cuisine/borne/synchro), P0+P1=0.
2. **W7-W11 audit profond interne** (calibré V1, verify-before-trust) : 60 findings → 21 REAL. Fixés : label.note (raw label ticket cuisine borne FR, 0-frozen), DB KDS index (perf), mobile standalone (×5), web standalone (promo fantôme+adresse). FAUX rattrapé sur mon propre audit (Cache::lock déjà release).
3. **W12 cycle 2** : BLOCKED — l'adversaire a attrapé une **P0 que le fix DB index avait introduite** : FORCE INDEX via `->from(DB::raw(...))` en collision avec BranchScope (frozen) → board KDS VIDE en HTTP (42 commandes en DB). Tests verts car FORCE INDEX MySQL-gated + CI SQLite (green≠prod-safe).
4. **HEAL `f959e813b`** : FORCE INDEX RETIRÉ (board = baseline pré-optim, byte-identique), test de régression HTTP-BranchScope ajouté. Perf = backlog (refaire sans toucher FROM + CI MySQL).
5. **W12 cycle 2 REDO** : CLEAN — board KDS confirmé fonctionnel 3 façons sur MySQL réel (navigateur 3 cartes, HTTP branch-staff 200/40, service-level 40 sans exception).

## Fixes livrés (convergence)
| # | Finding | Fix | Commit |
|---|---|---|---|
| label.note | P2 raw label ticket cuisine FR | clé i18n FR (0-frozen) | d4a5877d0 |
| DB KDS board | P2 perf → **P0 régression healée** | FORCE INDEX retiré + test régression | ca5629658→f959e813b |
| Mobile standalone | P3×5 (prix/loyalty/CONNECTION_PLAN fantômes) | alignés canon | 89cf0ec8e |
| Web standalone | P2×2 promo fantôme+adresse | backend-only promo + adresse unifiée | 08c4a30 (repo web) |

## Gates finaux
- Vitest 2276/0 (3 skip) · PHPUnit 3185/0 · KDS 172/0 (test régression inclus)
- Frozen diff 24e8a09c3..HEAD : SEULS pos-wizard.js + KioskWizardComponent.vue (LOCK owner8)
- NF525 CHAIN OK ×4 · audit_logs 4916 append-only

## Backlog documenté (P3, non bloquant)
- Perf board KDS full-scan : refaire sargable + tier CI MySQL réel (le SQLite ne catch pas un FROM-raw gaté-MySQL).
- db-schema append-only triggers, kiosk-offline abandoned order, web menu.js 31 vs 41 items drift, dead-code wizard mobile.

## Gates OWNER en attente
G1 C33 fiscal (Appliquer/Différer, PENDING) · G5 push+deploy · G2 photo Cordon Bleu · G3 pont borne caché · G4 rotation clés AWS.

## Leçon majeure
Le cycle 2 adversaire en fenêtre non-contendue a attrapé une P0 que 172 tests verts + SQLite CI masquaient. « Green ≠ prod-safe » : un fix perf MySQL-gated a cassé la prod, invisible en CI SQLite. La discipline « valider 1000% avec adversaire » a directement empêché une régression prod de l'écran cuisine.
