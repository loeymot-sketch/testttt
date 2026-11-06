# MOBILE-EXCELLENCE-PHASE17-CANONICAL-WEBP-ASSETS-20261016

## Mandat

Synchroniser les visuels sandwichs, tacos, galettes et catégories de l’app
mobile Le Cayenne avec les vignettes WebP réellement servies par le site et la
borne. Ne modifier ni les prix ni les règles de commande.

## Source canonique

`public/images/menu/thumbs/` du dépôt web. Ces vignettes sont la résolution
préférée de `App\\Support\\MenuImageThumb` pour les grilles site/borne.

## Résultat attendu

Les neuf assets allowlistés sont copiés dans `mobile/assets/menu/`, puis
`mobile/data/menu.js` et les tests statiques/E2E pointent vers ces WebP. Les
anciens PNG restent présents mais ne sont plus référencés par ces produits.
