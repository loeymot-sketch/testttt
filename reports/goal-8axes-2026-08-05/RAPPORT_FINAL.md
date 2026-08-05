# GOAL 8 AXES — Rapport final (2026-08-05)

**Plan** : `plans/GOAL_OWNER_8AXES_CUISINE_CAISSE_WEB_2026-08-05.md` · **Plage** : `dbea774e8..266c478ff` (backend) + 3 commits web (`49414b2`, `239bb1d`, `2c0861e` sur Site-lecayenne, LOCAUX)

## Convergence (§F)
- **Cycle 1 = Cycle 2, arbre gelé `266c478ff`** : PHPUnit 512 tests / 9 domaines = 0 échec ; vitest 2732 verts (0 échec, 3 skip préexistants). ✅
- Diff frozen-zone sur toute la plage = **0 ligne** hors `pos-wizard.js` **+20 sous LOCK** (`plans/LOCK_POSWIZARD_SANS_CRUDITES_2026-08-05.md`). ✅
- Chaîne NF525 : 5409 → 5450 en **ajout seul**, hash progressif. ✅
- Web : abandon-paiement 25/25 + nav-smoke 13/13 + sentinelle 17/17, 0 erreur JS. ✅

## Les 8 axes — preuves
| Axe | État | Preuve |
|---|---|---|
| A1 KDS 6 cartes + scroll | ✅ | Captures 1920/1366 + scrollée LUES (6 cartes lisibles, ◀ ▶, « +7 en attente ») ; 3 sentinelles réécrites FK-KDS-6CARDS-001 ; plafond rendu 24 (perf) |
| A2 Nom client téléphone | ✅ | Cause prouvée = découvrabilité (champ existait) → label VISIBLE « imprimé sur le ticket cuisine » (capture lue) + CTA téléphone plus jamais clippé ≤820px |
| A3 CB + multi-paiement | ✅ | Cause CB = 422 « 4 chiffres » silencieux → note OPTIONNELLE (PosCardDeclarativeNoNoteTest 3/3). Paiement MIXTE à l'encaissement : 20,01 € = 12 CB + 8,01 esp. au centime, rollback atomique, 409 anti-double (CounterCollectSplitPaymentTest 4/4) ; modale « Reste : 4,90 € » capturée |
| A4 Duplications ticket | ✅ | D-1 boisson formule dédupliquée PHP+JS (rouge→vert) ; D-2/D-3 = portions DISTINCTES par verdict G-9 (tests) ; 159 tests Hardware verts |
| A5 Web P0 abandon | ✅ | Garde caisse déjà en place (R1 2026-08-04) + EXPIRATION 60 min (WebUnpaidOrderExpiryTest 4/4) + suivi web honnête « PAIEMENT NON FINALISÉ » + reprise paiement + retour-3DS géré (25/25) |
| A6 Transfert web→caisse | ✅ | 4 suites de parité existantes re-validées vertes (16 tests + 7 vitest parité réelle) — zéro champ perdu détecté |
| A7 En-tête ticket | ✅ | « CUISINE » était DÉJÀ gras/1-ligne (fausse piste évitée) ; les lignes qui se repliaient (bannière, Client, Tel) = gras + UNE ligne garantie, troncature `...` CP858-safe |
| A8 Tacos/crudités/légumes | ✅ | DB réelle : 0 crudité sur catégorie Tacos (migration + sentinelle) ; Poivrons cuits/Maïs/Olives 0,90 € scellés au centime (3/3) + badge borne + total local = scellé ; « Sans crudités » 1 geste borne + caisse (LOCK) |

## Gates owner — à contresigner
- **G-2/G-3 → LOCK_POSWIZARD_SANS_CRUDITES** : appliqué sous votre directive /goal (§10 du LOCK) — contresign formel demandé ; rollback = 1 revert.
- **G-7** : Maïs & Olives fixés à **0,90 €** (lecture de « aussi payante ») — changeable en 1 ligne (`2026_08_05_110000`, `data/menu.js` web à venir).
- **G-4** : captures faites à 1920×1080 + 1366×768 — fournir la vraie résolution écran cuisine si différente (chrono légèrement rogné à 1366).
- **G-8** : **RIEN n'est déployé** (VPS ni Vercel). Les miroirs web data (`data/menu.js` : légumes 0,90 € + sans-crudités exclusif) restent à faire au moment du deploy web.

## Reste honnêtement ouvert
1. **Audit e2e visuel page-par-page du site web complet** (T-5.2, skill test-e2e) — non exécuté dans cette session ; l'abandon-paiement et la nav sont couverts par les 55 checks agent.
2. Miroir `data/menu.js` web pour les 3 légumes payants + chip « Sans crudités » web (wizard-v2) — à faire avec le deploy web.
3. Session concurrente active sur les 2 repos (synchro backend + axes A1/A4 web) — mes commits n'incluent que mes hunks ; coordonner avant deploy.
