# 🔒 LOCK — pos-wizard.js : bloquer « Ajouter au panier » tant que viandes incomplètes (0/2)

**Fichier frozen** : `public/js/pos-wizard.js` (§7 CLAUDE.md — « design parfait » owner-gaté).
**Autorisation owner** : 2026-07-04, l'owner a explicitement choisi « **Bloquer tant que 0/2** » (question directe, réponse enregistrée) suite au finding [C-2] du test-e2e Cowork.

## Problème
Le wizard **single-page** (caisse `/admin/pos-v4`) laisse « Ajouter au panier » actif même si les étapes
requises ne sont pas satisfaites (ex. Tacos L, 2 viandes exigées, badge « 0/2 ») → ajout avec viande par
défaut non voulue. Le wizard **multi-étapes**, lui, bloque déjà via `canProceedFromStep()`.

## Fix (chirurgical, minimal)
Avant `syncAndSubmit()` sur le chemin single-page (bouton `data-action="add-to-cart"` + raccourci
Ctrl+Enter), valider TOUTES les étapes actives via la fonction EXISTANTE `canProceedFromStep()` ; si une
étape échoue → `showValidationError(msg)` + `return` (bloque l'ajout). **Aucune logique nouvelle** : on
réutilise la validation déjà présente (celle du multi-étapes). Zéro impact sur les produits sans exigence
(canProceedFromStep renvoie `canProceed:true`).

## Rollback
`git revert` du commit. Aucune migration, aucun schéma, aucun autre fichier frozen.

## Sécurité
Ajout d'une garde de validation (restreint, jamais permissif) → renforce, ne relâche pas. NF525 non concerné.
