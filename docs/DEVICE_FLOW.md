# Cartographie par Appareil (Device Flow)

L'écosystème **FoodKing SaaS** s'articule autour de plusieurs appareils (écrans physiques).

## 1. Kiosk (Borne Interactive)
- **Utilisateur** : Machine autonome authentifiée via `KioskMachineController`.
- **Mécanique** :
  - L'admin crée une machine dans le dashboard.
  - La borne se loggue (`username`/`password`) pour générer un Token Sanctum.
  - La borne reste connectée tant qu'elle est autorisée (`is_login`, `status`).
  - Elle n'accède qu'à l'API `/api/frontend/` (Lecture seule du Store, Écriture pour créer Orders) et possède l'ability stricte `['kiosk:order']`.

## 2. POS (Caisse / Web Backend)
- **Utilisateur** : Caissier ou Manager (Authentification Admin standard).
- **Mécanique** :
  - Gère les flux globaux : crée des `PosOrder`, gère l'état `PENDING` -> `ACCEPT`.
  - Reçoit des bips Firebase lorsqu'une borne Kiosk passe une commande.

## 3. KDS (Kitchen Display System)
- **Utilisateur** : Cuisinier.
- **Mécanique** :
  - Écran passif-actif situé en cuisine. Se connecte avec des droits d'admin.
  - Ne voit QUE les commandes de sa succursale (`branch_id`).
  - Transition de `ACCEPT` -> `PREPARING` -> `PREPARED`.

## 4. Status Screen (Écran Client File d'attente)
- **Utilisateur** : Clients (Public).
- **Mécanique** :
  - Lit les informations via Websockets / Firebase (Event Bus).
  - Affiche les numéros de tickets de `queue_number` en temps réel.
