# LOCK — Refonte UI étape VIANDE du wizard caisse (frozen pos-wizard.js/.css)

**Date** : 2026-07-23
**Gate owner** : ✅ EXPLICITE — owner (message 2026-07-23) : « améliorer le WIZARD de la caisse [...] les viandes sont trop petits [...] je veux les remplacer par le maximum espace par chaque image et le petit texte [...] ils sont en parallèle avec les crus, [...] qu'ils prennent le même espace dans son carré [...] si je clique [...] que ça me sorte une liste que je la vois tout, je ne scrolle pas ». + « corrige le prix affiché [...] frites seule / boisson seule 1,90 €, menu 2,50 € ».

## Fichiers frozen touchés (§7)
- `public/js/pos-wizard.js` — `renderViandeStep` (+ portion viande de `renderViandeSauceStep`) : passage de **lignes emoji** (`wizard-viande-row` : emoji + nom + compteur) à une **grille de tuiles carrées avec grande IMAGE** (`renderOptionIcon(viande.thumb, viande.emoji)`) + petit nom, toutes visibles (suppression du « Voir tous » qui masquait des viandes → l'owner veut tout voir sans scroll). Contrat handler INCHANGÉ : les boutons gardent la classe `.viande-btn` + `data-viande` + `data-action="plus"/"minus"` (bindEvents `pos-wizard.js:5564` inchangé). Correctif prix fallback formule `2.00 → 1.90` (frites/boisson seule), menu `3.00 → 2.50` (lignes ~871-873 / ~966-968) pour cohérence si l'addon DB est absent.
- `public/css/pos-wizard.css` — nouvelles règles `.wizard-viande-grid` / `.wizard-viande-tile` (carré, grande image ~72-88px, nom compact), même gabarit que les crudités mais images plus grandes.

## Pourquoi le gate est satisfait
- Demande **explicite et détaillée** de l'owner de changer précisément cette UI + ces prix. C'est le cas d'usage du gate §10 « frozen-zone touch needed » = décision humaine fournie.
- Scope **strictement limité** à l'étape viande (layout) + fallback prix formule. Aucun changement de logique de sélection/pricing/scellé (le handler `.viande-btn` plus/minus est réutilisé tel quel ; le prix réel vient du SSOT backend, cf. migration `2026_07_23_160000_set_frites_boisson_seule_price_190`).
- Prix : la vraie source (SSOT) est corrigée en DONNÉE (migration items 2/3 = 1,90 €, non-frozen). Le fallback frozen n'est qu'un filet de sécurité aligné.

## Preuves attendues (avant merge)
- Rebuild Mix OK + bundle servi contient la grille viande.
- Vitest wizard POS existants restent verts (harness exécute la vraie logique buildSteps/render de pos-wizard.js) + nouveau test rendu grille viande.
- Capture visuelle AFTER de l'étape viande (grandes images en grille, tout visible).
- Prix affiché formule frites/boisson = 1,90 €, menu = 2,50 € == scellé backend.
- Frozen diff limité à pos-wizard.js/.css ; NF525 chaîne OK ; pricing/scellé inchangés.

## Réversibilité
`git checkout <ref> -- public/js/pos-wizard.js public/css/pos-wizard.css` + rebuild. Migration prix `php artisan migrate:rollback --path=...`.
