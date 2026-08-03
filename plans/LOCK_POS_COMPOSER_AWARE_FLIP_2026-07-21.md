# LOCK — Activation flag composer-aware POS (frozen behavior change)

**Date** : 2026-07-21
**Gate owner** : ✅ EXPLICITE — owner « oui » à la question « Flip flag composer en caisse
(`FK_POS_WIZARD_COMPOSER_AWARE_ENABLED`) — ⚠️ touche pos-wizard.js frozen » (message 2026-07-21).

## Quoi
Activer `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true` (config `catalog_v15.pos_wizard_composer_aware.enabled`).
Effet : `public/js/pos-wizard.js` (FROZEN §7) construit les pages du wizard caisse depuis le **profil
composer publié** (`item.composer_profile.steps`, SSOT partagé borne/web) au lieu de la heuristique legacy
`buildSteps(data)`. Résout le « doublage POS » (la caisse pouvait diverger du composer SSOT).

## Pourquoi ce n'est PAS une édition frozen
- **Aucun octet de `pos-wizard.js` modifié** (diff frozen = 0). Le chemin composer-aware EXISTE DÉJÀ dans le
  fichier (`pos-wizard.js:619`, `[T-WC-POS-RUNTIME-01]`), dormant derrière le flag.
- Activation = **env flip** (mécanisme de rollout prévu, cf. commentaire `config/catalog_v15.php:100-104`
  « production-safe rollout via env flip »). **Réversible** en 1 ligne .env + `config:cache`.

## Preuves (triple-vert)
- Vitest composer-aware : `posWizardComposerAware.spec.js` **9/9** (harness exécute la VRAIE logique
  buildSteps-from-composer de pos-wizard.js).
- Vitest POS wizard total : **35/35** (6 specs) avec flag ON local.
- Backend : `FritesWizardComposerTest` + `ComposerProfileProjection` **7/7 (20 assert)**.
- SSOT composer déjà **prouvé en production** (borne + web le consomment via forKiosk/web).
- ⚠️ Smoke visuel POS caisse automatisé **NON abouti** (login SPA `/login` = sélecteurs dynamiques, infra) —
  runtime couvert par les 9 tests harness (exécutent la VRAIE logique buildSteps-from-composer de pos-wizard.js).
  **Owner à confirmer visuellement** la caisse (rendu inchangé, cf. ci-dessous) ; **rollback en secondes** sinon.

## Portée / réversibilité
- V1 LOCAL mono-poste : flag posé côté serveur (`.env` VPS + local). Rollback = retirer la ligne + `config:cache`.
- Rendu inchangé : le composer-aware ne change QUE la SOURCE des étapes ; le code de RENDU pos-wizard.js
  (non modifié) les affiche pareil → risque visuel faible.
