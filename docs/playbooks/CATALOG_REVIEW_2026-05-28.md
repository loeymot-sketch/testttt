# Revue catalogue Le Cayenne — à valider avant ouverture

Source : `config/menu.php` (SSOT). Cette feuille = ce qui sera créé en base après `php artisan menu:reset --force`. **Le menu est commun borne + caisse pour V1** ; la séparation par surface (kiosk-only / pos-only, labels distincts, tri par surface) est V1.5 (colonnes `channels`, `kiosk_label`, `kiosk_sort`, `pos_sort` déjà en place mais non utilisées par les contrôleurs aujourd'hui).

Procédure : imprime, annote (✓ OK, ✗ retirer, prix corrigé), renvoie. Le `menu:reset` n'est lancé qu'après validation.

## Identité

- Restaurant : **Le Cayenne**
- Locale : `fr` — Devise : `EUR (€)` — TZ : `Europe/Paris`
- TVA appliquée : **10 % (emporter)** sur tous les items — pas d'alcool V1
- `default_tax_id` : **3** (VAT-10%) ← corrigé dans cette PR (était 1 = No-VAT)

## 1. Nos Tacos (4 items)

| Item | Prix | Viandes au choix | Sauce | Crudités |
|---|---|---|---|---|
| Tacos M (1 Viande) | 6,50 € | 1 | ✓ | ✓ |
| Tacos L (2 Viandes) | 8,50 € | 2 | ✓ | ✓ |
| Tacos XL (3 Viandes) | 10,50 € | 3 | ✓ | ✓ |
| Tacos XXL (4 Viandes) | 12,50 € | 4 | ✓ | ✓ |

## 2. Nos Sandwichs (8 items)

| Item | Prix | Composition |
|---|---|---|
| Le Méga | 8,00 € | 2 viandes + Cheddar + Œuf |
| Le Terminator | 9,00 € | 2 viandes + 2 Cheddar + Œuf + Jambon de dinde |
| Le Suprême | 7,00 € | Steak + Cordon Bleu + Cheddar |
| Le Cayenne | 7,00 € | Viande hachée OU chicken + mozzarella + cheddar + crème fraîche |
| Sandwich Froid | 4,50 € | Sandwich au thon |
| Panini | 5,00 € | Thon / Jambon / Viande hachée / Fromage de chèvre / Saumon / Escalope |
| Sandwich Classique (Pain) | 6,50 € | 1 viande au choix dans un pain classique |
| Sandwich Classique (Galette) | 6,50 € | 1 viande au choix dans une galette |

## 3. Nos Burgers (6 items)

| Item | Prix | Composition |
|---|---|---|
| Chicken Burger | 6,00 € | Poulet pané + Cheddar |
| Cheese Burger | 6,00 € | 1 Steak + 1 Cheddar |
| Fish Burger | 6,00 € | Poisson pané + Cheddar |
| Double Cheese | 7,00 € | 2 Steaks + 2 Cheddars |
| Grill Burger | 8,00 € | 2 Steaks + 2 Cheddars + Jambon de dinde |
| Big Burger | 9,00 € | 3 Steaks + 3 Cheddars + 2 Jambon de dinde |

## 4. Nos Assiettes (4 items)

| Item | Prix |
|---|---|
| Assiette Poulet (Nature / Curry / Paprika) + Frites + Salade + Pain + Sauce | 12,50 € |
| Assiette Kefta + Frites + Salade + Pain + Sauce | 12,50 € |
| Assiette Merguez + Frites + Salade + Pain + Sauce | 12,50 € |
| Assiette Mixte (Poulet + Kefta + Merguez) + Frites + Salade + Pain + Sauce | 14,50 € |

## 5. Ojja (4 items)

| Item | Prix |
|---|---|
| Ojja Bœuf + Frites + Pain | 13,50 € |
| Ojja Poulet + Frites + Pain | 13,50 € |
| Ojja Viande Hachée + Frites + Pain | 13,50 € |
| Ojja Merguez + Frites + Pain | 13,50 € |

## 6. Omelettes (3 items)

| Item | Prix |
|---|---|
| Omelette Nature + Frites + Pain | 7,50 € |
| Omelette Fromage + Frites + Pain | 8,50 € |
| Omelette Champignons Fromage + Frites + Pain | 9,50 € |

## 7. Nos Salades (4 items)

| Item | Prix | Composition |
|---|---|---|
| Salade Chèvre | 7,50 € | Laitue · Tomate · Chèvre · Croûtons · Vinaigrette · Maïs |
| Salade Royale | 7,50 € | Laitue · Tomate · Maïs · Poulet · Olives |
| Salade Saumon | 7,50 € | Laitue · Tomate · Maïs · Saumon · Olives |
| Salade Tunisienne | 7,50 € | Concombre · Tomate · Oignon · Poivrons · Thon · Olives · Huile d'olive |

## 8. Chicken & Tenders (4 items)

| Item | Prix |
|---|---|
| Chicken Wings (6 pièces) | 6,00 € |
| Chicken Wings (12 pièces) | 10,50 € |
| Tenders (6 pièces) | 7,50 € |
| Tenders (12 pièces) | 13,50 € |

## 9. Nos Menus Enfants (2 items)

| Item | Prix | Composition |
|---|---|---|
| Menu Cheese Burger (Enfant) | 6,00 € | 1 steak + 1 cheddar + frites + Capri-Sun |
| Menu Nuggets (Enfant) | 6,00 € | 6 Nuggets de poulet + frites + Capri-Sun |

## 10. Frites & Accompagnements (2 items)

| Item | Prix |
|---|---|
| Frites Moyenne | 2,50 € |
| Frites Grande | 4,00 € |

## 11. Nos Desserts (3 items)

| Item | Prix |
|---|---|
| Glace | 3,80 € |
| Tarte Daim | 3,80 € |
| Tiramisu | 3,80 € |

## 12. Nos Boissons (8 items, aucune alcoolisée)

| Item | Prix |
|---|---|
| Coca-Cola 33cl | 1,50 € |
| Coca-Cola Zero 33cl | 1,50 € |
| Fanta Orange 33cl | 1,50 € |
| Sprite 33cl | 1,50 € |
| Oasis Tropical 33cl | 1,50 € |
| Orangina 33cl | 1,50 € |
| Eau Plate 50cl | 1,00 € |
| Capri-Sun 20cl | 1,50 € |

## 13. Suppléments (commandables séparément au POS, 8 items)

| Item | Prix |
|---|---|
| Sauce supplémentaire | 0,50 € |
| Fromage supplémentaire | 1,00 € |
| Jambon de dinde | 1,00 € |
| Boursin | 1,00 € |
| Fromage à raclette | 1,00 € |
| Œuf | 1,00 € |
| Galette pommes de terre | 1,00 € |
| Salade verte | 2,00 € |

## Options transverses

### Viandes (9, sélectionnables sur Tacos / Sandwichs / Panini)
Merguez · Kefta · Mexicain · Cordon Bleu · Viande Hachée · Nuggets · Escalope de poulet · Tenders · Fricandelle

### Sauces canoniques (15, sélectionnables sur Tacos / Sandwichs / Burgers / Assiettes / Wings / Tenders)
Ketchup · Mayonnaise · Algérienne · Curry · Andalouse · Burger · Samouraï · Barbecue · Cocktail · Américaine · Hannibal · Harissa · Blanche · Poivre · Sans Sauce

### Crudités atomiques (3, toggle vert/rouge sur Tacos / Sandwichs / Burgers)
Salade · Tomate · Oignon

### Suppléments inline pendant wizard (apparaissent au moment de composer un item)
Jambon de dinde · Boursin · Fromage à raclette · Œuf · Fromage · Galette pommes de terre → tous à 1,00 €
Sauce supplémentaire → 0,50 €

### Addons (upsell affiché en bout de wizard)
Menu (Frites + Boisson) · Frites Seules · Boisson Seule → 2,00 € / 3,00 €

## Total

- **14 catégories actives**
- **~ 60 items principaux** (sans compter les 8 suppléments)
- **9 viandes × 15 sauces × 3 crudités** = combinaisons multiples par item paramétrable

## À renvoyer

Pour chaque ligne du tableau, mettre :
- `OK` si à conserver tel quel
- `RETIRER` si l'item ne doit pas apparaître au lancement
- `prix XX,XX €` si correction de prix
- Notes libres si refonte (description, photo manquante, etc.)

Le re-seed `php artisan menu:reset --force` n'est lancé qu'après réception de ce document annoté.
