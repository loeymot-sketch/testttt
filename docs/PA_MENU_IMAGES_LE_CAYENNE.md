# Menu Le Cayenne — sourcing images pour le PA

Document de référence pour sourcer les visuels (catégories, produits, sauces, suppléments, viandes, crudités).  
**Sources dans le dépôt :** `config/menu.php` (menu), `config/menu_images.php` (fichiers cibles).

**Dossier d’upload :** `public/images/menu/`  
**Attention :** en production, les libellés en base peuvent différer si l’admin a été modifié — recouper avec l’admin FoodKing si besoin.

---

## Convention de nommage

| Type | Règle |
|------|--------|
| **Catégorie** | Slug catégorie (ex. `nos-tacos`) ; fichier indiqué dans `menu_images.php` → `categories`. |
| **Produit** | Clé = slug type Laravel du nom FR (`tacos-m-1-viande`, etc.) ; fichier = valeur dans `menu_images.php` → `items`. |
| **Sauce** | Clé = libellé affiché (ex. `Algérienne`) ; fichiers `sauce_*.svg`. |
| **Supplément (wizard)** | Clés du type `Supplément Cheddar` dans `menu_images.php` → `supplements`. |
| **Crudités (combos)** | Libellés longs dans `menu_images.php` → `crudites`. |

---

## 1. Catégories

| Nom affichage | Slug (`menu.php`) | Fichier image (`menu_images.php`) |
|---------------|-------------------|-----------------------------------|
| Nos Tacos | `nos-tacos` | `tacos.png` |
| Nos Sandwichs | `nos-sandwichs` | `sandwich_terminator.png` |
| Sandwich froid | `sandwich-froid` | `sandwich_terminator.png` *(sidebar borne — en bas de liste)* |
| Nos Burgers | `nos-burgers` | `cheeseburger.png` |
| Nos Assiettes | `nos-assiettes` | `assiette_poulet.png` |
| Ojja | `ojja` | `ojja.png` |
| Omelettes | `omelettes` | `omelette.png` |
| Nos Salades | `nos-salades` | `salade_cesar.png` |
| Chicken & Tenders | `chicken-tenders` | `chicken_wings.png` |
| Nos Menus Enfants | `nos-menus-enfants` | *(non listé dans `categories` — à créer)* |
| Frites & Accompagnements | `frites-accompagnements` | `frites.png` |
| Nos Desserts | `nos-desserts` | `tiramisu.png` |
| Nos Boissons | `nos-boissons` | `coca_cola.png` |
| Suppléments | `supplements` | *(non listé — à créer)* |

---

## 2. Sauces

| Nom à afficher | Fichier |
|----------------|---------|
| Ketchup | `sauce_ketchup.svg` |
| Mayonnaise | `sauce_mayo.svg` |
| Algérienne | `sauce_algerienne.svg` |
| Curry | `sauce_curry.svg` |
| Andalouse | `sauce_andalouse.svg` |
| Burger | `sauce_burger.svg` |
| Samouraï | `sauce_samourai.svg` |
| Barbecue | `sauce_barbecue.svg` |
| Cocktail | `sauce_cocktail.svg` |
| Américaine | `sauce_americaine.svg` |
| Hannibal | `sauce_hannibal.svg` |
| Harissa | `sauce_harissa.svg` |
| Blanche | `sauce_blanche.svg` |
| Poivre | `sauce_poivre.svg` |
| Sans Sauce | `sauce_sans.svg` |

**Alias déjà mappés dans le code** (même fichier) : `Mayonnaise` → mayo ; `Big Burger` / `Biggy` → burger ; `Samourai` (sans ï) → samouraï ; `BBQ` → `sauce_bbq.svg` ; `Fish` → `sauce_fish.svg` ; `Sauce César` / `Sauce Cesar` → `sauce_cesar.svg`.

---

## 3. Viandes (wizard)

| Nom (`config/menu.php`) | Fichier (`menu_images.php` → `viandes`) |
|-------------------------|----------------------------------------|
| Merguez | `viande_merguez.png` |
| Kefta | *(non listé — à sourcer ou aligner avec kebab)* |
| Mexicain | *(à sourcer)* |
| Cordon Bleu | `viande_cordon.png` |
| Viande Hachée | `viande_hachee.png` |
| Nuggets | `viande_nuggets.png` |
| Escalope de poulet | `viande_poulet.png` |
| Tenders | `viande_tenders.png` |
| Fricandelle | *(à sourcer)* |

*(Le mapping prévoit aussi `Kebab`, `Poulet` si l’admin les utilise.)*

---

## 4. Crudités

### Options atomiques (`menu.php`)

| Nom | Idée visuel |
|-----|-------------|
| Salade | laitue / mélange vert |
| Tomate | tomate tranchée |
| Oignon | oignon rouge / blanc |

### Combos (libellés longs, `menu_images.php`)

| Libellé | Fichier |
|---------|---------|
| Complet (Salade, Tomate, Oignon) | `crudites_complet.png` |
| Sans Oignon | `crudites_sans_oignon.png` |
| Sans Tomate | `crudites_sans_tomate.png` |
| Sans Salade | `crudites_sans_salade.png` |
| Aucune Crudité | `crudites_aucune.png` |

---

## 5. Suppléments (options wizard, `menu.php`)

| Nom | Fichier proche (`menu_images.php`) |
|-----|-------------------------------------|
| Jambon de dinde | `supplement_jambon.png` |
| Boursin | `supplement_boursin.png` |
| Fromage a raclette | `supplement_raclette.png` |
| Œuf | `supplement_oeuf.png` |
| Fromage | `supplement_cheddar.png` (ou générique fromage) |
| Galette pommes de terre | *(à sourcer)* |

**Prix sauce supplément (config) :** `supplement_sauce_price` = 0,50 €.

### Lignes dédiées dans `menu_images.php` → `supplements`

| Libellé clé | Fichier |
|-------------|---------|
| Supplément Cheddar | `supplement_cheddar.png` |
| Supplément Jambon | `supplement_jambon.png` |
| Supplément Poulet | `supplement_poulet.png` |
| Supplément Kebab | `supplement_kebab.png` |
| Supplément Viande Hachée | `supplement_viande.png` |
| Supplément Œuf | `supplement_oeuf.png` |
| Supplément Raclette | `supplement_raclette.png` |
| Supplément Boursin | `supplement_boursin.png` |
| Supplément Chèvre | `supplement_chevre.png` |

---

## 6. Catégorie « Suppléments » (articles séparés)

| Produit (`menu.php`) | Slug fichier suggéré | Visuel |
|----------------------|----------------------|--------|
| Sauce supplémentaire | `sauce-supplementaire` | pot de sauce |
| Fromage supplémentaire | `fromage-supplementaire` | fromage / cheddar |
| Jambon de dinde | `jambon-de-dinde` | tranches |
| Boursin | `boursin` | portion |
| Fromage à raclette | `fromage-a-raclette` | raclette |
| Œuf | `oeuf` | œuf |
| Galette pommes de terre | `galette-pommes-de-terre` | galette |
| Salade verte | `salade-verte` | salade |

---

## 7. Addons menu (upsell)

| Nom | Fichier |
|-----|---------|
| Menu (Frites + Boisson) | `menu_complet.png` |
| Frites Seules | `frites.png` |
| Boisson Seule | `boisson.png` |

---

## 8. Produits par catégorie — slug → fichier

### Nos Tacos (`nos-tacos`)

| Nom produit | Clé slug | Fichier |
|-------------|----------|---------|
| Tacos M (1 Viande) | `tacos-m-1-viande` | `tacos.png` |
| Tacos L (2 Viandes) | `tacos-l-2-viandes` | `tacos.png` |
| Tacos XL (3 Viandes) | `tacos-xl-3-viandes` | `tacos.png` |
| Tacos XXL (4 Viandes) | `tacos-xxl-4-viandes` | `tacos.png` |

### Nos Sandwichs (`nos-sandwichs`)

| Nom (`menu.php`) | Clé `menu_images` | Fichier |
|------------------|-------------------|---------|
| Le Méga | `le-mega-2-viandes` | `sandwich_mega.png` |
| Le Terminator | `le-terminator-2-viandes` | `sandwich_terminator.png` |
| Le Suprême | `le-supreme-1-viande` | `sandwich_supreme.png` |
| Le Cayenne | `le-cayenne-1-viande` | `sandwich_cayenne.png` |
| Sandwich Classique (Pain) | `sandwich-classique-pain` *(suggéré)* | |
| Sandwich Classique (Galette) | `sandwich-classique-galette` *(suggéré)* | |
| Panini | `panini-1-viande` | `panini.png` |
| Sandwich Froid | `sandwich-froid` *(suggéré)* | |

### Nos Burgers (`nos-burgers`)

| Nom | Clé | Fichier |
|-----|-----|---------|
| Chicken Burger | `chicken-burger` | `chicken_burger.png` |
| Cheese Burger | `cheese-burger` | `cheeseburger.png` |
| Fish Burger | `fish-burger` | `fish_burger.png` |
| Double Cheese | `double-cheese` | `double_cheese.png` |
| Big Burger | `big-burger` | `big_burger.png` |
| Grill Burger | `grill-burger` | `grill_burger.png` |

### Nos Assiettes (`nos-assiettes`)

| Nom | Clé | Fichier |
|-----|-----|---------|
| Assiette Poulet | `assiette-poulet` | `assiette_poulet.png` |
| Assiette Kefta | `assiette-kefta` | `assiette_kefta.png` |
| Assiette Merguez | `assiette-merguez` | `assiette_merguez.png` |
| Assiette Mixte | `assiette-mixte-3-viandes` | `assiette_mixte.png` |

### Ojja (`ojja`)

| Nom | Clé | Fichier |
|-----|-----|---------|
| Ojja Bœuf | `ojja-boeuf` | `ojja.png` |
| Ojja Poulet | `ojja-poulet` | `ojja.png` |
| Ojja Viande Hachée | `ojja-viande-hachee` | `ojja.png` |
| Ojja Merguez | `ojja-merguez` | `ojja.png` |

### Omelettes (`omelettes`)

| Nom | Clé | Fichier |
|-----|-----|---------|
| Omelette Nature | `omelette-nature` | `omelette.png` |
| Omelette Fromage | `omelette-fromage` | `omelette_fromage.png` |
| Omelette Champignons Fromage | `omelette-champignons-fromage` | `omelette_champignons.png` |

### Nos Salades (`nos-salades`)

| Nom (`menu.php`) | Fichier suggéré |
|------------------|-----------------|
| Salade Chèvre | `salade_chevre.png` |
| Salade Royale | `salade_royale.png` |
| Salade Saumon | `salade_saumon.png` |
| Salade Tunisienne | `salade_tunisienne.png` |

*(Une entrée `salade_cesar.png` existe aussi dans les images catégorie / items selon config.)*

### Chicken & Tenders (`chicken-tenders`)

| Nom | Clé | Fichier |
|-----|-----|---------|
| Chicken Wings (6 pièces) | `chicken-wings-6-pieces` | `chicken_wings.png` |
| Chicken Wings (12 pièces) | `chicken-wings-12-pieces` | `chicken_wings.png` |
| Tenders (6 pièces) | `tenders-6-pieces` | `tenders.png` |
| Tenders (12 pièces) | `tenders-12-pieces` | `tenders.png` |

### Nos Menus Enfants (`nos-menus-enfants`)

| Nom | Slug suggéré | Visuel |
|-----|--------------|--------|
| Menu Cheese Burger (Enfant) | `menu-cheese-burger-enfant` | burger enfant + frites |
| Menu Nuggets (Enfant) | `menu-nuggets-enfant` | nuggets + frites |

### Frites & Accompagnements (`frites-accompagnements`)

| Nom | Clé | Fichier |
|-----|-----|---------|
| Frites Moyenne | `frites-moyenne` | `frites.png` |
| Frites Grande | `frites-grande` | `frites.png` |

### Nos Desserts (`nos-desserts`)

| Nom | Clé | Fichier |
|-----|-----|---------|
| Glace | `glace` | `glace.png` |
| Tarte Daim | `tarte-au-daim` | `tarte_daim.png` |
| Tiramisu | `tiramisu-speculoos` *(clé config)* | `tiramisu.png` |

### Nos Boissons (`nos-boissons`)

| Nom (`menu.php`) | Clé | Fichier |
|------------------|-----|---------|
| Coca-Cola 33cl | `coca-cola-33cl` | `coca_cola.png` |
| Coca-Cola Zero 33cl | `coca-cola-zero-33cl` | `coca_zero.png` |
| Fanta Orange 33cl | `fanta-orange-33cl` | `fanta.png` |
| Sprite 33cl | `sprite-33cl` | `sprite.png` |
| Oasis Tropical 33cl | `oasis-tropical-33cl` | `oasis_tropical.png` |
| Orangina 33cl | `orangina-33cl` | `orangina.png` |
| Eau Plate 50cl | `eau-plate-50cl` | `eau.png` |
| Capri-Sun | `capri-sun` | `capri_sun.png` |

**Également prévu dans `menu_images` :** Oasis Pomme Cassis → `oasis_pomme.png` ; Eau Gazeuse → `eau_gazeuse.png`.

---

## 9. Export base de données

Pour une liste **strictement identique** à la prod (noms modifiés en admin), exporter depuis la base ou l’API `frontend/item` / `frontend/item-category` plutôt que de se fier uniquement à ce fichier.

---

*Généré à partir de `config/menu.php` et `config/menu_images.php` — FoodKing / Le Cayenne.*
