# GABARIT OBLIGATOIRE — Rapport de mission `MISSION_ONB<nn>_<SLUG>.md` (2026-08-26)

> Le rapport de mission est le SECOND fichier que le propriétaire donne à une session avec le GOAL. Il porte
> l'ÉTAT DES LIEUX daté et la MÉMOIRE de la mission : ce qui a été mesuré, ce qui marche, ce qui est cassé, ce
> qui a déjà été fait ailleurs, les pièges, les décisions en attente, et le journal que la session remplit.
> Cible : 12-25 Ko. Français. Toute affirmation = preuve (capture lue, réponse API, requête DB, file:line).

```
# MISSION ONB-<nn> — <TITRE> · Rapport de mission
- GOAL : `plans/GOAL_ONB<nn>_<SLUG>_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du 2026-08-26 (HEAD `43b120c7d`, arbre principal servi sur :8766, base locale `foodking_e2e`)
- Port de session attribué : <port> · Voie : <voie> · Sessions compatibles en parallèle : <liste ONB-xx>

## 0. COMMENT LANCER (à coller tel quel dans une nouvelle session Claude Code, depuis l'arbre principal)
<bloc de prompt de 10-15 lignes : lire CONSTITUTION → PROJECT_BRAIN §2 → SYSTEM_MAP → PARALLEL_PROTOCOL → l'index →
ce rapport → le GOAL ; pré-vol §0.1 ; « lance le GOAL » ; discipline ; format de compte rendu FIXÉ / VÉRIFIÉ / BLOQUÉ>

## 1. CONTEXTE ET VISION
Pourquoi ce GOAL existe (demande propriétaire 2026-08-26), la place dans l'index (dépendances, ordre), le persona.

## 2. ÉTAT MESURÉ LE 2026-08-26 (reconnaissance web réelle)
2.1 Périmètre parcouru (URL + état) · 2.2 CE QUI MARCHE (preuves) · 2.3 CONSTATS P0→P3 (contrat complet, captures
citées par chemin) · 2.4 ANGLES MORTS du commerçant · 2.5 « CAYENNE » EN DUR · 2.6 Mesures chiffrées (DB, perf, tests)

## 3. CE QUI A DÉJÀ ÉTÉ FAIT (ne pas refaire)
GOAL/rapports antérieurs qui touchent la zone (fichier, date, ce qui est prouvé vert, ce qui a été différé), tests
existants (chemins + comptes), décisions propriétaire déjà prises (BRAIN §6).

## 4. ANCRAGES CODE (table)
| Rôle | Fichier | Lignes clés | Note |

## 5. BASES CHIFFRÉES À NE PAS DÉGRADER
Tests (PHPUnit filtre de la zone : commande + compte), Vitest, sentinelles concernées, compteurs DB, temps de
chargement des pages de la zone (mesurés).

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
Table Gate · Question posée en une phrase · Options A/B · Recommandation · Conséquence si non tranché.

## 7. RISQUES, PIÈGES, INSTRUMENTS
Pièges prouvés du projet (reducedMotion inerte, F1-F12 inertes en headless, produit inexistant, fixtures périmées,
`:8000` = autre worktree, symlink vendor, `.env.testing`, cache Spatie/permissions, cache settings, worker absent,
file `notifications` orpheline 1 490 jobs, serveur de dev mono-requête) + pièges propres à la zone.

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict (continue/heal/block/escalate) | Commit |
Puis : constats nouveaux (contrat), décisions prises, fichiers touchés (liste exhaustive pour le superviseur), état
final (FIXÉ / VÉRIFIÉ / BLOQUÉ).
```
