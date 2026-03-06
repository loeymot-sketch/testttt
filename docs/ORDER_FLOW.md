# Flux de Commande (Order Flow)

Ce document décrit le cycle de vie complet d'une commande FoodKing SaaS, de la création par un client à la remise physique.

## 1. Création (PENDING)
- **Point d'entrée** : Client sur le Frontend Web, App Mobile, ou Kiosk.
- **Route** : `POST /api/v1/frontend/order` (ou `tableOrderStore` pour les tables).
- **Sécurité** : 
  - Le frontend envoie un JSON avec les identifiants d'items. 
  - Le `FrontendOrderService` et `OrderService` ignorent les prix du JSON et les **recalculent** en fonction de la BDD (`Item`, `ItemVariation`, `ItemExtra`).
- **Validation** : Création d'un enregistrement `FrontendOrder` en statut `PENDING`. Si une `kiosk_machine` est connectée, elle attache son `branch_id`.

## 2. Notification (Events)
- Des jobs asynchrones (`SendOrderMail`, `SendOrderGotPush`) sont dispatchés.
- Firebase alerte le **POS (Caisse)** qu'une commande est en attente.

## 3. Paiement (ACCEPT)
- **Point d'entrée** : Le caissier sur `Admin/OnlineOrder` ou `Admin/POS`.
- **Validation** : Le caissier encaisse le paiement (Cash/CB) et change le statut à `ACCEPT`.
- **Action** : La commande passe dans la file de la cuisine.

## 4. Cuisine (PREPARING)
- **Point d'entrée** : L'écran en cuisine (**KDS** - Kitchen Display System).
- **Action** : Le cuisinier filtre par sa succursale (`branch_id`) et marque la commande comme `PREPARING`. 
- **Notification** : Le client voit son ticket clignoter sur le `StatusScreen`.

## 5. Prêt à Servir (PREPARED)
- **Action** : Le KDS bascule à `PREPARED`. Notification push et sonore au Frontend client (le Kiosk bipe).

## 6. Livraison (DELIVERED)
- **Dernière étape** : Le caissier marque la commande comme terminée. Les données alimentent le `DashboardController` pour le Dashboard Boss (statistiques).

## Diagramme d'État
`PENDING` -> `ACCEPT` -> `PREPARING` -> `PREPARED` -> `DELIVERED`
(Ou `CANCELED` à tout moment par erreur)
