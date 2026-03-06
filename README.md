# FoodKing SaaS

Bienvenue sur le dépôt principal de **FoodKing SaaS**, une solution complète de gestion de restaurant, commande en ligne et borne interactive.

## Stack Technique
- **Backend / Core** : Laravel 9 (PHP 8.1+)
- **Frontend Admin/Caisse** : Vue 3 + Vuex + Vue Router
- **Base de données** : MySQL 8+ (Ou SQLite pour les tests auto)
- **Build / Assets** : Laravel Mix (NPM/Node 18+)
- **Temps Réel** : Firebase Cloud Messaging (FCM) + Event Bus

### ⛔ Ce dépôt ne contient pas...
- **Le front-end du Kiosk (Flutter ou natif)**. Le code mobile/tablette du Kiosk n'est pas versionné ici. Ce dépôt ne fournit que l'**API Backend** (Sanctum/REST) servant le Kiosk. Le code Flutter se trouve dans le dossier racine `projet kiosk/`.
- **Les Apps Client & Livreur**. Le code de ces applications se trouve dans le dossier racine `FoodKing/source-code/`.
- Les builds compilés publics (le code est dans ressources/js, à vous de build).

### 🚧 État Actuel
- **Validation locale avant SaaS** : Le projet a été restructuré pour la production. L'accent est mis sur l'isolation backend, la sécurité de l'API par capacités (`kiosk:order`), et les tests QA.

## Installation Rapide

1. **Prérequis** : PHP 8.1+, Composer 2, Node 18, MySQL.
2. **Installation des dépendances** :
   ```bash
   composer install
   npm install
   ```
3. **Configuration** :
   Copiez `.env.example` en `.env`, configurez votre BDD et générez la clé avec `php artisan key:generate`.
4. **Base de données** :
   ```bash
   php artisan migrate --seed
   ```
5. **Compilation Frontend** :
   ```bash
   npm run dev      # Mode développement
   # ou
   npm run prod     # Mode production
   ```

## Documentation Technique Complète
Pour comprendre l'architecture, les flux de commande et la sécurité, consultez le dossier `docs/` :
- [Architecture Générale](docs/ARCHITECTURE.md)
- [Flux de Commande (Order Flow)](docs/ORDER_FLOW.md)
- [Cartographie par Appareil (Device Flow)](docs/DEVICE_FLOW.md)
- [Notes de Sécurité & Falsification](docs/SECURITY_NOTES.md)
- [Cartographie API](docs/API_MAP.md)
- [Plan de Tests](docs/TEST_PLAN.md)
