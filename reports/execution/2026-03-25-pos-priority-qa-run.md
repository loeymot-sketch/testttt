# Exécution QA — tests prioritaires POS (2026-03-25)

## Debug appliqué

- **Migration `2026_03_25_004307_add_transaction_id_to_orders_table`** : la table ciblée était `frontend_orders` (inexistante) alors que le modèle `FrontendOrder` utilise `orders`. Correction : migration sur `orders` + garde `Schema::hasTable` / `hasColumn` pour idempotence.

## Application lancée

- `php artisan serve --host=127.0.0.1 --port=8000` (arrière-plan)
- Vérification : `GET http://127.0.0.1:8000/` → **200**
- Front : `npm run dev` (Laravel Mix) — build des assets ; pour recompiler après changements JS : `npm run dev` ou `npm run watch`

## Tests automatisés (PHPUnit)

| Scénario | Statut | Fichier |
|----------|--------|---------|
| Coupon invalide (`coupon_id` inexistant) → **422** + message | OK | `tests/Feature/PosPriorityApiTest.php` |
| Livraison + `address_id` d’un **autre** client → **422** + message | OK | `tests/Feature/PosPriorityApiTest.php` |
| Suite POS admin (création, liste, détail, statut, export, reorder) | OK (8 tests) | `tests/Feature/POSComprehensiveTest.php` |

Commandes :

```bash
php artisan test tests/Feature/PosPriorityApiTest.php
php artisan test tests/Feature/POSComprehensiveTest.php
```

## Multi-agent / E2E navigateur

- **Non exécuté ici** : les scénarios « Tacos XL + menu + … », édition panier, viandes supplémentaires, toast reset panier nécessitent un **navigateur** (Playwright est présent en devDependency mais **aucun `playwright.config` ni specs E2E POS** dans le dépôt).
- Pour un vrai **multi-agent** parallèle : lancer en parallèle (1) PHPUnit POS, (2) build front, (3) Playwright une fois des specs ajoutées sous `tests/e2e/`.

## Checklist manuelle (à faire dans le navigateur)

1. **Commande complexe** : Tacos XL, 2 viandes, menu, grande portion, cheddar, sauce frites → panier, paiement, **KDS** (instruction multi-lignes), **ticket** (pre-line).
2. **Édition panier** : quantité, viandes, boisson, extras → re-soumission sans doublon ni perte.
3. **Coupon invalide** : côté UI, vérifier affichage erreur **422** (aligné avec API).
4. **Livraison + mauvaise adresse** : rejet **422** côté API ; UI doit afficher le message serveur.
5. **Viandes supplémentaires** : boutons `+` actifs quand quota principal plein ; instruction + `item_extras`.
6. **Reset panier** : toast de confirmation.

## Note

`tests/Feature/PosDiscountTest.php` échoue si `Tax::factory()` n’existe pas (factory manquante) — hors périmètre de cette exécution ; les tests ci-dessus utilisent les factories `Branch` / `User` / `Item` / `ItemCategory` déjà présentes.
