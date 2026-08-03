# GOAL — TEST-E2E RÉEL ABUSIF · TOUS SYSTÈMES · 2026-06-26

**Mission** : valider à 100 % chaque système, **page par page**, en conditions
réelles (Playwright live, DB `foodking_e2e`) — texte, technique, logique, archi,
**synchro inter-systèmes**, et **psychologie utilisateur** (client/cuisinier/
commerçant), pas juste le visuel. Boucle abusive jusqu'à convergence. + **MAJ menu
mobile/web** au canon Le Cayenne. **Discipline stricte → lire EN PREMIER
`goal-test-e2e-all-systems-2026-06-26/00_DISCIPLINE.md`.**

## §0 Préambule
- **Working-tree** : 718 fichiers non-commités = bruit worktrees + WIP (ESC/POS, MultiVariation, seeder menu). **Gate G0** : décision commit/stash owner AVANT W1.
- **Convergence** = 2 cycles consécutifs P0+P1=0 ET findings identiques (par page).
- **Pipeline/tâche** = `ultra-audit-profond`. Fan-out + matrice → `00_DISCIPLINE.md §4`.
- **Anti-hallucination** = règle #1 : tout `file:line`/produit vérifié, jamais inventé.

## §1 Les 5 systèmes (carte → fichier-plan)
| # | Système | Maturité | Lentille | Fichier |
|--|--|--|--|--|
| 1 | CAISSE (POS) | mûr · fiscal-critique | 🧑‍💼 commerçant | `01_SYSTEM_CAISSE.md` |
| 2 | BORNE (kiosk) | mûr | 🧑 client | `02_SYSTEM_BORNE.md` |
| 3 | KDS + OSS | mûr | 🧑‍🍳 cuisinier | `03_SYSTEM_KDS_OSS.md` |
| 4 | WEB + APP + menu | standalone · **menu PÉRIMÉ** | 🧑 client | `04_SYSTEM_WEB_APP_MENU.md` |
| 5 | CENTRAL | mûr | 🧑‍💼 commerçant | `05_SYSTEM_CENTRAL.md` |

Tous les anchors sont vérifiés (cartographie 5 agents, file:line réels).

## §2 Vagues (séquentiel par défaut ; audit parallèle DANS chaque vague)
- **W0 Pré-vol** : G0 working-tree, backup branche + dump DB, baseline (phpunit count, `audit_logs` count+last_hash), confirm serveur + `foodking_e2e`, gates owner.
- **W1 CAISSE** · **W2 BORNE** · **W3 KDS+OSS** — séquentielles (fiscal/sync partagés).
- **W4 WEB+APP+MENU** ∥ **W5 CENTRAL** — **parallèles** (arbres disjoints ; W4 écrit `data/menu.js`).
- **W6 CROSS-SURFACE E2E** : parcours réels Borne→KDS→OSS→encaissement→Z ; POS→KDS ; cohérence prix vitrine↔caisse. Prouve `SYNC_CONTRACT` bout-en-bout.
- **W7 Convergence finale** : smoke complet (PHPUnit+Vitest+Playwright), frozen-diff 0, NF525 CHAIN OK, 2 cycles identiques, sign-off owner.
- Checkpoint 6-points + interrupt-resume + blocage → `00_DISCIPLINE.md §7`.

## §3 Owner gates (WHO/WHAT/WHERE)
| Gate | Quoi | QUI | OÙ | Statut |
|--|--|--|--|--|
| G0 | Décision working-tree (commit/stash WIP) | owner | BRAIN §2 | PENDING |
| G1 | Touche frozen (pos-wizard/PaymentComponent/kiosk wizard) si un heal l'exige | owner (LOCK contre-signé) | `plans/LOCK_*.md` | conditionnel |
| G2 | Fantôme-upcharge viande +2,50 (frozen + décision business) | owner | escalade | conditionnel |
| G3 | Push / PR (jamais sans owner explicite) | owner | commit/PR | PENDING (fin) |
| G4 | Go-live physique (TPE réel, matériel) | owner physique | hors-scope V1 | différé |

## §4 Lancement (« lance le GOAL »)
1. Lire ce GOAL + `00_DISCIPLINE.md` + le fichier-système de la vague.
2. W0 pré-vol (gate G0).
3. Par page : boucle 9 étapes (`00_DISCIPLINE.md §1`), fan-out parallèle adversaire+audit+verify, heal TDD scope-minimal, convergence.
4. Checkpoint fin de vague → BRAIN §2/§3 + commit checkpoint.
5. W6 cross-surface, puis W7 convergence finale → sign-off owner.

## §5 Règle finale
DONE = **production-perfect**, pas « presque ». Chaque page : 0 P0/P1, frozen-diff 0,
NF525 OK, FR propre, **prix affiché == backend SSOT**, screenshots analysés,
adversaire muet 2 cycles. Menu mobile/web == canon. Sinon : heal / block / escalate.
Jamais feindre la certitude — partiel > faux, bloqué > silencieusement dangereux.
