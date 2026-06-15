# W8 — Wizard Studio LIFECYCLE (créer + publier/dépublier) — ultra-plan
**Date:** 2026-06-15 · amélioration autonome (réutilise endpoints existants, zéro gate). Raisonnement : le Studio savait seulement ÉDITER un profil publié existant — trou de cycle de vie.

## Raisonnement (pourquoi c'est la bonne amélioration)
1. **Création** : une catégorie SANS wizard → le Studio montre "Aucune page" mais `addPage` no-op (`!profile.id`) → opérateur bloqué. Manque le "démarrer un wizard de zéro".
2. **Publier/Dépublier** : le Studio édite des profils **publiés en direct** (concern WS-3). La bonne réponse = un vrai workflow **brouillon → publier** : travailler en brouillon (clients ne voient rien), publier quand prêt, dépublier pour retirer. Les endpoints existent déjà.

## Contrat vérifié (réutilisé)
- Créer : `POST admin/composer/categories/{id}/profile` (storeForCategory, catalog.compose, non-gaté) → profil brouillon (is_published=false).
- Publier : `POST admin/composer/profiles/{id}/publish` (permission catalog.publish — admin l'a) → `assertPublishable` (rejette : 0 page active / max<min / page requise sans choix → 422).
- Dépublier : `POST admin/composer/profiles/{id}/unpublish` (category-OK).
- NF525 neutre, aucun prix.

## Implémentation (WizardStudioComponent.vue, NON-FROZEN)
1. computed `createProfileEndpoint` (category/item) + `hasProfile`.
2. Body : si catégorie + pas de profil + pas d'erreur → CTA "Créer un wizard pour cette catégorie" → `createStudioProfile()` (POST endpoint {template:'custom', branch_id_scope:null, steps:[]}) → set profile + fetchSources + fetchPreview.
3. Header : bouton **Publier / Dépublier** à côté du badge. `togglePublish()` : publié→unpublish ; brouillon→publish (gère 422 `assertPublishable` → message clair "Impossible de publier : page sans option ou page requise vide") → maj profile + reloadPreview.
4. État brouillon : badge "Brouillon", pas de bannière "édition en direct" (déjà conditionnée `isPublished`), preview marche (is_published forcé côté réponse). ⇒ workflow brouillon→publier qui résout proprement le concern WS-3.

## Test-e2e (abuse en boucle)
- Vitest : createStudioProfile POST + set profile ; togglePublish publish/unpublish + 422 surfacé.
- PHPUnit : déjà couvert (storeForCategory/publish/unpublish existants).
- Live : catégorie sans wizard → créer → ajouter page → publier (assertPublishable) → dépublier → restaurer le clone. Boucle adversaire jusqu'à P0+P1=0.
</content>
