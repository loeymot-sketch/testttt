# REVIEW DE STRUCTURE — superviseur, 2026-06-10 (post 4 GOALS du jour)

## 1. Topologie réelle (vérifiée git, à l'instant)
| Ligne | HEAD | Contenu clé | Dans release/v1 ? |
|---|---|---|---|
| `release/v1-2026-06-10` (worktree dédié, **session ACTIVE**) | `36f122ca6` | spine + **printer-saga** + ses propres commits composer (T-COMPO-3/4/6, ultraplan UI/UX 52 tâches, W-V heals visuels CMS) | — |
| `heal/pre-cloud-exec-2026-06-05` (spine) | `059c20db7` | validation profonde 100% + CLIENTS plan + verdict superviseur | ✅ |
| `feat/pos-printer-saga-autoprint` | `b27365295` | impression SAGA NF525 | ✅ |
| `heal/mobile-update-2026-06-10` | — | **CLIENTS mobile convergé** (3 lignes intégrées, adversaire EXHAUSTED, 20/20×2) | ❌ **MANQUANT** |
| `goal/cms-gestion-2026-06-10-spine` (cette session) | `8186db45d` | CMS gestion + polish + **GATE-W6 LOCK pos-wizard render générique PROUVÉ LIVE** (37+ commits, superset de spine@7ebb1f252) | ❌ **MANQUANT** |
| Web `/Downloads/web` | `main=cc59bbe` | CLIENTS web convergé (a11y authentifié healé, 18/18) — main = canonique ✅ | n/a (repo séparé) |
| OVH prod | `origin/production=8579f7eae` | **204 commits derrière** — rien du jour déployé | ❌ |

## 2. ⚠️ COLLISION IMMINENTE (le finding n°1 de cette review)
La session release a commité `T-COMPO-6` : sentinel verrouillant « GAP#1 : generic_choices SANS branche renderStepContent → rend VIDE caisse, **gated T-COMPO-2 frozen** ». Or **T-COMPO-2 = exactement le renderer générique POS que CETTE session a déjà livré** sous `LOCK_POS_WIZARD_GENERIC_RENDER_2026-06-10.md` (prouvé live : sections builder, total 8.50→11.50, gate min, panier, flag OFF intact ; + heals RED). Si l'agent release implémente T-COMPO-2 sans merger `goal/cms-gestion-2026-06-10-spine` d'abord → **2 patchs frozen divergents sur le même fichier `pos-wizard.js`** = pire scénario de fragmentation.
**Directive superviseur** : l'agent wizard/release DOIT `git merge goal/cms-gestion-2026-06-10-spine` AVANT T-COMPO-2 (ma branche est superset de spine@7ebb1f252 ; la release ayant avancé, merge normal, conflits attendus minimes : PROJECT_BRAIN §2 = union, éventuels chevauchements composer additifs). Son sentinel T-COMPO-6 devrait passer de « gap dormant » à VERT après merge (la branche dispatch existe).

## 3. État par système (résumé supervisor)
- **Backend/caisse/borne/KDS** : convergés et re-validés plusieurs fois (3092/0) MAIS non déployés ; fragmentés sur 4 lignes → la release/v1 est LA bonne réponse en cours ; il lui manque 2 des 4 lignes.
- **Mobile app** : convergée aujourd'hui (CLIENTS) — adversaire épuisé, SSOT 41+4 aligné, earn 1pt/€, palette OK. Gates restants : redeem divergent mobile↔web, MADV-2 P3, distribution (aucun packaging), push.
- **Site web** : convergé aujourd'hui — main canonique, axe 0 y compris espace authentifié. Gates : GATE-PUBLISH-1 (29 placeholders LCEN owner-data), hébergement/publication absents, push.
- **Data** : incident ouvert — DB locale `foodking` re-seedée vers un catalogue ÉTRANGER 63 items (NF525 non prouvable localement) ; divergence nommage/prix DB:8766 vs miroirs clients owner-locked (Tacos 8,50/Big 11,50 vs M 6,90/L 8,90) = à trancher owner.
- **Worktrees** : 24+, dont 5 lanes mergées supprimables (refus classifier antérieur — à faire par l'owner ou à ignorer).

## 4. Recommandation d'ordre (le « meilleur à faire ensuite »)
1. **P-0 (release session)** : merger `goal/cms-gestion-2026-06-10-spine` + `heal/mobile-update-2026-06-10` dans `release/v1` → première branche VRAIMENT superset/shippable. (Sans ça, tout le reste re-fragmente.)
2. **Clients (cette mission)** : exécuter `plans/GOAL_CLIENTS_NEXT_BEST_2026-06-10.md` (redeem unifié, LCEN workflow, distribution PWA + hébergement web, wireup-prep V2 gated).
3. **Owner gates groupés en UNE session de décisions** : G-5/G-4 flip flags wizard · redeem paliers · LCEN data · DB locale à restaurer · PUSH + déploiement OVH (release).
