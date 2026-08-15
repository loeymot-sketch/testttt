# T-1.3 — Inventaire des worktrees (2026-08-15, HEAD `e8923b10a`)

> **Aucune suppression** — porte owner **G1** (`GOAL_CONFORT_MAX_ET_BASE_PROUVEE_2026-08-15.md §6`).
> Cet inventaire sert à INTERDIRE le dispatch d'un agent dans un worktree périmé sans vérification
> préalable (`PARALLEL_PROTOCOL.md`, règle ajoutée ci-dessous).

## `.claude/worktrees/` (12)

| Worktree | SHA | Retard vs HEAD | Fichiers non commités |
|---|---|---:|---:|
| `agent-a4f605517bf912efe` | `b8b4fb76b` | **2366** | **1986** ⚠️ |
| `clever-hypatia-1e4f84` | `b8b4fb76b` | **2366** | **20** ⚠️ |
| `fix-supplements-non-calcules-2026-07-29` | `9ad026e94` | 567 | 0 |
| `s1-borne-2026-07-29` | `9c3dd9ec1` | 571 | 3 |
| `s2-caisse-stock-2026-07-29` | `bc4ec6961` | 549 | 2 |
| `s3-sync-2026-07-29` | `a4a823fc5` | 570 | 0 |
| `s4-cayenne-hachee` | `d4dd1ca49` | 491 | 0 |
| `s5-kds-2026-07-29` | `6202daccd` | 570 | 0 |
| `s6-integration-2026-07-29` | `302abed28` | 570 | 0 |
| `s7-vitrine-2026-07-29` | `06d39f7bd` | 570 | 7 |
| `s8-parcours-web` | `2fa71ccb8` | **2366** | 0 |
| `uber-v2-fetch` | `36fe2dbbe` | 488 | 0 |

## `.cursor/worktrees/testttt/` (5) — noms courts non descriptifs

| Worktree | SHA | Retard vs HEAD | Fichiers non commités |
|---|---|---:|---:|
| `ixe` | `648d9691c` | 2525 | 0 |
| `qdg` | `648d9691c` | 2525 | 0 |
| `nkt` | `d550f363b` | 2529 | 0 |
| `pit` | `d550f363b` | 2529 | 0 |
| `vmo` | `d550f363b` | 2529 | 0 |

## Racine parallèle (2)

| Worktree | SHA | Retard vs HEAD | Fichiers non commités |
|---|---|---:|---:|
| `testttt-kiosk` (`feat/kiosk-phase-9-5`) | `2df255140` | 2416 | **16** ⚠️ |
| `testttt-kiosk-p93` (`feat/kiosk-phase-9-3`) | `0e1c1b2a7` | 2431 | **191** ⚠️ |

## Verdict

- **Aucun des 20 n'est à jour.** Le plus proche (`s4-cayenne-hachee`) a 491 commits de retard.
- **4 worktrees portent du travail non commité et non intégré** : `agent-a4f605517bf912efe` (1986
  fichiers — vraisemblablement un dump complet, à examiner avant toute décision), `clever-hypatia-1e4f84`
  (20), `testttt-kiosk-p93` (191), `testttt-kiosk` (16). **Ne rien supprimer sans que l'owner ait
  vérifié qu'aucun de ces fichiers n'est un travail perdu.**
- Les 16 autres, sans travail en cours, sont candidats sûrs à suppression **après décision owner (G1)**.

## Règle inscrite (`PARALLEL_PROTOCOL.md`)
> Avant de dispatcher un agent dans un worktree existant, vérifier son retard
> (`git rev-list --count <sha-worktree>..HEAD`). Au-delà de quelques dizaines de commits, créer un
> worktree FRAIS depuis HEAD plutôt que de réutiliser l'existant — un agent qui audite un worktree à
> plusieurs centaines de commits de retard audite un code qui n'existe plus.
