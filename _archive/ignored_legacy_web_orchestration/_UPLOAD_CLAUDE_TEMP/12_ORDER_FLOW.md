# Flux de Commande (Order Flow)

Ce document décrit le cycle de vie complet d'une commande FoodKing SaaS, de la création par un client à la remise physique, avec les règles strictes de lecture/écriture.

## Source of Truth
**La base de données MySQL + l'Entity `OrderService`** constituent l'unique Source of Truth (SOT) pour l'état d'un ticket et de son prix. Les appareils locaux (Kiosk, App) ne font qu'émettre des intentions.

---

## 1. Création (PENDING)
- **Qui écrit** : Client (App Web), Client (Kiosk).
- **Qui lit** : Caissier (POS).
- **Action** : Soumission d'une requète `/api/frontend/order`. Le serveur crée `FrontendOrder` et ignore les prix du client.
- **Notification** : Push Firebase vers le POS.

## 2. Paiement (ACCEPT)
- **Qui écrit** : Caissier (POS).
- **Qui lit** : Cuisine (KDS), Client (OSS).
- **Action** : Le caissier valide le règlement (Cash/CB) et bascule à `ACCEPT`.
- **Transitions interdites** : Impossible de passer à `ACCEPT` si le total ne correspond pas ou si le client annule au Kiosk.

## 3. Cuisine (PREPARING)
- **Qui écrit** : Cuisinier (KDS).
- **Qui lit** : Client (OSS), Caissier (POS).
- **Action** : La commande apparait sur l'écran cuisine, le Chef appuie sur "Préparer". 
- **Transitions interdites** : Un Kiosk ne peut pas déclencher cet état. Impossible de revenir à `PENDING`.

## 4. Prêt à Servir (PREPARED)
- **Qui écrit** : Cuisinier (KDS).
- **Qui lit** : Caissier (POS), Client (OSS).
- **Action** : Le plat est sur le comptoir. Le KDS alerte le système (bip sonore + clignotement OSS).

## 5. Livraison (DELIVERED)
- **Qui écrit** : Caissier (POS).
- **Qui lit** : Admin (Dashboard/Analytics).
- **Action** : Remis au client. Le ticket sort du flux de production (Archivage).
- **Transitions interdites** : Action bloquée pour le KDS et le Client. Impossible de revenir à `PREPARING` une fois livré.
