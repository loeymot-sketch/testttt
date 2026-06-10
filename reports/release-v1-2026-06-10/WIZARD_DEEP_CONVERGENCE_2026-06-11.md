# WIZARD BUILDER — Validation PROFONDE RÉELLE convergée (Phase A + B) 2026-06-11

> Owner : « prends ma place de gérant, prouve RÉELLEMENT (pas "oui c'est fait") que composer/modifier/supprimer/ajouter page+choix marche, listes enregistrées VRAIES, accessible et faisable + dispute adversaire. »

## PHASE A — preuve réelle siège-gérant (live :8768 + DB + code)
- **Listes enregistrées VRAIES** (scopées item, 3 types) : Big Classique → Viande 1/Viande 2/Sauce + crudite/supplement + 3 addons ; Petite Frites → Style frites seul. Jamais du faux.
- **Composer/modifier/persister** : page « Choix de la viande » → Viande 1 → choix unique → save → **reload = persiste** (DB step item_attribute:1, FK source_item_attribute_id=1). Panneau « 4 vrais choix Inclus » + « Prix… jamais sur la page wizard » (NF525 in-UI).
- **Custom page (ajouter/supprimer choix)** : code-vérifié → vraies ItemExtra (prix dans SSOT catalogue), étape extra_group price-free, idempotent, guard collision, re-edit PK-bound.
- État vide pédagogique, publish vert AA. 4 captures analysées (`visual/wizard-deep/`).

## PHASE B — dispute adversaire profonde (7 clusters live + vérif croisée)
**Verdict : le builder EST vraiment la meilleure méthode, accessible et faisable — 6/7 clusters 0 P0/P1.**
- compose-add-modify : 0 blocker. delete-page/wizard : 0 blocker. publish-diff-version : 0 blocker (le « redirect dashboard » observé = drop de session, PAS un bug). a11y-feasibility : 0 blocker (gérant non-technique peut tout faire). **client-render-e2e : 0 blocker — la borne rend RÉELLEMENT le wizard composé** (le climax).
- reorder-template : 3 « P1 » **RÉFUTÉS** par vérif croisée (la projection client choisit le bon profil par [branch,version,id] ; le binding W7 lie les vrais constructs ; pas de catch-all). Restes = P2 hygiène (profils orphelins, pas de toast « wizard existe »).

## LE bug réel trouvé + HEALÉ : CPC-01 (P1, perte de données)
- **Exploit** (reproduit bout-en-bout par l'adversaire, 9/9 déterministe) : un gérant nomme une page perso « Supplément » (accent) → le guard `mb_strtolower` (accent-SENSIBLE) laisse passer → la suppression `where('group_label')` (collation DB accent-INSENSIBLE) **soft-delete les 9 vraies options « supplement »** du catalogue (disparaissent de POS+borne).
- **Heal défense-en-profondeur** : (1) guard plie les accents (`Str::ascii` = superset utf8mb4_unicode_ci) → « Supplément/SUPPLÉMENT/supplément » bloqués 422, pluriel « Suppléments » distinct autorisé ; (2) suppression byte-exact PHP → ne traverse jamais un autre groupe (protège aussi données legacy).
- **Preuves** : test régression HTTP vert (21/21 personal-page), composer 198 passed, frozen 0, **+ preuve sur la vraie donnée MySQL e2e** (9 options BEFORE → guard bloque → 9 AFTER intactes ; MySQL confirme `Supplément=supplement`).

## Convergence
Wizard builder = **validé en profondeur, RÉELLEMENT** : listes vraies, persistance prouvée, custom-page→SSOT NF525, rendu client borne OK, accessible/faisable, 1 vrai bug trouvé+fermé. C'est la bonne méthode. RESTE (P2 hygiène, non bloquant) : toast « wizard déjà existant » au template, durcir publish-si-vide. Prochaine étape goal : Phase C (frozen wizard autorisé) puis Vagues avec audit en boucle.
