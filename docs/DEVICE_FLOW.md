# Cartographie par Appareil (Device Flow)

L'écosystème FoodKing s'articule autour de multiples appareils physiques ou virtuels. La cohérence entre eux est critique pour le Restaurant.

## 1. Kiosk (Borne Interactive)
- **Acteur** : Client du restaurant (Non-loggué, mais utilise une machine reconnue).
- **Source of Truth Locale** : Panier temporaire en mémoire Vue/Flutter.
- **Écriture** : Envoie un Payload `OrderRequest` via `POST /api/frontend/order`.
- **Lecture** : Récupère la carte via `/api/frontend/item`.
- **Limites** : Ne peut modifier aucune commande une fois soumise.

## 2. POS (Caisse / Web Backend)
- **Acteur** : Caissier (Humain, Authentifié `Manager`).
- **Source of Truth** : Backend direct.
- **Écriture** : Force les statuts, crée des commandes manuellement, applique des remises (`TableOrder`, `PosOrder`).
- **Lecture** : Écoute les WebSockets Firebase pour les entrées Kiosk.
- **Limites** : Limité à sa propre succursale (`branch_id`) sauf si compte `Admin`.

## 3. KDS (Kitchen Display System)
- **Acteur** : Brigade de Cuisine (Authentifié `Chef`).
- **Écriture** : Met à jour `OrderStatus` de `ACCEPT` -> `PREPARING` -> `PREPARED`.
- **Lecture** : Liste en temps réel des commandes envoyées par la caisse.
- **Flux Interdit** : Un Chef ne peut pas ajouter un produit ou éditer un prix sur le ticket.

## 4. Status Screen (Écran Client File d'attente / OSS)
- **Acteur** : Passif (Écran public au-dessus du comptoir).
- **Écriture** : AUCUNE. (Lecture Seule stricte).
- **Lecture** : Connecté via Pusher/Websockets, reçoit les `queue_number` en `PREPARING` et `PREPARED`.
