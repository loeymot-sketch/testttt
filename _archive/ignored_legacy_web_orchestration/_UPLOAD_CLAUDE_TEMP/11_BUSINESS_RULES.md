# FoodKing : Core Business Rules (Logique Mathématique)

Ce document centralise les **Lois Métier Mathématiques Inflexibles** du projet. Toute modification du code (`Controller`, `Service`, `Frontend`) doit respecter ces principes à la lettre sous peine de corruption financière (`php artisan test` validera ces règles).

---

## ⚖️ 1. Règle du Prix Central (SSOT)

**Principe : Le frontend (Kiosk, App Cliente, POS Web) n'a PAS le droit de définir le prix d'un produit.**

### Fonctionnement :
1. Le client sélectionne un Menu `Item A` à 10.00 €. Le Panier Kiosk affiche 10.00 €.
2. Le Kiosk envoie la requête API `/api/frontend/order` avec `total: 10.00`.
3. **Action Système :** Laravel capte l'ID de `Item A`, interroge la table `items`, récupère la vraie valeur serveur (10.00) et l'additionne au `Subtotal`. Si le backend indique 15.00 €, la commande vaudra 15.00 €. Le `total` du Payload initial est mathématiquement ignoré.

## 🧮 2. Calcul du Grand Total (Addition des frais)

Le `Total` d'une Order est le résultat direct de l'équation suivante gérée par le `FrontendOrderService` :

```text
ORDER_TOTAL = SUBTOTAL(items_db_price * quantity) 
              + DELIVERY_CHARGE (Si order_type == Delivery)
              - DISCOUNT (Si coupon valide)
              + TAXES (Si taxe locale définie)
```

### Détails Taxes (TVA) :
Le système calcule la taxe en **Cascade**.
- Chaque `Item` possède un `tax_id` dans la BDD.
- Le Service boucle sur le panier, regarde la Taxe assignée à l'Item et calcule le montant de taxe additionnel (ex: `Price * Tax_Rate / 100`).
- Il n'y a pas de "Taxe Unique Globale" appliquée sur le `Total`. C'est l'addition des taxes de chaque élément qui forme la "Global Tax" finale de la commande.

## 🎁 3. Les Réductions (Coupons)

**Un coupon n'est jamais poussé brût ("-5€"). Le Frontend donne le Code ("PROMO20") au Backend.**

- **Validation par le Backend** : Le `CouponService` vérifie la validité du Code (Type : `Date`, Type : `Fixed` (ex: 5€), Type : `Percentage` (ex: 20%)).
- **Plafond (Max Discount)** : Même un coupon `-50%` possède fréquemment une clause "Jusqu'à 10€ max" dans la BDD. Le Backend doit borner le rabais calculé.
- L'addition de tous les discounts ne peut **jamais** rendre un `ORDER_TOTAL` négatif. Le prix plancher est `0.00 €`.

## 🔄 4. Transitions d'États Logiques (Order Status)

Une commande vit via un pipeline unidirectionnel (Enum `OrderStatus`).

- `PENDING (5)` ➔ `ACCEPT (10)` ➔ `PREPARING (14)` ➔ `DELIVERED (17)`.
- **Interdit :** Passer de `PENDING` (Non payé par le Front/TPE) directement à `PREPARING` ou `DELIVERED`. Le Backend rejettera (422/400) ou restaurera le statut via ses Observers. Seul un Admin dashboard avec confirmation de paiement peut bypasser ce flow manuellement (Tracé dans `action_logs`).
- **Isolation Branche :** La succursale B ne peut pas valider une commande passée sur la succursale A. Le Backend vérifie que l'user assigné au changement de statut appartient au même `branch_id` que l'`Order`.
