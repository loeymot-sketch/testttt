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
- **[Continuité, vision & passation IDE](docs/PROJECT_CONTINUITY_AND_VISION.md)** — contexte produit (POS, KDS, borne, OSS), état du projet, correctifs majeurs, backlog ; **à lire en priorité** pour une nouvelle session ou un nouvel outil d’IA.
- **[Comptes locaux & parcours POS](docs/LOCAL_TEST_ACCOUNTS.md)** — logins de test (Le Cayenne), branche par défaut, `landing_url`, ordre du parcours jusqu’au menu caisse.
- **[Audit login / identifiants invalides](docs/AUDIT_LOGIN_ACCOUNTS.md)** — pourquoi le message « credentials invalid », décalage `admin@example.com` vs `admin@lecayenne.fr`, commande `php artisan foodking:ensure-admin`.
- [Architecture Générale](docs/ARCHITECTURE.md)
- [Flux de Commande (Order Flow)](docs/ORDER_FLOW.md)
- [Cartographie par Appareil (Device Flow)](docs/DEVICE_FLOW.md)
- [Notes de Sécurité & Falsification](docs/SECURITY_NOTES.md)
- [Cartographie API](docs/API_MAP.md)
- [Plan de Tests](docs/TEST_PLAN.md)

## Développement assisté par IA (Cursor) — règles du projet

### Règles automatiques (projet)
En ouvrant ce dossier comme **racine du workspace** dans Cursor, les règles du dossier **`.cursor/rules/`** sont prises en compte automatiquement (fichiers `.mdc` / `.md`).  
- **`project-continuity.mdc`** : rappel de lire `docs/PROJECT_CONTINUITY_AND_VISION.md` et `AGENTS.md` à chaque session.  
- **`global-operating-principles.md`** : principes généraux (workflow multi-agents, petits changements, docs source de vérité).

### Importer les règles « utilisateur » (tous vos projets Cursor)
Le fichier **`.cursor/rules/global-operating-principles.md`** est pensé pour être aussi une **User Rule** globale :

1. Ouvrir **Cursor** → **Settings** (ou `Cmd+,` / `Ctrl+,`).
2. Aller à **Rules** (ou **Cursor Settings → Rules** selon la version).
3. Dans **User Rules**, coller le contenu de **`.cursor/rules/global-operating-principles.md`** *ou* ajouter une règle qui renvoie explicitement à ce fichier (en le copiant depuis le dépôt après `git pull`).

Ainsi, les mêmes principes s’appliquent même si vous travaillez sur une autre branche ou un autre clone.

### Instructions projet pour les agents
Le fichier **`AGENTS.md`** à la racine décrit la boucle QA / planning / exécution et les responsabilités par rôle (Claude, Kimi, Anti-Gravity). Les rapports vivent sous **`reports/`** et les workflows sous **`workflows/`**.
