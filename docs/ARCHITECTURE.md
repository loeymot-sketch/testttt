# Architecture FoodKing SaaS

Ce projet suit un modèle MVC monolithique (Laravel) couplé à une SPA Vue 3 pour l'administration. Le code est organisé pour séparer les responsabilités.

## Composants Principaux

### 1. Controllers (Couche Transport HTTP)
Situés dans `app/Http/Controllers`, ils gèrent l'entrée de la requête, l'autorisation, la validation et délèguent le gros du travail aux Services.
* **Frontend** : Gère les appels du client final (App, Kiosk). Exemple `Frontend\OrderController`.
* **Admin** : Gère les appels du back-office et caissiers (POS, KDS). Exemple `Admin\DashboardController`, `Admin\KitchenDisplaySystemController`.

### 2. Services (Couche Logique Métier)
Situés dans `app/Services`, ils contiennent le code métier critique et la persistance.
* **OrderService** : Le service central pour la manipulation de statut et les commandes caisses/tables. Single Source of Truth pour les recalculs.
* **FrontendOrderService** : Spécifiquement conçu pour recevoir les requêtes Kiosk et Web. Recalcule les prix et gère la file d'attente.
* **CouponService** : Moteur de règles pour valider la réduction. Employé au lieu de faire confiance au client.

### 3. Models (Couche Domaine)
Situés dans `app/Models`.
* **Order / FrontendOrder** : Entité de la commande (prix, réductions, etc.)
* **OrderItem** : Article d'une commande (liaison `order_id` et `item_id`).
* **KioskMachine** : Appareil physique lié à une `branch_id`.

### 4. Events / Observers
Le système propage les changements :
* Utilisation massive de **Jobs** (e.g. `SendOrderGotPush`) pour avertir Firebase et les Kiosks sans bloquer le thread de l'API HTTP.

### 5. Frontend (Vue 3)
Dans `resources/js`, l'application administrateur complète (Dashboard, POS, Commandes, Coupons). Buildé par *Laravel Mix* via `webpack.mix.js`.
