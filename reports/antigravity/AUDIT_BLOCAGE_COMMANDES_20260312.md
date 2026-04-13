# AUDIT BLOCAGE COMMANDES — Playwright / E2E QA & Terminal
## Pourquoi `cat .env` et `php artisan db:seed` restent bloqués

**Date :** 12 Mars 2026  
**Contexte :** l’exécuteur Playwright / E2E QA bloque systématiquement sur ces commandes — 10+ tentatives échouées.

---

## 1. DIAGNOSTIC ROOT CAUSE

### 1.1 `cat .env | grep APP_ENV` — Blocage

| Cause possible | Probabilité | Explication |
|----------------|-------------|-------------|
| **.env dans .gitignore** | Haute | Le fichier `.env` est listé dans `.gitignore`. Certains environnements d'agents (Cursor sandbox, Gemini ou autres sandboxes QA) peuvent **restreindre l'accès aux fichiers ignorés** pour des raisons de sécurité. La commande `cat` tente de lire un fichier "non accessible" → blocage ou timeout. |
| **.env absent** | Moyenne | Si le projet n'a jamais été configuré (`cp .env.example .env`), le fichier n'existe pas. `cat .env` renverrait une erreur immédiate — sauf si le shell ou l'agent attend une entrée. |
| **Permissions fichier** | Faible | Sur certains systèmes, `.env` peut avoir des permissions restrictives (chmod 600). |

**Conclusion :** L'environnement d'exécution des agents (sandbox) **bloque probablement l'accès à `.env`** car ce fichier contient des secrets (DB credentials, API keys) et est ignoré par git.

---

### 1.2 `php artisan db:seed --class=MenuSeeder` — Blocage

| Cause possible | Probabilité | Explication |
|----------------|-------------|-------------|
| **Connexion MySQL bloquée** | Très haute | Le MenuSeeder exécute **dès la ligne 255** (`ItemCategory::count()`) une requête DB. Laravel utilise par défaut **MySQL** (`.env` → `DB_CONNECTION=mysql`). Dans un sandbox : (a) MySQL peut ne pas être accessible (réseau restreint), (b) `127.0.0.1:3306` peut être bloqué, (c) la connexion **attend un timeout** (30–60 s ou plus) → la commande semble "bloquée". |
| **MySQL non démarré** | Haute | Si MySQL n'est pas lancé sur la machine, la connexion attend indéfiniment. |
| **.env manquant ou invalide** | Moyenne | Sans `.env`, Laravel utilise les valeurs par défaut (`DB_HOST=127.0.0.1`, etc.). La connexion tente quand même MySQL. |
| **MenuSeeder MySQL-only** | Confirmée | Le `purgeExistingData()` utilise `SET FOREIGN_KEY_CHECKS=0/1` et `truncate()` — **spécifique MySQL**. Même avec SQLite configuré, le seeder planterait. |

**Conclusion :** Le sandbox **bloque les connexions réseau/socket** (MySQL). La commande attend une connexion qui n'aboutit jamais → blocage apparent.

---

## 2. PREUVES DANS LE CODE

### 2.1 MenuSeeder — Première requête DB

```php
// MenuSeeder.php, ligne 255-256 (menuExists())
$existingCategories = ItemCategory::count();  // ← PREMIÈRE REQUÊTE
$existingItems = Item::count();
```

Dès le début du `run()`, après les pre-flight checks (config uniquement), le seeder interroge la base. **Si la connexion MySQL échoue ou timeout → blocage.**

### 2.2 MenuSeeder — Code MySQL-only

```php
// MenuSeeder.php, purgeExistingData()
DB::statement('SET FOREIGN_KEY_CHECKS=0;');   // MySQL only
DB::table('item_addons')->truncate();         // truncate = MySQL
// ...
DB::statement('SET FOREIGN_KEY_CHECKS=1;');   // MySQL only
```

SQLite ne supporte pas `SET FOREIGN_KEY_CHECKS`. Une migration existante (`2026_03_11_000000_reset_menu_french.php`) gère déjà SQLite avec `PRAGMA foreign_keys` et `delete()` — le MenuSeeder doit être aligné.

### 2.3 phpunit.xml — SQLite pour les tests

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Les tests PHPUnit utilisent **SQLite en mémoire**. Aucune connexion MySQL. Les commandes `php artisan test` fonctionnent dans le sandbox car elles n'ont pas besoin de MySQL.

---

## 3. SOLUTIONS

### 3.1 Pour l'utilisateur (exécution manuelle)

**Ces commandes doivent être exécutées dans un terminal local, hors sandbox :**

```bash
# 1. Vérifier .env
cat .env | grep APP_ENV
# ou
grep APP_ENV .env

# 2. Vérifier que MySQL tourne
mysql -u root -e "SELECT 1"

# 3. Lancer le seeder
php artisan db:seed --class=MenuSeeder
```

### 3.2 Pour Playwright / E2E QA et agents (sandbox)

**Ne pas exécuter** `cat .env` ou `php artisan db:seed` dans le sandbox. À la place :

1. **Utiliser le test automatisé** (créé dans ce correctif) :
   ```bash
   php artisan test --filter=MenuSeederTest
   ```
   Ce test utilise SQLite en mémoire (phpunit.xml) → pas de MySQL, pas de .env DB.

2. **Lire .env.example** pour la structure (pas de secrets) :
   ```bash
   cat .env.example | grep APP_ENV
   ```

### 3.3 Rendre MenuSeeder compatible SQLite

Le MenuSeeder doit gérer SQLite comme la migration `2026_03_11_000000_reset_menu_french.php` pour permettre les tests automatisés.

---

## 4. RÉSUMÉ

| Commande | Pourquoi ça bloque | Solution |
|----------|--------------------|----------|
| `cat .env \| grep APP_ENV` | Accès à .env restreint dans le sandbox (fichier ignoré) | Exécuter manuellement, ou utiliser `cat .env.example` |
| `php artisan db:seed --class=MenuSeeder` | Connexion MySQL bloquée ou timeout dans le sandbox | Exécuter manuellement, ou utiliser `php artisan test --filter=MenuSeederTest` (SQLite) |

---

## 5. ACTIONS CORRECTIVES APPLIQUÉES

1. **MenuSeeder** : Ajout de la compatibilité SQLite dans `purgeExistingData()` (PRAGMA, delete au lieu de truncate).
2. **MenuSeederTest** : Nouveau test Feature qui exécute le seeder avec SQLite en mémoire — **3 tests PASS**.
3. **Documentation** : Ce rapport.

---

## 6. PROCÉDURE POUR ANTI-GRAVITY (Agents en sandbox)

### ❌ NE PAS exécuter (bloquent en sandbox)
```bash
cat .env | grep APP_ENV          # Accès .env restreint
php artisan db:seed --class=MenuSeeder   # Connexion MySQL bloquée
```

### ✅ À la place
```bash
# Valider le MenuSeeder (SQLite, pas de MySQL)
php artisan test --filter=MenuSeederTest

# Vérifier la structure .env (sans secrets)
cat .env.example | grep APP_ENV
```

### Exécution manuelle (par le développeur)
```bash
# Dans un terminal local (hors sandbox)
grep APP_ENV .env
php artisan db:seed --class=MenuSeeder
```

---

**Fin de l'audit — 12 Mars 2026**
