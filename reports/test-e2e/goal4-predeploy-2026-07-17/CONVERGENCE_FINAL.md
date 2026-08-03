# CONVERGENCE — test-e2e goal4-predeploy-2026-07-17

**Verdict : CONVERGÉ — P0+P1 = 0 sur les 3 vagues, findings P2/P3 stables (set-equality R1→R2→R3), prêt deploy.**

## Cycles

| Round | Wave A (borne) | Wave B (caisse) | Wave C (cœur) |
|---|---|---|---|
| R1 captures | 4/4 PASS (10 états quartet) | 2/2 PASS (8 états) | 4/4 PASS (6 états) |
| R1 adversaires | RED — P0=1 (A-001), P1=3 (A-002/003/004) | RED — P1=1 (B-001) | RED — P1=2 (C-001/004) |
| Fix wave R1 | blur transition retiré (A-003) | APPLIQUER min-w (B-001) + bridge tabindex (B-006) | catch-all assets → 404 réel + test (C-001) ; media orpheline (C-002) |
| R2 sérialisé | 4/4 PASS 2,7 min | 2/2 PASS | 4/4 PASS |
| R2 adversaires | A-001 closed (artefact multi-suites réfuté) ; A-003 PASS ; A-004 downgrade P2 frozen ; **A-002 FAIL root-causé** (branches[0]=Faker 9) | **GREEN** (B-001/B-006 PASS pixel/DOM) | **GREEN** (C-001 PASS 43/43 assets, C-002 PASS) + C2-101 résidu casse |
| Fix wave R2 | branch index id ASC (`97b793a2c`) — broadcasting/auth private-branch.1 = 200 | — | regex (?i) (`1a7818923`) |
| R3 sérialisé | 4/4 PASS | 2/2 PASS | 4/4 PASS |
| R3 adversaire (clôture) | **A-002 CLOSED preuve 6 maillons** (0×403, 4×200 auth, channel structurellement branch.1, contre-test branch.9=403, ordre [1,7,8,9] live, 0 frozen touché) → **GREEN**, 0 nouveauté | (R2 GREEN + spec R3 vert = 2 cycles propres) | (idem) |
| R4 confirmation | spec re-run + delta réseau (aucune classe ≥400 nouvelle) | — | — |

## P1+ corrigés pendant le run (commits)
- `C-001` catch-all SPA masquait les assets manquants en 200 → 404 franc + test (+ `(?i)` C2-101). Classe de l'incident « page blanche paiement » 2026-07-07 éliminée.
- `A-002` la borne s'abonnait à la branche Faker 9 (ordre DESC) → push temps-réel 86 mort → ordre id ASC frontend, auth 200 prouvée.
- `A-003` blur(4px) plein pane figé sur les grilles borne → fondu opacité seul (aussi un gain GPU borne).
- `B-001` bouton remise « APPLIQUER » rogné → min-w-[72px].
- `B-006` bridge aria-hidden focusable → tabindex=-1.
- `C-002` media orpheline (avatar cassé) → purgée, fallback propre.

## Ouverts P2/P3 (disclosed — non bloquants, stables 3 rounds)
Gated frozen : contraste turquoise wizard caisse (#43C6AC 2,12:1), aria wizard caisse (0 aria-pressed), format « €4.90 » US, A-004 preview 422 auto-récupéré (~950 ms), A-011 rafale 401 au boot auto-guérie (<1 s). Layout : pill « À encaisser » sur « Commande rapide », « 1× Mayonnaise » hors champ ticket 900px, « Téléphone (optionne » clippé. Data/dev : files POS périmées (orders 07-06 dev, janitor = gate owner NF525), double-chemin « Viande supplémentaire » bols (cumul 2×2,50 possible — analyser le véhicule de facturation avant fix data), branches Faker 7/8/9 en dev, base URL 8766 compilée dev (`MIX_HOST`) + CSP report-only sans 8766 (166→250 csp-report/run ; le deploy rebuild sur VPS avec SON .env — non embarqué). Divers : « Menu Enf… » sans title, descriptions tronquées en dur, « 1 articles », €7,00 vs 7,00 €, glyphe « Dupliquer » vide (lab-copy inexistant), OSS empty « — », aria edit/xmark manquants, « Crudite » sans accent, A-012 (spec : désactiver les animations aux captures).

## Gates backend (mêmes runs)
PHPUnit ciblé 36 tests / 107 assertions verts (sentinelles NF525 incluses) · chaîne fiscale OK ×4 branches · frozen zones diff = 0 · bundles rebuild verts ×3.
