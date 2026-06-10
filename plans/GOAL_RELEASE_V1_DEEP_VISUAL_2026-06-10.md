# GOAL — Validation profonde MAX DISCIPLINE de release/v1 : technique + UI/UX + parcours réels + captures + e2e boucle + adversaires (2026-06-10)

> Owner /goal : max discipline & perfection, validation profonde technique ET UI/UX, parcours réels validés avec captures d'écran, test-e2e en boucle, agents adversaires ; planification profonde puis lancer le plan.
> Cible : arbre INTÉGRÉ `release/v1-2026-06-10` (superset prouvé : spine+printer+cms+fix ; PHPUnit 3125/0, Vitest 2111/0, live e2e 2 cycles déjà verts). Servi :8768/foodking_e2e. Frozen §7 = 0. 0 push.
> Principe : ne PAS re-refaire la validation-profonde déjà convergée sur la spine ; VALIDER VISUELLEMENT LE DELTA intégré + un parcours réel unifié, avec captures analysées (Read) + adversaires, 2 cycles.

## Acquis (déjà prouvé sur l'arbre intégré)
- Technique : PHPUnit 3125/0, Vitest 2111/0, build prod OK, frozen 0.
- E2E render : central sweep 8/8 + sync fraîche kiosk→KDS→OSS, 2 cycles identiques (:8768).
- Adversaire release : EXHAUSTED (0 P0/P1/P2).

## DELTA à valider VISUELLEMENT (captures analysées + adversaire) — le cœur de ce goal

### W-V1 — CMS GESTION (surfaces neuves de la ligne cms, jamais capturées sur arbre unifié)
- Catalogue/Studio (`/admin/items` CatalogStudioComponent) : arbre stock, presets, prix builder.
- Hiérarchie catégories : create avec **sélecteur parent** (ItemCategoryCreate), liste, show parent_id.
- Composer builder : presets+prix par option, **bouton supprimer wizard** (W5b, 409 si publié).
- Captures chaque écran/état + analyse (FR, palette Cayenne, labels non bruts, états vide/erreur). Vérif technique : routes répondent, 0 console err.

### W-V2 — IMPRESSION REÇU (surface neuve printer-saga)
- Reçu POS modal (ReceiptComponent) : mentions NF525 (Opérateur, Désignation, TVA par taux, SIRET/TVA-intra), duplicata, netting TVA remise. Capture + analyse.
- Backend déjà prouvé (62 tests) ; ici = preuve VISUELLE du rendu reçu.

### W-V3 — PARCOURS RÉEL UNIFIÉ bout-en-bout (capture de TOUT le parcours)
- Kiosk : idle → catégorie → wizard composé → panier → paiement comptoir → confirmation (captures chaque écran).
- Bascule KDS : la commande apparaît → Démarrer → Prêt (captures).
- OSS : colonne Prêt (capture).
- Encaissement caisse : la commande → collect → reçu (captures).
- = preuve qu'un VRAI parcours client+staff traverse l'arbre intégré, capturé écran par écran.

## Dispositif (max discipline)
- Captures via Playwright (Chrome système, JPEG q70 — disque ~1Gi). Chaque capture LUE+analysée (Read, mandat §6).
- Adversaire par vague (read-only, refute file:line/capture+zone) → heal loop (non-frozen, scope-minimal) → 2 cycles identiques.
- Mutations sur :8768/foodking_e2e clone uniquement. Frozen observe-only.
- Convergence = 0 P0/P1 + 2 cycles identiques + adversaire épuisé + captures 100% parcours analysées.

## Reste hors-scope (gates owner, inchangé) : DATA-1 (DB fiscale propre), PUSH-1 (mise en ligne), merge mobile-update.
