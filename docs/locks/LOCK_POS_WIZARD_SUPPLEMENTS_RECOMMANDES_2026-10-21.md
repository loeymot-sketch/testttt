# LOCK — Édition frozen pos-wizard.js / pos-wizard.css (suppléments « recommandés » à la caisse)

**Date :** 2026-10-21
**Fichiers frozen touchés (CLAUDE.md §7) :** `public/js/pos-wizard.js`, `public/css/pos-wizard.css`
**SHA-256 avant :** js `3bf2f9c90c9320bf61d7e8619c535cee3e82acfa6d7f1314273a89ec6258995f` · css `96e06df60076e3d02346f5d1ab4cc7db79fbc5e01c42c175fbd3e840b811e33d`
**SHA-256 après :** voir `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` (même commit que le correctif)

## Pourquoi

Demande du propriétaire (`/goal`, 2026-10-21) : mettre des suppléments « (recommandés) » pour le client, adaptés à la catégorie —
« ça va être adapté sur la catégorie pour toutes les listes de supplément… ça va lever le panier moyen ». La caisse est une de ces listes :
c'est le préparateur qui propose, la mention l'aide à le faire.

## Le changement (affichage seul)

1. Construction de `paidExtras` : un drapeau `recommended` (lu dans `ex.is_recommended`, posé par le serveur d'après
   `config/menu_recommended_supplements.php`) et un tri stable qui met les recommandés en tête.
2. Un helper `recoTag(it)` ajoute « (recommandé) » après le nom dans les 4 grilles de suppléments.
3. CSS : `.wizard-option .option-reco` (petite mention ambrée sous le nom).

Aucun prix, aucun identifiant, aucune sélection ni payload n'est touché : le nom envoyé reste `s.name`, la mention n'est que du rendu.

## Vérification

Playwright caisse (capture lue), Vitest, sentinelle `FrozenZone` après mise à jour de la baseline.

## Autorisation

Demande du propriétaire ci-dessus (« toutes les listes de supplément »).
