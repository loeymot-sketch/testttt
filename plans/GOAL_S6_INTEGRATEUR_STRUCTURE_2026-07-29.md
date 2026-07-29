# GOAL S6 — INTÉGRATEUR & STRUCTURE DUPLICABLE (2026-07-29)

> Tu es le LEAD INTÉGRATION — le « chef des chefs ». Lis
> `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md` D'ABORD. Double mission owner :
> (A) pendant que S1-S5 travaillent : intégrer, arbitrer, prouver le TOUT-ENSEMBLE ;
> (B) structurer le projet pour être DUPLICABLE à d'autres restaurants (« ici va
> être notre image… une bonne structure claire, zéro doublon »).
> ⚠️ Lance-toi de préférence EN DÉCALÉ (après 1-2 h de S1-S5) ou en mode lecture
> d'abord — tu es le seul autorisé à lire PARTOUT, mais tu n'écris QUE dans tes chemins.

## Ownership (tes chemins d'écriture)
- `docs/**`, `plans/**` (dont `plans/handoffs/**` — tu les TRAITES : dispatch,
  arbitrage, fusion), `reports/goal-s6-integration/**`
- `tests/e2e/cross-*` (nouveaux e2e cross-systèmes), `tests/Feature/CrossSystem*`
- `PROJECT_BRAIN.md` (§2 consolidation), `SYSTEM_MAP.md`, `SYNC_CONTRACT.md` (màj)
- Fixes de code : UNIQUEMENT si un conflit inter-sessions casse la branche
  (build rouge) — scope minimal, préfixe [S6-ARBITRAGE], sinon → handoff au lead.

## Vagues
### V1 — Tour de contrôle (en continu, toutes les ~45 min)
- `git log --oneline -15` + build vert ? (`npm run production` + suites rapides).
  Branche cassée par un croisement → arbitre (rebase/fix minimal) SANS refaire
  le travail des leads.
- Lire les `PROGRESS.md` des 5 sessions + traiter `plans/handoffs/*` (router,
  fusionner les demandes contradictoires, trancher — tu DÉCIDES).
- Tenir `reports/goal-s6-integration/TABLEAU_DE_BORD.md` (état par session,
  risques croisés, décisions rendues).

### V2 — E2E CROSS-SYSTÈMES (la preuve du tout-ensemble)
Écris et fais tourner EN BOUCLE les parcours qui traversent TOUT :
1. Web (www.lecayenne.fr, commande réelle modérée §5) → caisse accepte → KDS
   prépare → prête → suivi web l'affiche → encaissement → stock décrémenté → NF525.
2. Borne → KDS → OSS → encaissement caisse.
3. Caisse directe + annulation → stock reverse + fidélité intacte.
4. 86 posé caisse → borne ET web grisés <25 s → restock → réapparition.
Chaque parcours chronométré, capturé aux étapes clés, vérifié en DB au centime.
Acceptance : les 4 parcours verts ×3 exécutions espacées (stabilité, pas de flake).

### V3 — STRUCTURE DUPLICABLE (mandat « autres restaurants »)
Audit à froid : qu'est-ce qui est HARDCODÉ Le Cayenne ? (grep « Cayenne »,
« Hénin », coordonnées, 437 rue, téléphone, couleurs, branch_id=1, prix, textes).
Produire `docs/DUPLICATION_PLAYBOOK.md` : pour ouvrir « Resto X » — la liste
EXACTE (config/env/seeders/DB/assets/domaines/comptes) de ce qui se change, ce
qui se garde, ce qui se génère. Classer chaque hardcode : OK-config / à-extraire
(finding P2 avec chemin d'extraction proposé, SANS tout refactorer maintenant —
V1 reste mono-resto, CONSTITUTION). Vérifier BranchScope coverage (sentinel).
Acceptance : playbook relu par un agent « nouveau resto » qui liste ce qui le
bloquerait — zéro inconnue restante.

### V4 — Anti-doublon global (mandat owner)
Sweep transversal : logique dupliquée entre borne/caisse/web/KDS (prix, dispo,
règles menu, textes, formats ticket), numéros de commande (génération unique
serveur prouvée sous course), events doubles (avec la carte S3). Chaque doublon :
consolider via le lead concerné (handoff) ou [S6-ARBITRAGE] si trivial.
Acceptance : registre doublons → 0 ouvert ou routé.

### V5 — Convergence FINALE du programme
Quand S1-S5 annoncent leur convergence : full-suite globale (PHPUnit large +
vitest full + frozen 0 + chaîne + nav-smoke + cross-e2e V2 ×2 cycles propres) →
tag `v1.1-perfection-<date>` + rapport final owner (1 page : preuves, chiffres,
reste éventuel) + BRAIN §2 + memory.

## Pouvoirs spéciaux & limites
Tu peux TOUT lire, arbitrer les handoffs, trancher les conflits — tu ne
réécris PAS le travail d'un lead dans ses chemins (handoff), sauf branche cassée.
Frozen/NF525/secrets : mêmes gates que tout le monde (§7 discipline).
