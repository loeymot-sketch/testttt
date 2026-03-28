# Visuels « sauces » (borne / POS)

Les fichiers `sauce_*.svg` sont générés par **`scripts/generate-menu-sauce-svgs.php`**
(couleur = type de sauce, pas de photos aléatoires).

Le mapping **nom de variation → fichier** est dans `config/menu_images.php` (`sauces`).
Le modèle **`ItemVariation`** fait une résolution **insensible à la casse** et **aux accents**
pour que `KETCHUP`, `Mayonnaise`, `Samourai`, etc. tombent sur le bon fichier.

Après changement de config : `php artisan config:clear`

Les anciens `sauce_*.png` peuvent rester sur le disque mais ne sont plus référencés.
