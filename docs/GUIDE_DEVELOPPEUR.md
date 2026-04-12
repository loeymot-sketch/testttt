# Guide du Développeur FoodKing

> **Document:** Guide de développement et maintenance  
> **Version:** 1.0  
> **Date:** 11 Mars 2026  
> **Public:** Développeurs, Intégrateurs, Support Technique

---

## Table des Matières

1. [Configuration de l'Environnement Local](#1-configuration-de-lenvironnement-local)
2. [Exécution des Tests](#2-exécution-des-tests)
3. [Ajouter un Nouvel Item au Menu](#3-ajouter-un-nouvel-item-au-menu)
4. [Modifier la Logique du Wizard](#4-modifier-la-logique-du-wizard)
5. [Problèmes Courants et Solutions](#5-problèmes-courants-et-solutions)
6. [Techniques de Débogage](#6-techniques-de-débogage)

---

## 1. Configuration de l'Environnement Local

### 1.1 Prérequis Système

| Composant | Version Minimum | Installation |
|-----------|-----------------|--------------|
| PHP | 8.1 | `brew install php@8.1` (Mac) ou `apt-get install php8.1` (Linux) |
| Composer | 2.x | `brew install composer` ou télécharger depuis getcomposer.org |
| Node.js | 18.x | `brew install node@18` ou utiliser nvm |
| MySQL | 8.0 | `brew install mysql@8.0` ou Docker |
| Git | 2.x | `brew install git` |

Extensions PHP requises :
```bash
# Extensions obligatoires
php -m | grep -E "pdo|mbstring|xml|ctype|json|tokenizer|openssl|gd|zip|curl"

# Si manquantes sur Mac
brew install php@8.1-pdo php@8.1-mbstring php@8.1-xml php@8.1-ctype php@8.1-json php@8.1-tokenizer php@8.1-openssl php@8.1-gd php@8.1-zip php@8.1-curl
```

### 1.2 Installation du Projet (Étape par Étape)

```bash
# 1. Cloner le repository
git clone <repository-url> foodking
cd foodking

# 2. Installer les dépendances PHP
composer install

# 3. Installer les dépendances Node
npm install

# 4. Créer le fichier d'environnement
cp .env.example .env

# 5. Générer la clé d'application
php artisan key:generate
```

### 1.3 Configuration de la Base de Données

**Option A: MySQL Local**

```bash
# Éditer .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=foodking_dev
DB_USERNAME=root
DB_PASSWORD=votre_mot_de_passe

# Créer la base de données
mysql -u root -p -e "CREATE DATABASE foodking_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Exécuter migrations et seeders
php artisan migrate --seed
```

**Option B: SQLite (Tests Rapides)**

```bash
# Éditer .env
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

# Créer le fichier SQLite
touch database/database.sqlite

# Exécuter migrations
php artisan migrate --seed
```

### 1.4 Compilation des Assets Frontend

```bash
# Mode développement (avec hot-reload)
npm run dev

# OU avec watch (recompilation automatique)
npm run watch

# Mode production (minifié)
npm run prod
```

### 1.5 Démarrer le Serveur de Développement

```bash
# Serveur PHP intégré
php artisan serve

# Le site est accessible sur http://localhost:8000
# Admin: http://localhost:8000/admin
# Login par défaut: admin / password (après seeding)
```

### 1.6 Vérifier l'Installation

```bash
# Vérifier que tout est OK
php artisan about

# Tester la connexion base de données
php artisan tinker
>>> DB::connection()->getPdo();
# Si pas d'erreur = connexion OK

# Vérifier les routes
php artisan route:list --path=api
```

---

## 2. Exécution des Tests

### 2.1 Configuration des Tests

Les tests utilisent SQLite en mémoire (configuré dans `phpunit.xml`) :

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

### 2.2 Types de Tests

| Type | Commande | Fichiers |
|------|----------|----------|
| **Unit** | `php artisan test --testsuite=Unit` | `tests/Unit/*` |
| **Feature** | `php artisan test --testsuite=Feature` | `tests/Feature/*` |
| **Tous** | `php artisan test` | Tous les tests |
| **Filtre** | `php artisan test --filter=NomDuTest` | Test spécifique |

### 2.3 Commandes Courantes

```bash
# Exécuter tous les tests
php artisan test

# Exécuter avec couverture
php artisan test --coverage

# Tests spécifiques
php artisan test --filter=OrderTest
php artisan test --filter=AuthTest

# Tests avec sortie détaillée
php artisan test --verbose

# Tests en parallèle (plus rapide)
php artisan test --parallel
```

### 2.4 Structure des Tests

```
tests/
├── Feature/                    # Tests d'intégration
│   ├── AdminCrudComprehensiveTest.php
│   ├── AuthComprehensiveTest.php
│   ├── POSComprehensiveTest.php
│   ├── SyncComprehensiveTest.php
│   ├── AntiGravityTest.php
│   └── SecurityComprehensiveTest.php
├── Unit/                       # Tests unitaires
│   └── ExampleTest.php
├── TestCase.php                # Classe base
└── CreatesApplication.php      # Bootstrap
```

### 2.5 Créer un Nouveau Test

```bash
# Générer le fichier
php artisan make:test MonNouveauTest

# Structure minimale
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class MonNouveauTest extends TestCase
{
    public function test_example()
    {
        $response = $this->get('/api/frontend/item');
        $response->assertStatus(200);
    }
}
```

---

## 3. Ajouter un Nouvel Item au Menu

### 3.1 Via l'Interface Admin (Recommandé)

**Étapes :**

1. **Se connecter à l'admin**
   ```
   http://localhost:8000/admin
   Username: admin
   Password: password
   ```

2. **Créer/Modifier une catégorie**
   - Menu: `Items > Categories`
   - Cliquer "Add Category"
   - Remplir: Name, Status (Active)

3. **Créer l'item**
   - Menu: `Items > All Items`
   - Cliquer "Add Item"
   - Remplir les champs:
     | Champ | Exemple | Obligatoire |
     |-------|---------|-------------|
     | Name | Tacos XL | Oui |
     | Category | Nos Tacos | Oui |
     | Price | 9.50 | Oui |
     | Description | ... | Non |
     | Tax | TVA 20% | Oui |
     | Status | Active | Oui |

4. **Configurer les attributs** (pour le wizard)
   - Section "Item Attributes"
   - Ajouter des variations (Viandes, Sauces)

5. **Tester le wizard**
   - Aller au POS (`/admin/pos`)
   - Cliquer sur le nouvel item
   - Vérifier que le wizard s'affiche correctement

### 3.2 Via Database Seeder (Développement)

**Fichier:** `database/seeders/GrillHouseMenuSeeder.php`

```php
// Ajouter une catégorie
$tacosCategory = ItemCategory::create([
    'name' => 'Nouvelle Catégorie',
    'status' => 5, // Active
]);

// Ajouter un item
Item::create([
    'item_category_id' => $tacosCategory->id,
    'name' => 'Mon Nouveau Tacos',
    'slug' => 'mon-nouveau-tacos',
    'price' => 10.50,
    'description' => 'Description du produit',
    'tax_id' => $taxId,
    'status' => 5, // Active
]);
```

**Exécuter:**
```bash
php artisan db:seed --class=GrillHouseMenuSeeder
```

### 3.3 Checklist Après Ajout

- [ ] Item visible dans l'admin (`Items > All Items`)
- [ ] Item visible dans l'API (`GET /api/frontend/item`)
- [ ] Wizard s'affiche correctement au POS
- [ ] Prix correctement calculé avec extras
- [ ] Commande test fonctionne (end-to-end)
- [ ] Ticket imprimé montre les détails
- [ ] KDS affiche la commande correctement

---

## 4. Modifier la Logique du Wizard

### 4.1 Architecture du Wizard

**Fichier principal:** `public/js/pos-wizard.js`

```
┌─────────────────────────────────────────────────────────────┐
│                    WIZARD FLOW                              │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────┐                                            │
│  │ Intercept   │  Intercepter le modal Vue.js               │
│  │ Modal       │                                            │
│  └──────┬──────┘                                            │
│         │                                                   │
│  ┌──────▼──────┐                                            │
│  │ Detect      │  Analyser nom + catégorie                  │
│  │ Category    │  Déterminer: tacos, sandwich, burger...    │
│  └──────┬──────┘                                            │
│         │                                                   │
│  ┌──────▼──────┐                                            │
│  │ Build       │  Construire les étapes dynamiques          │
│  │ Steps       │  1-7 étapes selon catégorie               │
│  └──────┬──────┘                                            │
│         │                                                   │
│  ┌──────▼──────┐                                            │
│  │ Navigation  │  Next/Prev entre les étapes                │
│  │ Steps       │                                            │
│  └──────┬──────┘                                            │
│         │                                                   │
│  ┌──────▼──────┐                                            │
│  │ Calculate   │  Calculer prix avec extras                 │
│  │ Price       │                                            │
│  └──────┬──────┘                                            │
│         │                                                   │
│  ┌──────▼──────┐                                            │
│  │ Submit to   │  Envoyer au panier Vue.js                 │
│  │ Cart        │                                            │
│  └─────────────┘                                            │
└─────────────────────────────────────────────────────────────┘
```

### 4.2 Points de Modification Courants

#### A. Ajouter une Viande

**Fichier:** `public/js/pos-wizard.js` (ligne ~37)

```javascript
var VIANDES = [
    { key: 'merguez', name: 'Merguez', emoji: '🌶️' },
    { key: 'poulet', name: 'Poulet', emoji: '🍗' },
    { key: 'kebab', name: 'Kebab', emoji: '🥩' },
    // AJOUTER ICI:
    { key: 'nouvelle_viande', name: 'Nouvelle Viande', emoji: '🍖' },
];
```

#### B. Modifier le Prix des Sauces Supplémentaires

**Fichier:** `public/js/pos-wizard.js` (ligne ~30)

```javascript
var SAUCE_EXTRA_PRICE = 0.50;  // Modifier cette valeur
```

#### C. Ajouter une Sauce

**Fichier:** `public/js/pos-wizard.js` (ligne ~53)

```javascript
var ALL_SAUCES = [
    { key: 'ketchup', name: 'Ketchup', emoji: '🍅', price: 0 },
    { key: 'mayo', name: 'Mayonnaise', emoji: '🥚', price: 0 },
    // AJOUTER ICI:
    { key: 'nouvelle_sauce', name: 'Nouvelle Sauce', emoji: '🌶️', price: 0 },
];
```

#### D. Modifier les Suppléments (Prix)

**Fichier:** `database/seeders/GrillHouseMenuSeeder.php` (ligne ~76)

```php
$supplements = [
    'Supplément Cheddar' => 1.00,
    'Supplément Jambon' => 1.00,
    // AJOUTER/MODIFIER ICI:
    'Supplément Nouveau' => 1.50,
];
```

Puis réexécuter le seeder ou créer un nouveau seeder.

### 4.3 Déboguer le Wizard

**Activer les logs console:**

Ouvrir DevTools (F12) → Console. Le wizard log automatiquement:

```
[POS-WIZARD] Intercepted item data: {...}
[POS-WIZARD] detectCategory: {domCat: "Nos Tacos", name: "Tacos L"}
[POS-WIZARD] Building steps for: tacos with 2 meats
[POS-WIZARD] Calculated price: 13.50
```

**Tester en console:**

```javascript
// Tester la détection de catégorie
detectCategory('Tacos L (2 Viandes)');
// Retourne: {category: 'tacos', viandeCount: 2}

// Tester le formatage prix
fmtPrice(10.5);
// Retourne: "10,50 €"
```

### 4.4 Structure des Données Envoyées

Le wizard envoie cette structure au panier:

```javascript
{
  "item_id": 123,
  "name": "Tacos L (2 Viandes)",
  "price": 8.50,
  "quantity": 2,
  "item_variations": [
    {"name": "Viande 1", "value": "Poulet"},
    {"name": "Viande 2", "value": "Kebab"},
    {"name": "Sauce", "value": "Algérienne"},
    {"name": "Sauce 2", "value": "Blanche"},
    {"name": "Garniture", "value": "Salade, Tomate, Oignon"}
  ],
  "item_extras": [
    {"name": "Supplément Cheddar", "price": 1.00},
    {"name": "Menu (Frites+Boisson)", "price": 3.00}
  ],
  "instruction": "Sans oignon svp"
}
```

---

## 5. Problèmes Courants et Solutions

### 5.1 Installation et Configuration

| Problème | Symptôme | Solution |
|----------|----------|----------|
| **Extension PHP manquante** | `Class 'PDO' not found` | `brew install php@8.1-pdo` ou activer dans php.ini |
| **Permission storage** | `Unable to write to storage` | `chmod -R 775 storage bootstrap/cache` |
| **Clé APP_KEY manquante** | `No application encryption key` | `php artisan key:generate` |
| **Migration échoue** | `Table already exists` | `php artisan migrate:fresh --seed` (⚠️ perd les données) |

### 5.2 Base de Données

| Problème | Symptôme | Solution |
|----------|----------|----------|
| **Connexion refused** | `Connection refused [127.0.0.1:3306]` | Vérifier que MySQL est démarré: `brew services start mysql` |
| **Accès denied** | `Access denied for user` | Vérifier DB_USERNAME/DB_PASSWORD dans .env |
| **Base inexistante** | `Unknown database` | Créer la base: `mysql -u root -e "CREATE DATABASE foodking_dev"` |
| **Foreign key error** | `Cannot add foreign key` | Vérifier l'ordre des migrations ou utiliser `migrate:fresh` |

### 5.3 Frontend et Assets

| Problème | Symptôme | Solution |
|----------|----------|----------|
| **Mix manifest missing** | `Mix manifest not found` | Exécuter `npm run dev` ou `npm run prod` |
| **Vue.js ne charge pas** | Page blanche, erreurs console | Vérifier `npm run dev` tourne bien |
| **CSS pas appliqué** | Styles manquants | Vider le cache: `php artisan view:clear` |
| **Wizard ne s'affiche pas** | Modal standard apparaît | Vérifier URL contient `/admin/pos`, pas d'erreur JS |

### 5.4 API et Authentification

| Problème | Symptôme | Solution |
|----------|----------|----------|
| **401 Unauthenticated** | `Token invalide` | Vérifier le token dans le header `Authorization: Bearer <token>` |
| **403 Forbidden** | `Not authorized` | Vérifier les permissions/rôles de l'utilisateur |
| **422 Validation** | `The field is required` | Vérifier les données envoyées correspondent aux règles |
| **CORS error** | `Blocked by CORS policy` | Vérifier `APP_URL` et `SANCTUM_STATEFUL_DOMAINS` dans .env |

### 5.5 Commandes et Paiement

| Problème | Symptôme | Solution |
|----------|----------|----------|
| **Prix incorrect** | Total ne correspond pas | Vérifier `detectCategory()` retourne bonne catégorie |
| **Statut ne change pas** | Reste PENDING | Vérifier la transition est autorisée (ORDER_FLOW.md) |
| **Notification non reçue** | KDS ne voit pas la commande | Vérifier Firebase config dans .env |
| **Queue number dupliqué** | Deux commandes #1001 | Normal en parallèle, vérifier `lockForUpdate()` |

---

## 6. Techniques de Débogage

### 6.1 Logs Laravel

**Emplacement:** `storage/logs/laravel.log`

```bash
# Voir les logs en temps réel
tail -f storage/logs/laravel.log

# Filtrer par type
tail -f storage/logs/laravel.log | grep "ERROR"
tail -f storage/logs/laravel.log | grep "Order"

# Voir les dernières erreurs
grep -A 5 "Stack trace" storage/logs/laravel.log | tail -20
```

**Écrire dans les logs:**
```php
// Dans un controller ou service
Log::info('Commande créée', ['order_id' => $orderId, 'total' => $total]);
Log::error('Paiement échoué', ['order_id' => $orderId, 'error' => $error]);
```

### 6.2 Tinker (REPL Laravel)

```bash
php artisan tinker

# Exemples d'utilisation:
>>> $user = User::first();
>>> $user->orders()->count();
>>> Order::where('status', \App\Enums\OrderStatus::PENDING)->get();
>>> Order::find(123)->update(['status' => \App\Enums\OrderStatus::ACCEPT]);
>>> DB::table('orders')->where('id', 123)->dump();
```

### 6.3 Debug Database Queries

```bash
# Activer le log des requêtes
cat >> .env << 'EOF'
DB_DEBUG=true
QUERY_LOG=true
EOF

# Ou dans tinker
>>> DB::enableQueryLog();
>>> Order::find(1);
>>> DB::getQueryLog();
```

### 6.4 Debug Frontend (Vue.js/Wizard)

**Console JavaScript:**
```javascript
// Voir l'état Vuex (store)
__VUE_DEVTOOLS_GLOBAL_HOOK__.Vue.config.devtools = true;

// Voir les items du panier
localStorage.getItem('vuex');

# Voir les données du wizard (si ouvert)
document.querySelector('.wizard-container')

# Tester une fonction du wizard
detectCategory('Tacos XL (3 Viandes)');
detectViandeCount('Tacos XL (3 Viandes)');
```

### 6.5 Debug API avec cURL

```bash
# Tester l'API publique
curl -X GET http://localhost:8000/api/frontend/item \
  -H "Accept: application/json"

# Tester avec authentification
curl -X GET http://localhost:8000/api/admin/pos-order \
  -H "Authorization: Bearer <votre_token>" \
  -H "Accept: application/json"

# Créer une commande (test)
curl -X POST http://localhost:8000/api/frontend/order \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "branch_id": 1,
    "order_type": 5,
    "items": [
      {"item_id": 1, "quantity": 2}
    ]
  }'
```

### 6.6 Outils Recommandés

| Outil | Usage | Installation |
|-------|-------|--------------|
| **Laravel Debugbar** | Debug en dev | `composer require barryvdh/laravel-debugbar --dev` |
| **Telescope** | Monitoring requêtes | Déjà inclus, accès: `/telescope` |
| **Clockwork** | Debug bar alternative | Extension navigateur |
| **Postman/Insomnia** | Test API | Application desktop |
| **TablePlus/Sequel Pro** | GUI Base de données | Application desktop |

### 6.7 Checklist de Débogage Systématique

1. **Vérifier l'environnement**
   ```bash
   php -v  # PHP 8.1+
   php artisan about  # Config OK
   ```

2. **Vérifier la base de données**
   ```bash
   php artisan db:monitor
   php artisan tinker -e "DB::connection()->getPdo();"
   ```

3. **Vérifier les logs**
   ```bash
   tail -20 storage/logs/laravel.log
   ```

4. **Vider les caches**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   php artisan view:clear
   php artisan route:clear
   ```

5. **Recompiler les assets**
   ```bash
   npm run prod
   ```

6. **Vérifier les permissions**
   ```bash
   chmod -R 775 storage bootstrap/cache
   chown -R www-data:www-data storage  # Sur Linux
   ```

---

## Annexes

### A. Commandes Artisan Utiles

```bash
# Informations
php artisan about
php artisan route:list
php artisan route:list --path=api/admin

# Cache
php artisan cache:clear
php artisan config:clear
php artisan config:cache  # En production
php artisan view:clear

# Base de données
php artisan migrate:status
php artisan migrate:fresh --seed
php artisan db:seed --class=GrillHouseMenuSeeder

# Maintenance
php artisan down  # Mode maintenance
php artisan up    # Sortir du mode maintenance
php artisan tinker

# Tests
php artisan test
php artisan test --filter=NomTest
```

### B. Structure des Fichiers Importants

| Fichier | Description |
|---------|-------------|
| `app/Services/OrderService.php` | Logique commandes caisse |
| `app/Services/FrontendOrderService.php` | Logique commandes Kiosk |
| `public/js/pos-wizard.js` | Wizard personnalisation |
| `database/seeders/GrillHouseMenuSeeder.php` | Données menu initiales |
| `config/menu.php` | Configuration menu |

---

**Guide complet du développeur FoodKing.**

*Pour toute question technique, consulter l'équipe Architecture ou référencer les documents dans `docs/`.*
